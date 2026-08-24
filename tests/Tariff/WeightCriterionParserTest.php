<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Tariff;

use Davix\Customs\Tariff\WeightCriterion;
use Davix\Customs\Tariff\WeightCriterionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeightCriterionParserTest extends TestCase
{
    private WeightCriterionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WeightCriterionParser();
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
        self::assertSame($expected, $criterion->maximumKg);
        self::assertNull($criterion->minimumKg);
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
        self::assertSame($expected, $criterion->minimumKg);
        self::assertNull($criterion->maximumKg);
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
        self::assertSame(1.0, $criterion->maximumKg);
        self::assertNull($criterion->minimumKg, 'A maximum must never be read as a minimum');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noWeightProvider(): array
    {
        return [
            'other' => ['Other than parkas'],
            'material' => ['Of wool or fine animal hair'],
            'empty' => [''],
            'unrelated number' => ['Containing 85% or more by weight of silk'],
            'no unit' => ['Of a weight exceeding 1 per garment'],
            'zero' => ['Of a weight exceeding 0 kg'],
        ];
    }

    #[DataProvider('noWeightProvider')]
    public function testDescriptionsWithoutAWeightConditionYieldNull(string $description): void
    {
        self::assertNull($this->parser->parse($description));
        self::assertFalse($this->parser->describesWeight($description));
    }

    public function testMatchingAnUpperBound(): void
    {
        $criterion = WeightCriterion::notExceeding(1.0);

        self::assertTrue($criterion->matches(0.5));
        self::assertTrue($criterion->matches(1.0), 'Not exceeding is inclusive');
        self::assertFalse($criterion->matches(1.1));
    }

    public function testMatchingALowerBound(): void
    {
        $criterion = WeightCriterion::exceeding(1.0);

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
        $under = WeightCriterion::notExceeding(1.0);
        $over = WeightCriterion::exceeding(1.0);

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
        self::assertSame('not exceeding 1 kg', WeightCriterion::notExceeding(1.0)->describes());
        self::assertSame('exceeding 0.5 kg', WeightCriterion::exceeding(0.5)->describes());
        self::assertSame('exceeding 0.25 kg', WeightCriterion::exceeding(0.25)->describes());
    }

    public function testCaseInsensitivity(): void
    {
        $criterion = $this->parser->parse('OF A WEIGHT NOT EXCEEDING 1 KG PER GARMENT');

        self::assertNotNull($criterion);
        self::assertSame(1.0, $criterion->maximumKg);
    }
}
