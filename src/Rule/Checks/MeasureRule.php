<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\RuleInterface;
use Davix\Customs\Tariff\MeasureSet;

/**
 * Shared ground for checks that read commodity measures.
 *
 * Every one of them needs the same two narrowings and gets them wrong in the
 * same two ways if left to do it alone.
 *
 * Direction first. A single commodity carries import and export measures
 * together - the artillery fixture has nine import prohibitions and eleven
 * export ones, and they are different restrictions. A merchant importing stock
 * told about export controls learns to ignore the module.
 *
 * Origin second, because most measures attach to a group of countries with
 * exclusions carved out. The 35% additional duty on a cotton parka applies to
 * Russia and Belarus and nowhere else; reporting it against Vietnamese stock
 * is worse than saying nothing.
 *
 * All measure rules depend on ambiguous expansion having passed, so they only
 * run once a single declarable commodity is known. Measures on a subheading
 * the merchant has not yet narrowed belong to several different classifications
 * at once and none of them can be reported honestly.
 */
abstract class MeasureRule implements RuleInterface
{
    public function prerequisites(): array
    {
        return [AmbiguousExpansion::CODE];
    }

    /**
     * Measures narrowed to direction and, where known, origin.
     *
     * Null when no measures were fetched - an offline scan resolves everything
     * against the mirror and makes no HTTP calls per product. Silence is the
     * only honest answer there.
     */
    protected function applicableMeasures(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?MeasureSet {
        $measures = $context->measuresForDirection();

        if ($measures === null) {
            return null;
        }

        $origin = $this->originOf($data);

        return $origin === null ? $measures : $measures->forOrigin($origin);
    }

    /**
     * Whether the product's origin is known well enough to narrow by.
     *
     * When it is not, measures are reported unnarrowed and the issue says so.
     * Suppressing a prohibition because the origin field is empty would be the
     * worst of both worlds: silent about something that stops a shipment.
     */
    protected function originOf(ProductCustomsDataInterface $data): ?string
    {
        $origin = trim((string) $data->countryOfOrigin());

        return $origin === '' ? null : strtoupper($origin);
    }

    protected function hasKnownOrigin(ProductCustomsDataInterface $data): bool
    {
        return $this->originOf($data) !== null;
    }
}
