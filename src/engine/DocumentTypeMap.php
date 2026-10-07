<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchTnt\engine;

use Yii;
use yii\db\Connection;

/**
 * Карта «документ → раздел контента» и переключатель слотов индекса.
 *
 * Зачем нужна. TNTSearch — чисто текстовый движок: он не хранит атрибутов и не умеет ни
 * фильтровать выдачу, ни считать распределение по разделам. Чтобы контракт фасада выполнялся
 * честно (фильтр и пагинация — внутри ядра, а не поверх результата), ядро ведёт собственную
 * маленькую таблицу соответствия. Заглядывать в каталог фасада оно при этом не может и не должно:
 * это чужая таблица, и ядро обязано оставаться заменяемым.
 *
 * Слоты. Индекс собирается в свободный слот (`a` или `b`), а рабочим он становится одним
 * обновлением строки состояния. Так во время полной пересборки сайт продолжает искать по прежнему
 * индексу и не отдаёт пустую выдачу, а прерванная сборка не портит рабочий индекс.
 *
 * Схему создают миграции модуля (`migrations/`), как и у любого другого модуля системы: ядро —
 * полноценный модуль, и его служебные таблицы появляются при установке, а не самопроизвольно при
 * первом запросе. Данные в них полностью восстановимы переиндексацией, поэтому обе таблицы можно
 * исключать из дампа базы.
 */
final class DocumentTypeMap
{
    public const string SLOT_A = 'a';
    public const string SLOT_B = 'b';

    private const string MAP_TABLE = '{{%search_tnt_map}}';
    private const string STATE_TABLE = '{{%search_tnt_state}}';

    /** Служебные таблицы ядра — для подсчёта занимаемого места. */
    public const array TABLES = [self::MAP_TABLE, self::STATE_TABLE];
    private const string STATE_ACTIVE_SLOT = 'active_slot';

    private function db(): Connection
    {
        return Yii::$app->db;
    }


    /**
     * Слот, по которому сейчас идёт поиск.
     */
    public function activeSlot(): string
    {
        $value = $this->db()
            ->createCommand(
                'SELECT [[value]] FROM ' . self::STATE_TABLE . ' WHERE [[name]] = :name',
                [':name' => self::STATE_ACTIVE_SLOT],
            )
            ->queryScalar();

        return $value === self::SLOT_B ? self::SLOT_B : self::SLOT_A;
    }

    /**
     * Слот, в который безопасно собирать новый индекс.
     */
    public function buildSlot(): string
    {
        return $this->activeSlot() === self::SLOT_A ? self::SLOT_B : self::SLOT_A;
    }

    /**
     * Сделать слот рабочим — момент подмены индекса.
     */
    public function activate(string $slot): void
    {
        $this->db()->createCommand()->upsert(
            self::STATE_TABLE,
            ['name' => self::STATE_ACTIVE_SLOT, 'value' => $slot],
            ['value' => $slot],
        )->execute();
    }

    /**
     * Очистить карту слота (перед сборкой и после успешной подмены — для старого слота).
     */
    public function clear(string $slot): void
    {
        $this->db()->createCommand()->delete(self::MAP_TABLE, ['slot' => $slot])->execute();
    }

    /**
     * Стереть карту всех слотов и состояние: рабочим снова становится слот по умолчанию.
     *
     * Для полной очистки индекса ({@see TntSearchEngine::purge()}). TRUNCATE карты, а не DELETE:
     * данные восстановимы пересборкой, а место таблица отдаёт сразу.
     */
    public function purge(): void
    {
        $this->db()->createCommand()->truncateTable(self::MAP_TABLE)->execute();
        $this->db()->createCommand()->delete(self::STATE_TABLE)->execute();
    }

    /**
     * Записать соответствия пачкой.
     *
     * @param array<int, string> $documentTypes карта «id документа => тип»
     */
    public function addBatch(string $slot, array $documentTypes): void
    {
        if ($documentTypes === []) {
            return;
        }

        $rows = [];
        foreach ($documentTypes as $documentId => $type) {
            $rows[] = [$slot, (int)$documentId, $type];
        }

        $this->db()->createCommand()
            ->batchInsert(self::MAP_TABLE, ['slot', 'document_id', 'type'], $rows)
            ->execute();
    }

    /**
     * Записать одно соответствие поверх существующего — при точечном обновлении документа.
     *
     * Отдельно от {@see addBatch()}: та рассчитана на заведомо пустой слот и вставляет пачкой,
     * а здесь строка может уже существовать.
     */
    public function put(string $slot, int $documentId, string $type): void
    {
        $this->db()->createCommand()->upsert(
            self::MAP_TABLE,
            ['slot' => $slot, 'document_id' => $documentId, 'type' => $type],
            ['type' => $type],
        )->execute();
    }

    /**
     * Убрать одно соответствие — при точечном удалении документа из рабочего индекса.
     */
    public function remove(string $slot, int $documentId): void
    {
        $this->db()->createCommand()
            ->delete(self::MAP_TABLE, ['slot' => $slot, 'document_id' => $documentId])
            ->execute();
    }

    /**
     * Типы указанных документов в рабочем слоте.
     *
     * @param list<int> $documentIds
     * @return array<int, string> «id документа => тип»
     */
    public function typesOf(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $rows = $this->db()->createCommand(
            'SELECT [[document_id]], [[type]] FROM ' . self::MAP_TABLE
            . ' WHERE [[slot]] = :slot AND [[document_id]] IN (' . implode(',', array_map('intval', $documentIds)) . ')',
            [':slot' => $this->activeSlot()],
        )->queryAll();

        $types = [];
        foreach ($rows as $row) {
            $types[(int)$row['document_id']] = (string)$row['type'];
        }

        return $types;
    }
}
