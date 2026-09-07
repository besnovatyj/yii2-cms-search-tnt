<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchTnt\settings;

use Besnovatyj\Search\settings\ParamReader;
use TeamTNT\TNTSearch\Stemmer\NoStemmer;
use TeamTNT\TNTSearch\Stemmer\PorterStemmer;
use TeamTNT\TNTSearch\Stemmer\RussianStemmer;

/**
 * Сборка {@see TntSettings} из `params` модуля.
 *
 * Разбор строковых значений выполняет {@see ParamReader} из фасада: формат хранения настроек
 * общий для всего поиска, и второй разборщик означал бы вторую трактовку одних и тех же строк.
 *
 * Язык приложения приходит аргументом, а не читается из `Yii::$app`: класс остаётся чистым, а
 * знание о том, откуда берётся язык, живёт в composition root (`config/common.php`).
 */
final class TntSettingsFactory
{
    /** Морфология «по языку сайта». */
    private const string MORPHOLOGY_AUTO = 'auto';

    /**
     * Стеммеры, доступные к выбору.
     *
     * Русский Snowball отсекает окончания («ботинки» → «ботинк»), чего достаточно для падежей и
     * чисел. Смены основы («люди» → «человек») он не делает — это умеет только лемматизатор,
     * которого в TNTSearch нет; за ним нужно ядро Manticore.
     *
     * @var array<string, class-string>
     */
    private const array STEMMERS = [
        'ru'   => RussianStemmer::class,
        'en'   => PorterStemmer::class,
        'none' => NoStemmer::class,
    ];

    /**
     * @param array<string, mixed> $params   `params` модуля ядра
     * @param string               $language язык приложения (`Yii::$app->language`)
     */
    public function create(array $params, string $language): TntSettings
    {
        $reader = new ParamReader($params);

        return new TntSettings(
            stemmerClass: $this->stemmerClass($reader->string('morphology', self::MORPHOLOGY_AUTO), $language),
            maxMatches: $reader->int('maxMatches', 2000, min: 100, max: 20000),
        );
    }

    /**
     * Класс стеммера по настройке; `auto` — по языку приложения.
     *
     * @return class-string
     */
    private function stemmerClass(string $morphology, string $language): string
    {
        if ($morphology !== self::MORPHOLOGY_AUTO && isset(self::STEMMERS[$morphology])) {
            return self::STEMMERS[$morphology];
        }

        return str_starts_with($language, 'ru') ? RussianStemmer::class : PorterStemmer::class;
    }
}
