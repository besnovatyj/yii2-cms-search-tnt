<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchTnt;

use RuntimeException;
use TeamTNT\TNTSearch\Engines\MysqlEngine;
use TeamTNT\TNTSearch\Stemmer\PorterStemmer;
use TeamTNT\TNTSearch\Stemmer\RussianStemmer;
use Yii;
use yii\base\Exception;
use yii\helpers\FileHelper;

/**
 * Сборка конфигурации TNTSearch из штатного компонента подключения к базе.
 *
 * Отдельные реквизиты доступа для поиска не заводятся намеренно: индекс живёт в той же базе, что
 * и контент, и второй набор кредов означал бы второй секрет в проде и второй способ ошибиться.
 * DSN разбирается ровно на те поля, которые ждёт TNTSearch.
 *
 * Стеммер выбирается по языку приложения. Он «запекается» в индекс на этапе индексации, поэтому
 * смена языка сайта требует полной пересборки — фасад сообщит об этом на странице состояния,
 * когда сравнит ядро и метку сборки.
 */
final class TntConfigFactory
{
    /** Каталог для служебных файлов TNTSearch (при MySQL-движке практически не используется). */
    private const string STORAGE_ALIAS = '@runtime/tntsearch';

    /**
     * @return array<string, mixed> конфиг для `TNTSearch::loadConfig()`
     *
     * @throws RuntimeException если подключение к базе не MySQL или DSN нечитаем
     */
    public function create(): array
    {
        $db = Yii::$app->db;

        if ($db->driverName !== 'mysql') {
            throw new RuntimeException(
                "Ядро поиска TNTSearch настроено на MySQL-хранилище индекса, а подключение использует «{$db->driverName}».",
            );
        }

        $dsn = $this->parseDsn($db->dsn);

        return [
            'driver' => 'mysql',
            'host' => $dsn['host'],
            'port' => $dsn['port'],
            'database' => $dsn['dbname'],
            'username' => (string)$db->username,
            'password' => (string)$db->password,
            'charset' => $db->charset ?? 'utf8mb4',
            'storage' => $this->storagePath(),
            'stemmer' => $this->stemmerClass(),
            'engine' => MysqlEngine::class,
        ];
    }

    /**
     * Класс стеммера по языку приложения.
     *
     * Русский Snowball отсекает окончания («ботинки» → «ботинк»), чего достаточно для падежей и
     * чисел. Смены основы («люди» → «человек») он не делает — это умеет только лемматизатор,
     * которого в TNTSearch нет; за ним нужно ядро Manticore.
     *
     * @return class-string
     */
    public function stemmerClass(): string
    {
        return str_starts_with(Yii::$app->language, 'ru')
            ? RussianStemmer::class
            : PorterStemmer::class;
    }

    /**
     * Каталог служебных файлов; создаётся при первом обращении.
     */
    private function storagePath(): string
    {
        $path = Yii::getAlias(self::STORAGE_ALIAS);

        if (!is_dir($path)) {
            try {
                FileHelper::createDirectory($path, 0775);
            } catch (Exception $e) {
                throw new RuntimeException("Не удалось создать каталог {$path} для TNTSearch: " . $e->getMessage());
            }
        }

        return $path . DIRECTORY_SEPARATOR;
    }

    /**
     * Разбор DSN Yii в поля, которых ждёт TNTSearch.
     *
     * @return array{host:string, port:int, dbname:string}
     */
    private function parseDsn(string $dsn): array
    {
        $parts = [];
        foreach (explode(';', substr($dsn, strpos($dsn, ':') + 1)) as $chunk) {
            $pair = explode('=', $chunk, 2);
            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }

        // unix_socket вместо host встречается в docker-сборках; TNTSearch подключается по TCP,
        // поэтому в таком случае честнее упасть сразу, а не искать индекс в пустой базе.
        if (!isset($parts['dbname'])) {
            throw new RuntimeException('В DSN подключения к базе не найдено имя базы (dbname).');
        }

        if (!isset($parts['host'])) {
            throw new RuntimeException(
                'В DSN подключения к базе не найден host: TNTSearch подключается по TCP и не умеет unix-сокет.',
            );
        }

        return [
            'host' => $parts['host'],
            'port' => (int)($parts['port'] ?? 3306),
            'dbname' => $parts['dbname'],
        ];
    }
}
