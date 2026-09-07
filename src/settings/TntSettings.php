<?php

/*
 * Copyright (c) 2026 Besnovatyj. Licensed under the MIT License.
 */

declare(strict_types=1);

namespace Besnovatyj\SearchTnt\settings;

/**
 * Настройки ядра на TNTSearch — готовые значения, ничего не вычисляющие.
 *
 * Собираются {@see TntSettingsFactory} из `params` модуля; `Yii` здесь нет, поэтому объект можно
 * создать в тесте одной строкой.
 */
final readonly class TntSettings
{
    /**
     * @param string $stemmerClass Класс стеммера TNTSearch. Уже разрешён фабрикой: настройка
     *                             «по языку сайта» превращена в конкретный класс, потому что
     *                             стеммер «запекается» в индекс при сборке и должен совпадать
     *                             с тем, что применяется при поиске.
     * @param int    $maxMatches   Потолок числа совпадений, забираемых у движка за один запрос.
     *                             Это и есть заявленная граница ядра: фильтр по разделам и
     *                             пагинация считаются по этому списку, поэтому при большем числе
     *                             совпадений выдача станет неполной на дальних страницах.
     *
     * @phpstan-param class-string<\TeamTNT\TNTSearch\Stemmer\StemmerInterface> $stemmerClass
     */
    public function __construct(
        public string $stemmerClass,
        public int $maxMatches,
    ) {
    }
}
