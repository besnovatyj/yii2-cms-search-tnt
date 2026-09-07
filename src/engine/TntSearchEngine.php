<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchTnt\engine;

use Besnovatyj\Search\contracts\EngineCapabilities;
use Besnovatyj\Search\contracts\IndexableDocument;
use Besnovatyj\Search\contracts\SearchEngineInterface;
use Besnovatyj\Search\contracts\SearchHit;
use Besnovatyj\Search\contracts\SearchQuery;
use Besnovatyj\Search\contracts\SearchResult;
use Besnovatyj\Search\settings\SearchSettings;
use Besnovatyj\SearchTnt\settings\TntSettings;
use RuntimeException;
use TeamTNT\TNTSearch\Indexer\TNTIndexer;
use TeamTNT\TNTSearch\TNTSearch;
use Throwable;
use Yii;
use yii\helpers\Html;

/**
 * Ядро сквозного поиска на TNTSearch с хранением индекса в MySQL проекта.
 *
 * Почему именно оно как ядро по умолчанию: ничего не нужно ставить на сервер — ни демона, ни
 * ansible-роли, ни отдельного порта, а качество поиска при этом взрослое: BM25, стемминг русского,
 * нечёткое совпадение с многобайтным расстоянием Левенштейна (в отличие от штатного `levenshtein()`
 * в PHP, который считает байты и на кириллице врёт вдвое).
 *
 * Граница применимости. TNTSearch — чисто текстовый движок: атрибутов он не хранит и фильтровать
 * выдачу не умеет. Поэтому ядро забирает у него совпадения одним списком (не более
 * {@see TntSettings::$maxMatches}), после чего фильтрует по разделам и режет на страницы само,
 * опираясь на собственную карту {@see DocumentTypeMap}. Для сайта в тысячи документов это честно и незаметно;
 * когда типичный запрос начнёт упираться в потолок совпадений, пора переходить на ядро Manticore —
 * контракт фасада, провайдеры модулей и вёрстка выдачи при этом не меняются.
 *
 * Морфология. Snowball-стеммер отсекает окончания, поэтому «ботинки» находят «ботинок». Смены
 * основы («люди» → «человек», «шла» → «идти») он не делает — это умеет лемматизатор, которого
 * здесь нет; см. {@see EngineCapabilities::$lemmatization}.
 *
 * Веса. Полевого взвешивания у TNTSearch нет, поэтому важность выражается частотой: заголовок и
 * ключевые слова повторяются в индексируемой строке. Приём грубый, но работает на самой механике
 * BM25 и не требует лезть во внутренности библиотеки.
 */
final class TntSearchEngine implements SearchEngineInterface
{
    /** Префикс имён таблиц индекса; полное имя — префикс + слот. */
    private const string INDEX_PREFIX = 'bescms_search_';

    /** Таблицы, которые TNTSearch создаёт на каждый индекс. */
    private const array INDEX_TABLES = ['wordlist', 'doclist', 'fields', 'hitlist', 'info'];

    /** Во сколько раз заголовок весомее тела текста (реализуется повтором). */
    private const int TITLE_WEIGHT = 3;

    /** Во сколько раз ключевые слова весомее тела текста. */
    private const int KEYWORDS_WEIGHT = 2;

    /** Сколько соответствий «документ → раздел» накапливается до записи в базу. */
    private const int MAP_BATCH = 200;

    private ?TNTSearch $reader = null;

    private ?TNTIndexer $indexer = null;

    private ?string $buildSlot = null;

    /** @var array<int, string> накопленные соответствия «id документа => раздел» */
    private array $pendingTypes = [];

    /** Проверялась ли готовность ядра в этом запросе. */
    private bool $availabilityChecked = false;

    /** Причина неготовности; null — ядро готово (значимо только после проверки). */
    private ?string $unavailableReason = null;

    public function __construct(
        private readonly TntConfigFactory $config,
        private readonly DocumentTypeMap $map,
        private readonly TntSettings $engineSettings,
        private readonly SearchSettings $settings,
    ) {
    }

    public function capabilities(): EngineCapabilities
    {
        return new EngineCapabilities(
            fuzzy: true,
            lemmatization: false,
            highlight: true,
            suggestion: false,
            facets: true,
            sortByDate: false,
            incremental: true,
            comfortableSize: 20000,
        );
    }

    /**
     * Ядро готово, если таблицы рабочего слота существуют, то есть индекс хоть раз собирали.
     *
     * Наличие самой библиотеки не проверяется: она объявлена жёсткой зависимостью пакета, и без
     * неё не загрузился бы и класс модуля.
     */
    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Почему ядро не готово: индекса ещё нет или база недоступна.
     *
     * Различать нужно по той же причине, что и у ядра с демоном: «индекс не собран» — это штатное
     * состояние сразу после установки, лечится кнопкой пересборки, а ошибка базы — авария.
     * Проверка выполняется один раз за запрос.
     */
    public function unavailableReason(): ?string
    {
        if ($this->availabilityChecked) {
            return $this->unavailableReason;
        }

        $this->availabilityChecked = true;

        try {
            // Имя без плейсхолдера `{{%…}}`: таблицы индекса создаёт сама библиотека своим
            // подключением, префикс таблиц Yii к ним не применяется.
            $table = self::INDEX_PREFIX . $this->map->activeSlot() . '_wordlist';

            $this->unavailableReason = Yii::$app->db->getTableSchema($table, true) !== null
                ? null
                : 'Индекс ещё ни разу не собирали этим ядром: таблиц рабочего слота нет.';
        } catch (Throwable $e) {
            $this->unavailableReason = 'Ошибка базы данных: ' . $e->getMessage();

            Yii::warning('TNTSearch недоступен: ' . $e->getMessage(), 'search/tnt');
        }

        return $this->unavailableReason;
    }

    public function query(SearchQuery $searchQuery): SearchResult
    {
        $tnt = $this->reader();
        $tnt->fuzziness($this->settings->fuzzy);

        $found = $tnt->search($searchQuery->text, $this->engineSettings->maxMatches);

        /** @var list<int> $ids */
        $ids = array_map('intval', $found['ids'] ?? []);

        if ($ids === []) {
            return SearchResult::empty();
        }

        $scores = $this->normalizeScores($found['docScores'] ?? []);
        $types = $this->map->typesOf($ids);
        $facets = $this->countByType($ids, $types);

        if ($searchQuery->types !== []) {
            $allowed = array_flip($searchQuery->types);
            $ids = array_values(array_filter(
                $ids,
                static fn (int $id): bool => isset($allowed[$types[$id] ?? '']),
            ));
        }

        $total = count($ids);
        $window = array_slice($ids, $searchQuery->offset, $searchQuery->limit);

        $hits = [];
        foreach ($window as $id) {
            $hits[] = new SearchHit($id, (float)($scores[$id] ?? 0.0));
        }

        return new SearchResult(
            hits: $hits,
            total: $total,
            facets: $searchQuery->withFacets ? $facets : [],
        );
    }

    /**
     * Подсветка совпадений.
     *
     * Текст экранируется ДО подсветки, поэтому наружу уходит безопасный HTML: содержимое
     * документа не может внести разметку, добавляются только теги `<mark>`. Морфология здесь
     * та же, что при поиске — подсветку делает сам движок по своим токенам, поэтому по запросу
     * «ботинок» подсвечивается и «ботинках».
     */
    public function highlight(string $text, string $query): string
    {
        if ($text === '' || $query === '') {
            return Html::encode($text);
        }

        try {
            return $this->reader()->highlight(Html::encode($text), $query, 'mark', ['wholeWord' => false]);
        } catch (Throwable $e) {
            Yii::warning('Не удалось подсветить фрагмент: ' . $e->getMessage(), 'search/tnt');

            return Html::encode($text);
        }
    }

    public function beginRebuild(): void
    {
        $slot = $this->map->buildSlot();
        $this->buildSlot = $slot;
        $this->pendingTypes = [];

        // Слот мог остаться заполненным после прерванной сборки — начинаем с чистого места.
        $this->dropIndexTables($slot);
        $this->map->clear($slot);

        $name = $this->indexName($slot);

        $tnt = new TNTSearch();
        $tnt->loadConfig($this->config->create());

        // createIndex() лишь заводит таблицы и возвращает ДВИЖОК, а не индексатор; сам индексатор
        // отдаёт getIndex(), и только после selectIndex() — тот подтягивает стеммер и токенизатор
        // выбранного индекса. Порядок вызовов здесь существенный, менять его нельзя.
        $tnt->createIndex($name, true);
        $tnt->selectIndex($name);

        $indexer = $tnt->getIndex();
        $indexer->setPrimaryKey('id');
        $indexer->disableOutput(true);

        // Стеммер ставим явно тем же классом, что и при поиске: собранный другим стеммером индекс
        // молча перестал бы отвечать на запросы — совпадений просто не находилось бы.
        $stemmerClass = $this->engineSettings->stemmerClass;
        $indexer->setStemmer(new $stemmerClass());

        $this->indexer = $indexer;
    }

    /**
     * @param iterable<IndexableDocument> $documents
     */
    public function addDocuments(iterable $documents): void
    {
        if ($this->indexer === null || $this->buildSlot === null) {
            throw new RuntimeException('Сборка индекса не начата: вызовите beginRebuild().');
        }

        foreach ($documents as $document) {
            $this->indexer->insert([
                'id' => $document->documentId,
                'content' => $this->composeText($document),
            ]);

            $this->pendingTypes[$document->documentId] = $document->type;

            if (count($this->pendingTypes) >= self::MAP_BATCH) {
                $this->flushTypes();
            }
        }

        $this->flushTypes();
    }

    public function commitRebuild(): void
    {
        if ($this->buildSlot === null) {
            throw new RuntimeException('Сборка индекса не начата: вызовите beginRebuild().');
        }

        $this->flushTypes();

        $previous = $this->map->activeSlot();
        $newSlot = $this->buildSlot;

        // Момент подмены: одна строка состояния — и поиск идёт по новому индексу.
        $this->map->activate($newSlot);

        if ($previous !== $newSlot) {
            $this->dropIndexTables($previous);
            $this->map->clear($previous);
        }

        $this->resetBuild();

        // Индекс только что появился (или сменил слот) — проверка готовности, сделанная до этого
        // в том же процессе, больше не действительна.
        $this->availabilityChecked = false;
    }

    public function cancelRebuild(): void
    {
        if ($this->buildSlot === null) {
            return;
        }

        $this->dropIndexTables($this->buildSlot);
        $this->map->clear($this->buildSlot);
        $this->resetBuild();
    }

    public function indexDocument(IndexableDocument $document): void
    {
        $slot = $this->map->activeSlot();

        $tnt = new TNTSearch();
        $tnt->loadConfig($this->config->create());
        $tnt->selectIndex($this->indexName($slot));

        $indexer = $tnt->getIndex();
        $indexer->setPrimaryKey('id');
        $indexer->update($document->documentId, [
            'id' => $document->documentId,
            'content' => $this->composeText($document),
        ]);

        $this->map->put($slot, $document->documentId, $document->type);
    }

    public function removeDocument(int $documentId): void
    {
        $slot = $this->map->activeSlot();

        $tnt = new TNTSearch();
        $tnt->loadConfig($this->config->create());
        $tnt->selectIndex($this->indexName($slot));
        $tnt->getIndex()->delete($documentId);

        $this->map->remove($slot, $documentId);
    }

    /**
     * Строка, которая реально попадает в индекс.
     *
     * Заголовок и ключевые слова повторяются: у TNTSearch нет полевых весов, а BM25 учитывает
     * частоту термина — значит совпадение в заголовке даёт более высокую оценку. Множитель
     * важности документа из настроек усиливает тот же повтор.
     */
    private function composeText(IndexableDocument $document): string
    {
        $titleTimes = max(1, (int)round(self::TITLE_WEIGHT * max(0.1, $document->boost)));
        $keywordTimes = max(1, (int)round(self::KEYWORDS_WEIGHT * max(0.1, $document->boost)));

        $parts = array_fill(0, $titleTimes, $document->title);

        if ($document->keywords !== '') {
            $parts = array_merge($parts, array_fill(0, $keywordTimes, $document->keywords));
        }

        $parts[] = $document->text;

        return implode(' ', $parts);
    }

    /**
     * Оценки релевантности, приведённые к целочисленным ключам документов.
     *
     * @param array<array-key, mixed> $docScores
     * @return array<int, float>
     */
    private function normalizeScores(array $docScores): array
    {
        $scores = [];
        foreach ($docScores as $id => $score) {
            $scores[(int)$id] = (float)$score;
        }

        return $scores;
    }

    /**
     * Распределение совпадений по разделам — для вкладок выдачи.
     *
     * Считается по ВСЕМ найденным документам, до фильтра по разделу: иначе вкладки показывали бы
     * ноль у всех разделов, кроме выбранного.
     *
     * @param list<int>          $ids
     * @param array<int, string> $types
     * @return array<string, int>
     */
    private function countByType(array $ids, array $types): array
    {
        $facets = [];

        foreach ($ids as $id) {
            $type = $types[$id] ?? null;
            if ($type === null) {
                continue;
            }

            $facets[$type] = ($facets[$type] ?? 0) + 1;
        }

        return $facets;
    }

    /**
     * Записать накопленные соответствия «документ → раздел».
     */
    private function flushTypes(): void
    {
        if ($this->pendingTypes === [] || $this->buildSlot === null) {
            return;
        }

        $this->map->addBatch($this->buildSlot, $this->pendingTypes);
        $this->pendingTypes = [];
    }

    private function resetBuild(): void
    {
        $this->buildSlot = null;
        $this->indexer = null;
        $this->pendingTypes = [];
        $this->reader = null;
    }

    /**
     * Читающее соединение с выбранным рабочим слотом (создаётся один раз на запрос).
     */
    private function reader(): TNTSearch
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        $tnt = new TNTSearch();
        $tnt->loadConfig($this->config->create());
        $tnt->selectIndex($this->indexName($this->map->activeSlot()));

        return $this->reader = $tnt;
    }

    private function indexName(string $slot): string
    {
        return self::INDEX_PREFIX . $slot;
    }

    /**
     * Удалить таблицы индекса слота.
     *
     * Прямой DROP, а не `flushIndex()` библиотеки: удаление слота должно работать и тогда, когда
     * индекс битый или собран другой версией TNTSearch, то есть без предварительного его открытия.
     */
    private function dropIndexTables(string $slot): void
    {
        $db = Yii::$app->db;
        $name = $this->indexName($slot);

        foreach (self::INDEX_TABLES as $suffix) {
            // Без `{{%…}}`: см. комментарий в isAvailable() — префикс таблиц Yii здесь не участвует.
            $table = $name . '_' . $suffix;

            if ($db->getTableSchema($table, true) !== null) {
                $db->createCommand()->dropTable($table)->execute();
            }
        }
    }
}
