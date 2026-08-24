<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A weight condition a commodity line imposes, in kilograms.
 *
 * Apparel subheadings routinely split on garment weight before splitting on
 * anything else — 620140 divides at 1 kg per garment, then again on parka
 * versus other. That makes net weight a classification input rather than
 * merely a duty input, and it is the strongest argument for pushing merchants
 * to populate it: a populated weight often halves the candidate list before
 * the merchant is asked anything at all.
 */
final class WeightCriterion
{
    private function __construct(
        public readonly ?float $minimumKg,
        public readonly ?float $maximumKg,
    ) {
    }

    /**
     * Goods heavier than a threshold. The tariff phrases this as "exceeding",
     * which is exclusive.
     */
    public static function exceeding(float $kilograms): self
    {
        return new self($kilograms, null);
    }

    /**
     * Goods no heavier than a threshold — "not exceeding", inclusive.
     */
    public static function notExceeding(float $kilograms): self
    {
        return new self(null, $kilograms);
    }

    public function matches(float $weightKg): bool
    {
        if ($this->minimumKg !== null && $weightKg <= $this->minimumKg) {
            return false;
        }

        if ($this->maximumKg !== null && $weightKg > $this->maximumKg) {
            return false;
        }

        return true;
    }

    public function describes(): string
    {
        if ($this->minimumKg !== null) {
            return sprintf('exceeding %s kg', $this->format($this->minimumKg));
        }

        if ($this->maximumKg !== null) {
            return sprintf('not exceeding %s kg', $this->format($this->maximumKg));
        }

        return 'no weight condition';
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
