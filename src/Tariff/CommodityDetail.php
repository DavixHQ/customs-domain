<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A commodity together with everything fetched alongside it.
 *
 * What a rule sees once measures have been retrieved: the nomenclature line,
 * its measures, the flags HMRC precomputes, and the ancestor chain.
 *
 * The ancestors matter more than they look. Weight conditions live on grouping
 * lines rather than declarable ones, so a rule reasoning about the conditions
 * on a specific commodity has to read upward - the line itself says only
 * "Other".
 */
final class CommodityDetail
{
    /**
     * @param list<Commodity> $ancestors Nearest last, so the final entry is the
     *        immediate parent.
     * @param array<string, string> $footnotes Code to description.
     */
    public function __construct(
        public readonly Commodity $commodity,
        public readonly MeasureSet $measures,
        public readonly DutyCalculatorFlags $flags,
        public readonly array $ancestors = [],
        public readonly array $footnotes = [],
        public readonly ?string $basicThirdCountryDuty = null,
        public readonly ?string $preferentialTariffDuty = null,
        public readonly ?string $preferentialQuotaDuty = null,
    ) {
    }

    public function code(): string
    {
        return $this->commodity->code;
    }

    public function isDeclarable(): bool
    {
        return $this->commodity->isDeclarable();
    }

    public function importMeasures(): MeasureSet
    {
        return $this->measures->forDirection(TradeDirection::Import);
    }

    public function exportMeasures(): MeasureSet
    {
        return $this->measures->forDirection(TradeDirection::Export);
    }

    /**
     * Descriptions from the commodity and every ancestor, nearest first.
     *
     * The chain a weight or composition condition has to be read from.
     *
     * @return list<string>
     */
    public function descriptionChain(): array
    {
        $chain = [$this->commodity->description];

        foreach (array_reverse($this->ancestors) as $ancestor) {
            $chain[] = $ancestor->description;
        }

        return $chain;
    }

    public function immediateParent(): ?Commodity
    {
        return $this->ancestors === [] ? null : $this->ancestors[count($this->ancestors) - 1];
    }

    public function requiresSupplementaryUnit(): bool
    {
        return $this->commodity->requiresSupplementaryUnit()
            || !$this->measures->supplementaryUnits()->isEmpty();
    }
}
