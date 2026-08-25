<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Tariff;

use Davix\Customs\Provider\Hmrc\ChapterCsvParser;
use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\InMemoryCommodityRepository;
use Davix\Customs\Tariff\MeasuredProperty;
use Davix\Customs\Tariff\QuantityCriterionParser;
use PHPUnit\Framework\TestCase;

/**
 * Measures how much of the real nomenclature the parser can actually read.
 *
 * Unit tests prove a pattern works on the phrasing it was written for. This
 * proves the patterns cover what the tariff actually says, across three
 * chapters chosen because they branch on entirely different things: garment
 * weight, alcoholic strength and fat content.
 *
 * The thresholds are floors rather than exact counts. HMRC revises the
 * nomenclature and a refreshed capture will shift the numbers; a sharp drop
 * means a pattern has stopped matching, which is the thing worth catching.
 */
final class QuantityCoverageTest extends TestCase
{
    /**
     * @return list<Commodity>
     */
    private function chapter(string $file): array
    {
        $csv = file_get_contents(__DIR__ . '/../Fixtures/Api/' . $file);

        self::assertIsString($csv, sprintf('Fixture %s is unreadable', $file));

        return (new ChapterCsvParser())->parse($csv);
    }

    /**
     * @return array<string, int>
     */
    private function propertyCounts(string $file): array
    {
        $parser = new QuantityCriterionParser();
        $counts = [];

        foreach ($this->chapter($file) as $line) {
            $criterion = $parser->parse($line->description);

            if ($criterion === null || !$criterion->hasKnownProperty()) {
                continue;
            }

            $property = (string) $criterion->property;
            $counts[$property] = ($counts[$property] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Chapter 22 is the strongest case for reading more than mass: 348 lines
     * branch on alcoholic strength, which a weight-only parser sees as nothing
     * at all.
     */
    public function testAlcoholicStrengthIsReadThroughoutChapterTwentyTwo(): void
    {
        $counts = $this->propertyCounts('chapter-22.csv');

        self::assertGreaterThan(300, $counts[MeasuredProperty::ALCOHOL_STRENGTH] ?? 0);
        self::assertGreaterThan(30, $counts[MeasuredProperty::VOLUME] ?? 0);
    }

    public function testFatContentIsReadThroughoutChapterFour(): void
    {
        $counts = $this->propertyCounts('chapter-04.csv');

        self::assertGreaterThan(25, $counts[MeasuredProperty::FAT_CONTENT] ?? 0);
        self::assertGreaterThan(15, $counts[MeasuredProperty::NET_WEIGHT] ?? 0);
    }

    public function testGarmentWeightIsStillReadInChapterSixtyTwo(): void
    {
        $counts = $this->propertyCounts('chapter-62.csv');

        self::assertGreaterThan(5, $counts[MeasuredProperty::NET_WEIGHT] ?? 0);
    }

    /**
     * Nothing in the apparel chapter should be read as alcoholic strength or
     * fat content. A parser that finds conditions everywhere is not reading
     * the tariff, it is pattern-matching noise.
     */
    public function testConditionsAreNotInventedWhereNoneExist(): void
    {
        $counts = $this->propertyCounts('chapter-62.csv');

        self::assertArrayNotHasKey(MeasuredProperty::ALCOHOL_STRENGTH, $counts);
        self::assertArrayNotHasKey(MeasuredProperty::FAT_CONTENT, $counts);
        self::assertArrayNotHasKey(MeasuredProperty::VOLUME, $counts);
    }

    /**
     * Most lines carry no condition at all, and should not.
     */
    public function testMostLinesCarryNoCondition(): void
    {
        $parser = new QuantityCriterionParser();
        $lines = $this->chapter('chapter-62.csv');

        $withCondition = 0;

        foreach ($lines as $line) {
            if ($parser->parse($line->description) !== null) {
                ++$withCondition;
            }
        }

        self::assertLessThan(count($lines) / 4, $withCondition);
    }

    // ------------------------------------------------------------ narrowing

    /**
     * The point of all of it: a merchant who records their product's alcoholic
     * strength gets the same help an apparel merchant gets from recording a
     * garment weight.
     */
    public function testAlcoholicStrengthNarrowsChapterTwentyTwo(): void
    {
        $resolver = new CommodityResolver(
            new InMemoryCommodityRepository($this->chapter('chapter-22.csv')),
        );

        $unmeasured = $resolver->resolve('2204');
        $measured = $resolver->resolve('2204', [MeasuredProperty::ALCOHOL_STRENGTH => 12.0]);

        self::assertGreaterThan(0, $unmeasured->candidateCount());
        self::assertLessThan(
            $unmeasured->candidateCount(),
            $measured->candidateCount(),
            'Recording alcoholic strength should reduce the candidate list',
        );
        self::assertTrue($measured->narrowedByMeasurement);
    }

    /**
     * Chapter 4 exercises the harder path: the threshold and the thing it
     * measures sit on different lines, so narrowing only works if the subject
     * is carried down from a parent.
     */
    public function testFatContentNarrowsChapterFour(): void
    {
        $resolver = new CommodityResolver(
            new InMemoryCommodityRepository($this->chapter('chapter-04.csv')),
        );

        $unmeasured = $resolver->resolve('0403');
        $measured = $resolver->resolve('0403', [MeasuredProperty::FAT_CONTENT => 0.5]);

        self::assertGreaterThan(0, $unmeasured->candidateCount());
        self::assertLessThan($unmeasured->candidateCount(), $measured->candidateCount());
        self::assertTrue($measured->narrowedByMeasurement);
    }

    /**
     * A measurement of the wrong kind narrows nothing rather than narrowing
     * wrongly.
     */
    public function testAnIrrelevantMeasurementDoesNotNarrow(): void
    {
        $resolver = new CommodityResolver(
            new InMemoryCommodityRepository($this->chapter('chapter-22.csv')),
        );

        $unmeasured = $resolver->resolve('2204');
        $irrelevant = $resolver->resolve('2204', [MeasuredProperty::FAT_CONTENT => 3.0]);

        self::assertSame($unmeasured->candidateCount(), $irrelevant->candidateCount());
        self::assertFalse($irrelevant->narrowedByMeasurement);
    }
}
