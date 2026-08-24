<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A country, or a group of them, that a measure applies to.
 *
 * Groups carry their members: ERGA OMNES arrives with 261 children. That
 * matters because a measure attached to a group with a handful of exclusions
 * is the normal shape, and resolving whether it applies to one merchant's
 * origin needs both the membership and the exclusions.
 */
final class GeographicalArea
{
    /** Every country in the world, the default scope for third country duty. */
    public const ERGA_OMNES = '1011';

    /**
     * @param list<string> $memberCodes Country codes belonging to this group,
     *        empty for an area that is itself a country.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly array $memberCodes = [],
        public readonly ?int $sid = null,
    ) {
    }

    public function isGroup(): bool
    {
        return $this->memberCodes !== [];
    }

    public function isErgaOmnes(): bool
    {
        return $this->id === self::ERGA_OMNES;
    }

    /**
     * Whether an origin falls inside this area, before exclusions.
     */
    public function covers(string $countryCode): bool
    {
        $code = strtoupper($countryCode);

        if (strtoupper($this->id) === $code) {
            return true;
        }

        return in_array($code, array_map('strtoupper', $this->memberCodes), true);
    }

    public function memberCount(): int
    {
        return count($this->memberCodes);
    }
}
