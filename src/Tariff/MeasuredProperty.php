<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A property of the goods that the tariff may branch on.
 *
 * Keys rather than an enum because the set is open: the nomenclature measures
 * dozens of things, and a host that can supply one this package has not named
 * should not have to wait for a release. The constants cover what live data
 * actually branches on often enough to matter.
 *
 * A product supplies whichever of these it knows. A condition on a property
 * the product does not supply never eliminates a candidate - the same
 * conservative rule that governs weight, for the same reason: discarding the
 * correct classification is far worse than presenting one extra option.
 */
final class MeasuredProperty
{
    /** Net weight per item, in kilograms. */
    public const NET_WEIGHT = 'net_weight_kg';

    /** Volume of the immediate packing or container, in litres. */
    public const VOLUME = 'volume_litres';

    /** Actual alcoholic strength by volume, per cent. */
    public const ALCOHOL_STRENGTH = 'alcohol_percent';

    /** Milk fat or fat content by weight, per cent. */
    public const FAT_CONTENT = 'fat_percent';

    /** Protein content by weight, per cent. */
    public const PROTEIN_CONTENT = 'protein_percent';

    /** Sucrose or added sugar content by weight, per cent. */
    public const SUGAR_CONTENT = 'sugar_percent';

    /** Starch or glucose content by weight, per cent. */
    public const STARCH_CONTENT = 'starch_percent';

    /** Dry matter content by weight, per cent. */
    public const DRY_MATTER = 'dry_matter_percent';

    /** @var array<string, Dimension> */
    private const DIMENSIONS = [
        self::NET_WEIGHT => Dimension::Mass,
        self::VOLUME => Dimension::Volume,
        self::ALCOHOL_STRENGTH => Dimension::Percentage,
        self::FAT_CONTENT => Dimension::Percentage,
        self::PROTEIN_CONTENT => Dimension::Percentage,
        self::SUGAR_CONTENT => Dimension::Percentage,
        self::STARCH_CONTENT => Dimension::Percentage,
        self::DRY_MATTER => Dimension::Percentage,
    ];

    private function __construct()
    {
    }

    public static function dimensionOf(string $property): ?Dimension
    {
        return self::DIMENSIONS[$property] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function known(): array
    {
        return array_keys(self::DIMENSIONS);
    }

    public static function isKnown(string $property): bool
    {
        return isset(self::DIMENSIONS[$property]);
    }
}
