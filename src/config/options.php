<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

/**
 * Опции модуля настроек `yii2-cms-config` для ядра поиска на TNTSearch.
 *
 * Пути указывают в `modules.SearchTnt.params.*` — оттуда их читает
 * {@see \Besnovatyj\SearchTnt\settings\TntSettingsFactory}.
 *
 * Стеммер «запекается» в индекс при индексации, поэтому после его смены нужна полная пересборка
 * (`php yii Search/index/rebuild`). Пересобирать индекс сама настройка не пытается: на боевом
 * сайте это осознанное действие администратора, а фасад напомнит о необходимости пересборки на
 * своей странице состояния.
 *
 * Раздел настроек — общий с фасадом (`Search`): для администратора это одна тема, а не два
 * несвязанных модуля.
 */
return [
    'search_tnt_morphology' => [
        'path'        => 'modules.SearchTnt.params.morphology',
        'label'       => '[Поиск: TNTSearch] Стеммер',
        'description' => 'После смены нужна полная переиндексация',
        'category'    => 'Search',
        'rules'       => [
            ['required'],
            ['in', 'range' => ['auto', 'ru', 'en', 'none']],
        ],
        'inputOptions' => [
            'type'  => 'dropdown',
            'items' => [
                'auto' => 'По языку сайта (рекомендуется)',
                'ru'   => 'Русский (отсечение окончаний)',
                'en'   => 'Английский (Porter)',
                'none' => 'Без стеммера (поиск точных словоформ)',
            ],
        ],
    ],

    'search_tnt_max_matches' => [
        'path'        => 'modules.SearchTnt.params.maxMatches',
        'label'       => '[Поиск: TNTSearch] Окно совпадений',
        'description' => 'Сколько совпадений ядро забирает за запрос: глубина листания и точность вкладок',
        'category'    => 'Search',
        'rules'       => [
            ['required'],
            ['integer', 'min' => 100, 'max' => 20000],
        ],
        'inputOptions' => [
            'type' => 'number',
        ],
    ],
];
