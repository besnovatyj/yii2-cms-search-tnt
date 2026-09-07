<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchTnt\migrations;

use Besnovatyj\Kernel\migration\BaseMigration;
use yii\base\NotSupportedException;

/**
 * Состояние ядра на TNTSearch: какой слот индекса сейчас рабочий.
 *
 * Смысл слотов — безопасная пересборка: индекс собирается в свободный слот (`a` или `b`), а
 * рабочим он становится обновлением одной строки этой таблицы. Поэтому во время полной
 * переиндексации сайт продолжает искать по прежнему индексу, а прерванная сборка не портит
 * рабочий — она просто оставляет недособранный слот, который затрётся следующей попыткой.
 *
 * Именно в базе, а не в кэше: apcu на боевом сервере живёт в каждом процессе PHP-FPM отдельно, и
 * подмена слота, выполненная консолью, до воркеров бы не дошла.
 */
class m260907_100100_create_search_tnt_state_table extends BaseMigration
{
    public const string TABLE_NAME = '{{%search_tnt_state}}';

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
            'name'  => $this->string(64)->notNull()
                ->comment('Ключ состояния, напр. active_slot'),
            'value' => $this->string(255)->null()->defaultValue(null)
                ->comment('Значение'),
        ], $this->tableOptions);

        $this->addCommentOnTable(static::TABLE_NAME, 'Состояние ядра поиска TNTSearch');

        $this->createIndexes(static::TABLE_NAME, 'name', true);
    }

    /**
     * @throws NotSupportedException
     */
    public function safeDown(): void
    {
        parent::safeDown();
    }
}
