<?php

declare(strict_types=1);

namespace Davix\Customs\Validation;

/**
 * A level in the tariff hierarchy, identified by its digit length.
 *
 * The first six digits are the internationally harmonised part — every WCO
 * member uses the same subheading for the same goods. Digits seven and eight
 * are the EU Combined Nomenclature, retained by the UK after exit. Digits nine
 * and ten are the national commodity level, and only that level is declarable
 * on a UK import.
 */
enum CodeLevel: int
{
    case Chapter = 2;
    case Heading = 4;
    case Subheading = 6;
    case Combined = 8;
    case Commodity = 10;

    public static function fromLength(int $length): ?self
    {
        return self::tryFrom($length);
    }

    public function digitLength(): int
    {
        return $this->value;
    }

    /**
     * Whether a code at this level can be used on a UK import declaration.
     *
     * Only the full ten-digit commodity level qualifies. A six-digit
     * subheading is a perfectly valid code that simply is not specific
     * enough to declare against — which is a classification problem, not a
     * formatting one.
     */
    public function isDeclarable(): bool
    {
        return $this === self::Commodity;
    }

    public function isMoreSpecificThan(self $other): bool
    {
        return $this->value > $other->value;
    }
}
