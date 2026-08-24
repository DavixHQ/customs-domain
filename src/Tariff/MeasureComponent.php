<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * One term of a duty calculation.
 *
 * Most duties are a single percentage. Compound duties combine several
 * components — a percentage plus an amount per unit, or a ceiling.
 */
final class MeasureComponent
{
    public function __construct(
        public readonly string $dutyExpressionId,
        public readonly ?float $dutyAmount = null,
        public readonly ?string $monetaryUnitCode = null,
        public readonly ?string $measurementUnitCode = null,
        public readonly ?string $description = null,
        public readonly ?string $abbreviation = null,
    ) {
    }

    /**
     * Whether this component is a plain percentage of customs value.
     */
    public function isAdValorem(): bool
    {
        return $this->abbreviation === '%'
            && $this->monetaryUnitCode === null
            && $this->measurementUnitCode === null;
    }

    /**
     * Whether this component is an amount per unit of quantity or weight,
     * which is why net weight and supplementary units have to be populated.
     */
    public function isSpecific(): bool
    {
        return $this->monetaryUnitCode !== null || $this->measurementUnitCode !== null;
    }
}
