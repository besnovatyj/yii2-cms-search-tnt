<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

use Besnovatyj\SearchTnt\engine\DocumentTypeMap;
use Besnovatyj\SearchTnt\engine\TntConfigFactory;
use Besnovatyj\SearchTnt\engine\TntSearchEngine;
use Besnovatyj\SearchTnt\Module;
use Besnovatyj\SearchTnt\settings\TntSettings;
use Besnovatyj\SearchTnt\settings\TntSettingsFactory;
use yii\di\Container;

/**
 * Yii2-конфиг модуля для движка yiisoft/config (группа `common` — общий для всех приложений).
 *
 * Объявляется через `extra.config-plugin`, собирается modman в merge-plan и мёржится в рантайме.
 * Регистрация модуля и DI-проводка — здесь, и только здесь: `config/container.php` выполняется
 * при инициализации модуля, а к модулю ядра никто не обращается — маршрутов у него нет, его
 * создаёт фасад через контейнер. Bootstrap-класс по той же причине не нужен: `container.singletons`
 * применяется в `preInit`, то есть раньше любой возможной точки обращения, и при этом лениво —
 * пока поиск не понадобился, ничего не создаётся.
 *
 * Группа `common`, а не `app-frontend`: искать умеет и фронт, и консоль (переиндексация), и
 * админка (страница состояния индекса).
 */
return [
    'modules' => [
        Module::moduleId() => array_merge(
            ['class' => Module::class],
            Module::moduleConfig(),
        ),
    ],
    'container' => [
        'singletons' => [
            /**
             * Настройки ядра — один объект на запрос.
             *
             * Замыкание — единственное место пакета, знающее о `Yii::$app`: значения приезжают
             * из модуля настроек прямо в объект модуля, а язык приложения нужен для варианта
             * «стеммер по языку сайта». Ленивость обязательна — на момент сборки конфига ни того,
             * ни другого ещё нет.
             */
            TntSettings::class => static fn (Container $c): TntSettings => $c
                ->get(TntSettingsFactory::class)
                ->create(
                    (array)(Yii::$app->getModule(Module::MODULE_ID)?->params ?? []),
                    (string)Yii::$app->language,
                ),

            /**
             * Движок и его помощники — синглтоны: у сборки индекса есть состояние (открытый слот,
             * накопленные соответствия «документ → раздел»), и оно обязано принадлежать одному
             * объекту на весь прогон переиндексации.
             */
            TntSettingsFactory::class => TntSettingsFactory::class,
            TntConfigFactory::class => TntConfigFactory::class,
            DocumentTypeMap::class => DocumentTypeMap::class,
            TntSearchEngine::class => TntSearchEngine::class,
        ],
    ],
];
