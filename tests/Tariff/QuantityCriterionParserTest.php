<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Tariff;

use Davix\Customs\Tariff\Dimension;
use Davix\Customs\Tariff\MeasuredProperty;
use Davix\Customs\Tariff\QuantityCriterion;
use Davix\Customs\Tariff\QuantityCriterionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityCriterionParserTest extends TestCase
{
    private QuantityCriterionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QuantityCriterionParser();
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function maximumProvider(): array
    {
        return [
            'tariff wording' => ['Of a weight not exceeding 1 kg per garment', 1.0],
            'decimal' => ['Of a weight not exceeding 0.5 kg', 0.5],
            'grams' => ['Of a weight not exceeding 500 g', 0.5],
            'less than' => ['Of a weight less than 2 kg', 2.0],
            'not more than' => ['Weighing not more than 3 kg', 3.0],
            'kilograms spelled out' => ['Of a weight not exceeding 1 kilogram', 1.0],
        ];
    }

    #[DataProvider('maximumProvider')]
    public function testUpperBoundsAreParsed(string $description, float $expected): void
    {
        $criterion = $this->parser->parse($description);

        self::assertNotNull($criterion);
        self::assertSame($expected, $criterion->maximum);
        self::assertNull($criterion->minimum);
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function minimumProvider(): array
    {
        return [
            'tariff wording' => ['Of a weight exceeding 1 kg per garment', 1.0],
            'more than' => ['Weighing more than 2 kg', 2.0],
            'grams' => ['Of a weight exceeding 250 g', 0.25],
            'or more' => ['Of a weight of 5 kg or more', 5.0],
        ];
    }

    #[DataProvider('minimumProvider')]
    public function testLowerBoundsAreParsed(string $description, float $expected): void
    {
        $criterion = $this->parser->parse($description);

        self::assertNotNull($criterion);
        self::assertSame($expected, $criterion->minimum);
        self::assertNull($criterion->maximum);
    }

    /**
     * The parsing order that matters most. "Not exceeding 1 kg" contains
     * "exceeding 1 kg", so testing the positive form first would invert every
     * upper bound in the nomenclature.
     */
    public function testNegativePhrasingIsNotMistakenForPositive(): void
    {
        $criterion = $this->parser->parse('Of a weight not exceeding 1 kg per garment');

        self::assertNotNull($criterion);
        self::assertSame(1.0, $criterion->maximum);
        self::assertNull($criterion->minimum, 'A maximum must never be read as a minimum');
    }

    /**
     * A textile line stating a fibre proportion is a real condition, and is
     * read as one - but "silk" is not a property this package names, so the
     * criterion has no subject and cannot be tested.
     *
     * Detected and inert is the right outcome. Returning null would discard
     * information a host might later supply a value for; treating it as
     * measurable would test a silk proportion against whatever number happened
     * to be to hand.
     */
    public function testAFibreProportionIsDetectedButHasNoNamedSubject(): void
    {
        $criterion = $this->parser->parse('Containing 85% or more by weight of silk');

        self::assertNotNull($criterion);
        self::assertSame(85.0, $criterion->minimum);
        self::assertTrue($criterion->minimumInclusive);
        self::assertFalse($criterion->hasKnownProperty());
        self::assertTrue(
            $criterion->matchesProperties([MeasuredProperty::NET_WEIGHT => 1.0]),
            'A criterion with no subject must never eliminate a candidate',
        );
    }

    public function testMatchingAnUpperBound(): void
    {
        $criterion = QuantityCriterion::notExceeding(1.0);

        self::assertTrue($criterion->matches(0.5));
        self::assertTrue($criterion->matches(1.0), 'Not exceeding is inclusive');
        self::assertFalse($criterion->matches(1.1));
    }

    public function testMatchingALowerBound(): void
    {
        $criterion = QuantityCriterion::exceeding(1.0);

        self::assertFalse($criterion->matches(0.5));
        self::assertFalse($criterion->matches(1.0), 'Exceeding is exclusive');
        self::assertTrue($criterion->matches(1.1));
    }

    /**
     * The two forms must partition the range with no gap and no overlap, or a
     * garment of exactly the threshold weight either matches both lines or
     * neither.
     */
    public function testTheTwoFormsPartitionCleanlyAtTheThreshold(): void
    {
        $under = QuantityCriterion::notExceeding(1.0);
        $over = QuantityCriterion::exceeding(1.0);

        foreach ([0.999, 1.0, 1.001] as $weight) {
            self::assertNotSame(
                $under->matches($weight),
                $over->matches($weight),
                sprintf('Exactly one line must match at %s kg', (string) $weight),
            );
        }
    }

    public function testDescription(): void
    {
        self::assertSame('not exceeding 1 kg', QuantityCriterion::notExceeding(1.0)->describes());
        self::assertSame('exceeding 0.5 kg', QuantityCriterion::exceeding(0.5)->describes());
        self::assertSame('exceeding 0.25 kg', QuantityCriterion::exceeding(0.25)->describes());
    }

    /**
     * The tariff writes decimals with a comma: chapter 4 has "net content not
     * exceeding 2,5 kg". Reading that as a thousands separator gives 25 kg, a
     * tenfold error, and worse than not parsing, because it produces a
     * confident threshold that is wrong.
     */
    public function testEuropeanDecimalCommaIsNotReadAsAThousandsSeparator(): void
    {
        $criterion = $this->parser->parse('In immediate packings of a net content not exceeding 2,5 kg');

        self::assertNotNull($criterion);
        self::assertSame(2.5, $criterion->maximum);
    }

    public function testThousandsSeparatorsStillParse(): void
    {
        $criterion = $this->parser->parse('Of a weight not exceeding 1,000 g');

        self::assertNotNull($criterion);
        self::assertSame(1.0, $criterion->maximum);
    }

    /**
     * The conditions this parser previously could not read at all.
     *
     * Counted across live chapters 4, 22 and 62: 348 lines branch on alcoholic
     * strength, 43 on container volume, 35 on fat content. A parser that reads
     * only mass narrows an apparel catalogue and leaves every beverage and
     * dairy catalogue untouched.
     *
     * @return array<string, array{string, string, float}>
     */
    public static function nonMassConditionProvider(): array
    {
        return [
            'alcoholic strength' => [
                'Of an actual alcoholic strength by volume not exceeding 15 % vol',
                MeasuredProperty::ALCOHOL_STRENGTH,
                15.0,
            ],
            'fat content' => [
                'Of a fat content, by weight, not exceeding 1 %',
                MeasuredProperty::FAT_CONTENT,
                1.0,
            ],
            'container volume' => [
                'In containers holding 10 litres or less',
                MeasuredProperty::VOLUME,
                10.0,
            ],
            'volume written as a word' => [
                'In immediate packings of a net content not exceeding two litres',
                MeasuredProperty::VOLUME,
                2.0,
            ],
            'grams converted to kilograms' => [
                'Pizza cheese, frozen, cut into pieces each weighing not more than 1 gram',
                MeasuredProperty::NET_WEIGHT,
                0.001,
            ],
        ];
    }

    #[DataProvider('nonMassConditionProvider')]
    public function testConditionsOutsideMassAreRead(
        string $description,
        string $property,
        float $maximum,
    ): void {
        $criterion = $this->parser->parse($description);

        self::assertNotNull($criterion);
        self::assertSame($property, $criterion->property);
        self::assertSame($maximum, $criterion->maximum);
    }

    /**
     * A band stated in one line. Read before either bound in isolation,
     * because taking only the first loses the other end of the range.
     */
    public function testARangeCarriesBothBounds(): void
    {
        $criterion = $this->parser->parse('Of a fat content, by weight, exceeding 1 % but not exceeding 6 %');

        self::assertNotNull($criterion);
        self::assertSame(MeasuredProperty::FAT_CONTENT, $criterion->property);
        self::assertSame(1.0, $criterion->minimum);
        self::assertSame(6.0, $criterion->maximum);
        self::assertFalse($criterion->minimumInclusive, '"exceeding" excludes the threshold');
        self::assertTrue($criterion->maximumInclusive, '"not exceeding" includes it');
        self::assertFalse($criterion->matches(1.0));
        self::assertTrue($criterion->matches(6.0));
    }

    public function testInclusiveLowerBounds(): void
    {
        $criterion = $this->parser->parse(
            'Undenatured ethyl alcohol of an alcoholic strength by volume of 80 % vol or higher',
        );

        self::assertNotNull($criterion);
        self::assertSame(80.0, $criterion->minimum);
        self::assertTrue($criterion->minimumInclusive, '"or higher" includes the threshold');
        self::assertTrue($criterion->matches(80.0));
    }

    /**
     * Chapter 4 has 73 lines reading simply "Not exceeding 3 %". The subject
     * sits on a parent that carries no number, so threshold and subject live
     * on different lines and neither is usable alone. Parsed, but inert until
     * something supplies the missing word.
     */
    public function testASubjectlessPercentageIsParsedButNotMeasurable(): void
    {
        $criterion = $this->parser->parse('Not exceeding 3 %');

        self::assertNotNull($criterion);
        self::assertFalse($criterion->hasKnownProperty());
        self::assertTrue(
            $criterion->matchesProperties([MeasuredProperty::FAT_CONTENT => 99.0]),
            'An unmeasurable condition must never eliminate a candidate',
        );

        $named = $criterion->withProperty(MeasuredProperty::FAT_CONTENT);

        self::assertFalse($named->matchesProperties([MeasuredProperty::FAT_CONTENT => 99.0]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noConditionProvider(): array
    {
        return [
            'parkas' => ['Parkas'],
            'other' => ['Other'],
            'batik' => ['Hand-printed by the "batik" method'],
            'material only' => ['Of wool or fine animal hair'],
            'empty' => [''],
            'number without a unit' => ['Of a weight exceeding 1 per garment'],
            'zero threshold' => ['Of a weight exceeding 0 kg'],
        ];
    }

    #[DataProvider('noConditionProvider')]
    public function testDescriptionsWithoutAConditionYieldNull(string $description): void
    {
        self::assertNull($this->parser->parse($description));
    }

    /**
     * A condition measuring something the product has not recorded is inert
     * rather than eliminating. Discarding the correct classification is far
     * worse than presenting one extra option.
     */
    public function testAConditionOnAnUnrecordedPropertyNeverEliminates(): void
    {
        $criterion = $this->parser->parse('Of an actual alcoholic strength by volume not exceeding 15 % vol');

        self::assertNotNull($criterion);
        self::assertTrue($criterion->matchesProperties([MeasuredProperty::NET_WEIGHT => 1.0]));
        self::assertFalse($criterion->matchesProperties([MeasuredProperty::ALCOHOL_STRENGTH => 40.0]));
    }

    public function testCaseInsensitivity(): void
    {
        $criterion = $this->parser->parse('OF A WEIGHT NOT EXCEEDING 1 KG PER GARMENT');

        self::assertNotNull($criterion);
        self::assertSame(1.0, $criterion->maximum);
    }
}