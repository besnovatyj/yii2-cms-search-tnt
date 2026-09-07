<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchTnt\migrations;

use Besnovatyj\Kernel\migration\BaseMigration;
use yii\base\NotSupportedException;

/**
 * Карта «документ индекса → раздел контента» для ядра на TNTSearch.
 *
 * Зачем отдельная таблица: TNTSearch — чисто текстовый движок, он не хранит атрибутов и не умеет
 * ни фильтровать выдачу по разделу, ни считать распределение по разделам для вкладок. Чтобы
 * контракт фасада выполнялся честно (фильтр и пагинация — внутри ядра), ядро ведёт соответствие
 * само. Заглядывать в каталог фасада (`search_documents`) оно при этом не может: это чужая
 * таблица, а ядро обязано оставаться заменяемым.
 *
 * Строки живут парами со слотами индекса (`a`/`b`): пока один слот рабочий, второй наполняется
 * пересборкой. Поэтому первичный ключ составной — слот плюс документ.
 *
 * Содержимое полностью восстановимо переиндексацией, поэтому таблицу можно исключать из дампа базы.
 */
class m260907_100000_create_search_tnt_map_table extends BaseMigration
{
    public const string TABLE_NAME = '{{%search_tnt_map}}';

    /**
     * @throws NotSupportedException
     */
    public function safeUp(): void
    {
        parent::safeUp();

        if ($this->existTable(static::TABLE_NAME)) {
            return;
        }

        $this->createTable(static::TABLE_NAME, [
            'slot'        => $this->char(1)->notNull()
                ->comment('Слот индекса: a или b'),
            'document_id' => $this->integer()->notNull()
                ->comment('Первичный ключ строки каталога search_documents'),
            'type'        => $this->string(64)->notNull()
                ->comment('Ключ источника: <модуль>.<сущность>, напр. blog.post'),
        ], $this->tableOptions);

        $this->addCommentOnTable(static::TABLE_NAME, 'Карта «документ → раздел» ядра поиска TNTSearch');

        // Документ уникален внутри слота: пересборка наполняет свободный слот, не трогая рабочий.
        $this->createIndexes(static::TABLE_NAME, ['slot', 'document_id'], true);
        // Выборка типов рабочего слота и подсчёт вкладок выдачи.
        $this->createIndexes(static::TABLE_NAME, ['slot', 'type'], false, false);
    }

    /**
     * Удаление таблицы (вместе с индексами и внешними ключами) выполняет {@see BaseMigration::safeDown()}
     * по `static::TABLE_NAME`.
     *
     * @throws NotSupportedException
     */
    public function safeDown(): void
    {
        parent::safeDown();
    }
}
