<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A quantity condition a commodity line imposes.
 *
 * Replaces the earlier weight-only criterion. Live data made the case: chapter
 * 22 branches 342 times on alcoholic strength and 43 on container volume,
 * chapter 4 on fat content, chapter 62 on garment weight. A model that reads
 * only mass narrows apparel and leaves every other catalogue untouched.
 *
 * Bounds carry their own inclusivity because the tariff's wording is precise
 * and the boundary cases are real. "Exceeding 1 kg" excludes exactly 1 kg;
 * "not exceeding 1 kg" includes it. Get that backwards and every garment
 * weighing exactly the threshold is misclassified - and thresholds are chosen
 * to sit where products cluster.
 *
 * The property may be unknown. Chapter 4 contains 76 lines reading simply "Not
 * exceeding 3 %", whose subject sits on a parent line naming the fat content.
 * Such a criterion is parsed but cannot be matched until something supplies
 * the subject, and an unmatched criterion never eliminates anything.
 */
final class QuantityCriterion
{
    private function __construct(
        public readonly Dimension $dimension,
        public readonly ?string $property = null,
        public readonly ?float $minimum = null,
        public readonly bool $minimumInclusive = false,
        public readonly ?float $maximum = null,
        public readonly bool $maximumInclusive = true,
    ) {
    }

    /**
     * Goods above a threshold. The tariff's "exceeding" is exclusive.
     */
    public static function exceeding(
        float $value,
        Dimension $dimension = Dimension::Mass,
        ?string $property = null,
        bool $inclusive = false,
    ): self {
        return new self($dimension, $property, $value, $inclusive);
    }

    /**
     * Goods at or below a threshold. "Not exceeding" is inclusive.
     */
    public static function notExceeding(
        float $value,
        Dimension $dimension = Dimension::Mass,
        ?string $property = null,
        bool $inclusive = true,
    ): self {
        return new self($dimension, $property, null, false, $value, $inclusive);
    }

    /**
     * A band, as in "exceeding 1 % but not exceeding 6 %".
     */
    public static function between(
        float $minimum,
        float $maximum,
        Dimension $dimension = Dimension::Mass,
        ?string $property = null,
        bool $minimumInclusive = false,
        bool $maximumInclusive = true,
    ): self {
        return new self($dimension, $property, $minimum, $minimumInclusive, $maximum, $maximumInclusive);
    }

    public function matches(float $value): bool
    {
        if ($this->minimum !== null) {
            $satisfied = $this->minimumInclusive ? $value >= $this->minimum : $value > $this->minimum;

            if (!$satisfied) {
                return false;
            }
        }

        if ($this->maximum !== null) {
            $satisfied = $this->maximumInclusive ? $value <= $this->maximum : $value < $this->maximum;

            if (!$satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this criterion can be tested against a set of measured
     * properties.
     *
     * False when the subject is unknown, or when the product does not supply
     * it. Either way the criterion is inert rather than failing.
     *
     * @param array<string, float> $properties
     */
    public function isMeasurableAgainst(array $properties): bool
    {
        return $this->property !== null && array_key_exists($this->property, $properties);
    }

    /**
     * Test against a product's measured properties.
     *
     * Returns true when the criterion cannot be tested at all, so that an
     * untestable condition never eliminates a candidate.
     *
     * @param array<string, float> $properties
     */
    public function matchesProperties(array $properties): bool
    {
        if (!$this->isMeasurableAgainst($properties)) {
            return true;
        }

        return $this->matches($properties[(string) $this->property]);
    }

    public function hasKnownProperty(): bool
    {
        return $this->property !== null;
    }

    /**
     * A copy naming the subject, for a bare condition whose parent supplies it.
     */
    public function withProperty(string $property): self
    {
        return new self(
            $this->dimension,
            $property,
            $this->minimum,
            $this->minimumInclusive,
            $this->maximum,
            $this->maximumInclusive,
        );
    }

    public function describes(): string
    {
        $unit = $this->dimension->baseUnit();

        if ($this->minimum !== null && $this->maximum !== null) {
            return sprintf(
                '%s %s to %s %s',
                $this->minimumInclusive ? 'from' : 'over',
                $this->format($this->minimum),
                $this->format($this->maximum),
                $unit,
            );
        }

        if ($this->minimum !== null) {
            return sprintf(
                '%s %s %s',
                $this->minimumInclusive ? 'at least' : 'exceeding',
                $this->format($this->minimum),
                $unit,
            );
        }

        if ($this->maximum !== null) {
            return sprintf(
                '%s %s %s',
                $this->maximumInclusive ? 'not exceeding' : 'less than',
                $this->format($this->maximum),
                $unit,
            );
        }

        return 'no condition';
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
