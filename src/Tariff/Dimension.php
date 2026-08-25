<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * What kind of quantity a classification condition measures.
 *
 * The tariff branches on more than weight. Chapter 22 splits 342 times on
 * alcoholic strength and 43 on container volume; chapter 4 splits on fat
 * content. A narrowing step that reads only mass works for apparel and does
 * nothing for a beverage or dairy catalogue.
 */
enum Dimension: string
{
    /** Kilograms. */
    case Mass = 'mass';

    /** Litres. */
    case Volume = 'volume';

    /** Per cent, 0 to 100. */
    case Percentage = 'percentage';

    /** A plain count of items. */
    case Count = 'count';

    public function baseUnit(): string
    {
        return match ($this) {
            self::Mass => 'kg',
            self::Volume => 'l',
            self::Percentage => '%',
            self::Count => 'items',
        };
    }
}
