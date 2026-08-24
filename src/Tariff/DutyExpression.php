<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A duty rate as the tariff expresses it.
 *
 * Three renderings arrive together: a plain base, an HTML-marked version, and
 * a compact verbose form. Only the plain base is kept — the HTML is presentation
 * the host will style itself, and storing markup from an upstream service in a
 * domain object invites it into places it does not belong.
 */
final class DutyExpression
{
    public function __construct(
        public readonly string $base,
        public readonly ?string $verbose = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->base) === '';
    }

    public function isZero(): bool
    {
        $normalised = strtolower(trim($this->base));

        return $normalised === '0.00 %' || $normalised === '0.00%' || $normalised === '0 %';
    }

    /**
     * The ad valorem percentage, when the rate is a plain one.
     *
     * Returns null for compound and specific duties — "12.00 % + 8.50 EUR/kg"
     * has no single percentage, and inventing one to make a comparison work
     * would understate what a merchant actually pays.
     */
    public function percentage(): ?float
    {
        if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*%\s*$/', $this->base, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }

    public function isPlainPercentage(): bool
    {
        return $this->percentage() !== null;
    }

    public function __toString(): string
    {
        return $this->base;
    }
}
