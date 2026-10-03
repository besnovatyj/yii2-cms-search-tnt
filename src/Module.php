<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchTnt;

use Besnovatyj\Contracts\module\DeclaresModule;
use Besnovatyj\Contracts\module\ProvidesDependencies;
use Besnovatyj\Contracts\module\ProvidesMigrations;
use Besnovatyj\Contracts\module\ProvidesOptions;
use Besnovatyj\Kernel\module\CmsModule;
use Besnovatyj\Search\contracts\SearchEngineDescriptor;
use Besnovatyj\Search\contracts\SearchEngineProvider;
use Besnovatyj\SearchTnt\engine\TntSearchEngine;

/**
 * Ядро сквозного поиска на TNTSearch: полнотекстовый индекс в базе самого проекта.
 *
 * Модулем, а не просто пакетом, оформлено ради трёх вещей, которых у пакета быть не может: его
 * можно включать и выключать менеджером модулей, у него есть собственные настройки в админке, и
 * его служебные таблицы заводятся миграциями при установке — как у любого другого модуля системы,
 * а не создаются на ходу первым же запросом.
 *
 * Ни контроллёров, ни пунктов меню модуль не добавляет: весь его видимый интерфейс — раздел
 * настроек и страница состояния индекса, которую рисует фасад. Выключенный модуль означает
 * «этого ядра в системе нет»: фасад перестаёт видеть его в списке и остаётся на своём.
 */
class Module extends CmsModule implements
    DeclaresModule,
    ProvidesDependencies,
    ProvidesMigrations,
    ProvidesOptions,
    SearchEngineProvider
{
    public const bool EDITABLE = true;
    public const string MODULE_ID = 'SearchTnt';

    /** Ключ ядра в настройках и в метке «каким ядром собран индекс». */
    public const string ENGINE_KEY = 'tnt';

    public static function moduleId(): string { return self::MODULE_ID; }
    public static function isEditable(): bool { return self::EDITABLE; }
    public static function moduleConfig(): array { return require __DIR__ . '/config/config.php'; }
    public static function options(): array { return require __DIR__ . '/config/options.php'; }
    public static function dependencies(): array { return require __DIR__ . '/config/dependencies.php'; }
    public static function migrationPath(): string { return __DIR__ . '/migrations'; }
    public static function migrationNamespace(): ?string { return __NAMESPACE__ . '\\migrations'; }

    /**
     * Ядро, которое этот модуль приносит фасаду. Реализация {@see SearchEngineProvider};
     * вызывается только модулем поиска, если он установлен.
     *
     * @return SearchEngineDescriptor[]
     */
    public function searchEngines(): array
    {
        return [
            new SearchEngineDescriptor(
                self::ENGINE_KEY,
                'TNTSearch (в базе проекта)',
                TntSearchEngine::class,
            ),
        ];
    }
}
