<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Fixtures;

use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\InMemoryCommodityRepository;
use DateTimeImmutable;

/**
 * The real 6201 subtree - men's and boys' overcoats - as returned by the UK
 * Trade Tariff.
 *
 * Transcribed from a live chapter 62 response rather than invented, because an
 * invented tree got the most important detail wrong. Every SID, code,
 * productline suffix, indent level, declarability flag and description here
 * came off the wire.
 *
 * Three things this shape teaches that a plausible-looking fixture does not:
 *
 * 1. Weight conditions live on grouping lines, never on declarable ones. The
 *    split at 1 kg happens at indent 2, two levels above anything a merchant
 *    can declare against. Narrowing by weight therefore has to read ancestor
 *    descriptions; reading the candidate's own description finds nothing at
 *    all.
 *
 * 2. Commodity codes repeat across productline suffixes. 6201200011 is both
 *    SID 106844, a grouping line, and SID 106845, the declarable commodity
 *    beneath it. Storage keyed on the code loses one of them.
 *
 * 3. Declarable siblings include options that are almost never the right
 *    answer - "Hand-made ponchos" and "Hand-printed by the batik method" are
 *    real ten-digit codes sitting alongside "Parkas" and "Other".
 *
 * 4. The weight descriptions below contain a non-breaking space between the
 *    quantity and the unit, exactly as the tariff sends them. Replacing it
 *    with an ordinary space would make every weight pattern match trivially
 *    and hide the fact that PCRE does not treat U+00A0 as whitespace.
 *
 *     6201000000  heading                                    43116
 *     ├── 6201200000  Of wool or fine animal hair            106820
 *     │   ├── 6201200011  Overcoats, raincoats... (grouping)   106844
 *     │   │   ├── 6201200011  Hand-made ponchos              106845  ✓
 *     │   │   └── 6201200019  Other                          106846  ✓
 *     │   └── 6201200091  Other                              106847  ✓
 *     └── 6201300000  Of cotton                              106821
 *         ├── 6201301000  ...not exceeding 1 kg                106822
 *         │   ├── 6201301011  Overcoats... (grouping)          106848
 *         │   │   ├── 6201301011  Parkas                     106849  ✓
 *         │   │   └── 6201301019  Other                      106850  ✓
 *         │   └── 6201301091  Other (grouping)               106851
 *         │       ├── 6201301091  Hand-printed, batik        106852  ✓
 *         │       └── 6201301099  Other                      106853  ✓
 *         └── 6201309000  ...exceeding 1 kg                    106824
 *             ├── 6201309011  Overcoats... (grouping)          106854
 *             │   ├── 6201309011  Parkas                     106855  ✓
 *             │   └── 6201309019  Other                      106856  ✓
 *             └── 6201309091  Other (grouping)               106857
 *                 ├── 6201309091  Hand-printed, batik        106858  ✓
 *                 └── 6201309099  Other                      106859  ✓
 *
 * ✓ marks a declarable line. Eight of them sit under "Of cotton", four either
 * side of the weight split.
 */
final class ChapterSixtyTwoFixture
{
    public const HEADING_SID = 43116;

    public const WOOL_SUBHEADING_SID = 106820;
    public const PONCHO_GROUPING_SID = 106844;
    public const PONCHO_SID = 106845;
    public const WOOL_OTHER_SID = 106846;
    public const WOOL_OTHER_TOP_SID = 106847;

    public const COTTON_SUBHEADING_SID = 106821;

    public const COTTON_LIGHT_SID = 106822;
    public const COTTON_LIGHT_COATS_SID = 106848;
    public const COTTON_LIGHT_PARKA_SID = 106849;
    public const COTTON_LIGHT_OTHER_SID = 106850;
    public const COTTON_LIGHT_REST_SID = 106851;
    public const COTTON_LIGHT_BATIK_SID = 106852;
    public const COTTON_LIGHT_REST_OTHER_SID = 106853;

    public const COTTON_HEAVY_SID = 106824;
    public const COTTON_HEAVY_COATS_SID = 106854;
    public const COTTON_HEAVY_PARKA_SID = 106855;
    public const COTTON_HEAVY_OTHER_SID = 106856;
    public const COTTON_HEAVY_REST_SID = 106857;
    public const COTTON_HEAVY_BATIK_SID = 106858;
    public const COTTON_HEAVY_REST_OTHER_SID = 106859;

    /** Declarable lines beneath "Of cotton", four per weight branch. */
    public const COTTON_DECLARABLE_COUNT = 8;

    /** Declarable lines beneath the whole heading: three wool plus eight cotton. */
    public const HEADING_DECLARABLE_COUNT = 11;

    private const COATS_DESCRIPTION =
        'Overcoats, raincoats, car-coats, capes, cloaks and similar articles';

    public static function repository(): InMemoryCommodityRepository
    {
        return new InMemoryCommodityRepository(self::commodities());
    }

    /**
     * @return list<Commodity>
     */
    public static function commodities(): array
    {
        return [
            self::line(self::HEADING_SID, '6201000000', '80', 0, false, null,
                "Men's or boys' overcoats, car-coats, capes, cloaks, anoraks"),

            // Wool. Holds the duplicate-code pair.
            self::line(self::WOOL_SUBHEADING_SID, '6201200000', '80', 1, false, self::HEADING_SID,
                'Of wool or fine animal hair'),
            self::line(self::PONCHO_GROUPING_SID, '6201200011', '10', 2, false, self::WOOL_SUBHEADING_SID,
                self::COATS_DESCRIPTION),
            self::line(self::PONCHO_SID, '6201200011', '80', 3, true, self::PONCHO_GROUPING_SID,
                'Hand-made ponchos', 'NAR'),
            self::line(self::WOOL_OTHER_SID, '6201200019', '80', 3, true, self::PONCHO_GROUPING_SID,
                'Other', 'NAR'),
            self::line(self::WOOL_OTHER_TOP_SID, '6201200091', '80', 2, true, self::WOOL_SUBHEADING_SID,
                'Other', 'NAR'),

            // Cotton. Splits on garment weight two levels above anything declarable.
            self::line(self::COTTON_SUBHEADING_SID, '6201300000', '80', 1, false, self::HEADING_SID,
                'Of cotton'),

            self::line(self::COTTON_LIGHT_SID, '6201301000', '80', 2, false, self::COTTON_SUBHEADING_SID,
                'Of a weight, per garment, not exceeding 1 kg'),
            self::line(self::COTTON_LIGHT_COATS_SID, '6201301011', '10', 3, false, self::COTTON_LIGHT_SID,
                self::COATS_DESCRIPTION),
            self::line(self::COTTON_LIGHT_PARKA_SID, '6201301011', '80', 4, true, self::COTTON_LIGHT_COATS_SID,
                'Parkas', 'NAR'),
            self::line(self::COTTON_LIGHT_OTHER_SID, '6201301019', '80', 4, true, self::COTTON_LIGHT_COATS_SID,
                'Other', 'NAR'),
            self::line(self::COTTON_LIGHT_REST_SID, '6201301091', '10', 3, false, self::COTTON_LIGHT_SID,
                'Other'),
            self::line(self::COTTON_LIGHT_BATIK_SID, '6201301091', '80', 4, true, self::COTTON_LIGHT_REST_SID,
                'Hand-printed by the "batik" method', 'NAR'),
            self::line(self::COTTON_LIGHT_REST_OTHER_SID, '6201301099', '80', 4, true, self::COTTON_LIGHT_REST_SID,
                'Other', 'NAR'),

            self::line(self::COTTON_HEAVY_SID, '6201309000', '80', 2, false, self::COTTON_SUBHEADING_SID,
                'Of a weight, per garment, exceeding 1 kg'),
            self::line(self::COTTON_HEAVY_COATS_SID, '6201309011', '10', 3, false, self::COTTON_HEAVY_SID,
                self::COATS_DESCRIPTION),
            self::line(self::COTTON_HEAVY_PARKA_SID, '6201309011', '80', 4, true, self::COTTON_HEAVY_COATS_SID,
                'Parkas', 'NAR'),
            self::line(self::COTTON_HEAVY_OTHER_SID, '6201309019', '80', 4, true, self::COTTON_HEAVY_COATS_SID,
                'Other', 'NAR'),
            self::line(self::COTTON_HEAVY_REST_SID, '6201309091', '10', 3, false, self::COTTON_HEAVY_SID,
                'Other'),
            self::line(self::COTTON_HEAVY_BATIK_SID, '6201309091', '80', 4, true, self::COTTON_HEAVY_REST_SID,
                'Hand-printed by the "batik" method', 'NAR'),
            self::line(self::COTTON_HEAVY_REST_OTHER_SID, '6201309099', '80', 4, true, self::COTTON_HEAVY_REST_SID,
                'Other', 'NAR'),
        ];
    }

    /**
     * A line withdrawn in the HS2022 restructure, for the withdrawn-code path.
     *
     * Not part of the repository - the point of a withdrawn code is that the
     * current mirror does not contain it.
     */
    public static function withdrawnLine(): Commodity
    {
        return new Commodity(
            sid: 620193,
            code: '6201930000',
            productlineSuffix: Commodity::SUFFIX_DECLARABLE,
            description: 'Anoraks, of man-made fibres',
            declarable: true,
            parentSid: self::HEADING_SID,
            numberIndents: 1,
            validityStart: new DateTimeImmutable('2012-01-01'),
            validityEnd: new DateTimeImmutable('2021-12-31'),
        );
    }

    private static function line(
        int $sid,
        string $code,
        string $suffix,
        int $indents,
        bool $declarable,
        ?int $parentSid,
        string $description,
        ?string $supplementaryUnit = null,
    ): Commodity {
        return new Commodity(
            sid: $sid,
            code: $code,
            productlineSuffix: $suffix,
            description: $description,
            declarable: $declarable,
            parentSid: $parentSid,
            numberIndents: $indents,
            validityStart: new DateTimeImmutable('2022-01-01'),
            supplementaryUnit: $supplementaryUnit,
        );
    }
}
