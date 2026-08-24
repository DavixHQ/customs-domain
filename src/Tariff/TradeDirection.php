<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * Which way goods are moving.
 *
 * A single commodity response carries both directions mixed together — a
 * cotton parka comes back with import duty alongside export controls on cat
 * and dog fur. Reporting an export restriction to a merchant importing stock,
 * or the reverse, is noise that teaches them to ignore the module.
 */
enum TradeDirection: string
{
    case Import = 'import';
    case Export = 'export';

    public function opposite(): self
    {
        return $this === self::Import ? self::Export : self::Import;
    }
}
