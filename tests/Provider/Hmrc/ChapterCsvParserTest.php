<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Provider\Hmrc;

use Davix\Customs\Exception\TariffParseException;
use Davix\Customs\Provider\Hmrc\ChapterCsvParser;
use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\InMemoryCommodityRepository;
use Davix\Customs\Tariff\MeasuredProperty;
use PHPUnit\Framework\TestCase;

/**
 * Parses the recorded chapter 62 response. See tests/Fixtures/Api/README.md.
 */
final class ChapterCsvParserTest extends TestCase
{
    private ChapterCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ChapterCsvParser();
    }

    private function chapterCsv(): string
    {
        $csv = file_get_contents(__DIR__ . '/../../Fixtures/Api/chapter-62.csv');

        self::assertIsString($csv, 'Chapter fixture is unreadable');

        return $csv;
    }

    /**
     * @return list<Commodity>
     */
    private function chapter(): array
    {
        return $this->parser->parse($this->chapterCsv());
    }

    public function testTheWholeChapterParses(): void
    {
        self::assertCount(464, $this->chapter());
    }

    public function testTheTreeIsFullyLinked(): void
    {
        $lines = $this->chapter();

        $sids = [];
        foreach ($lines as $line) {
            $sids[$line->sid] = true;
        }

        $roots = 0;
        $orphans = 0;

        foreach ($lines as $line) {
            if ($line->parentSid === null) {
                ++$roots;
            } elseif (!isset($sids[$line->parentSid])) {
                ++$orphans;
            }
        }

        self::assertSame(1, $roots, 'A chapter has exactly one root');
        self::assertSame(0, $orphans, 'Every parent reference resolves within the chapter');
    }

    public function testTheRootIsTheChapterItself(): void
    {
        $root = null;

        foreach ($this->chapter() as $line) {
            if ($line->isRoot()) {
                $root = $line;
            }
        }

        self::assertInstanceOf(Commodity::class, $root);
        self::assertSame(43115, $root->sid);
        self::assertSame('6200000000', $root->code);
        self::assertFalse($root->isDeclarable());
    }

    public function testColumnsAreMapped(): void
    {
        $lines = $this->chapter();
        $first = $lines[0];

        self::assertSame(43115, $first->sid);
        self::assertSame('6200000000', $first->code);
        self::assertSame('80', $first->productlineSuffix);
        self::assertSame(0, $first->numberIndents);
        self::assertSame('/uk/api/chapters/62', $first->href);
        self::assertStringContainsString('ARTICLES OF APPAREL', $first->description);
    }

    /**
     * Dates arrive as "1972-01-01 00:00:00 UTC", which is not ISO 8601.
     */
    public function testTheNonIsoDateFormatIsParsed(): void
    {
        $lines = $this->chapter();

        self::assertSame('1971-12-31', $lines[0]->validityStart?->format('Y-m-d'));
    }

    /**
     * Filtering by `as_of` returns only lines valid on that date, so every end
     * date is empty. A null end means "in force", never "unknown" — which is
     * exactly why a withdrawn code needs a separate historic lookup rather
     * than an end-date check.
     */
    public function testEveryEndDateIsEmptyInAFilteredPull(): void
    {
        foreach ($this->chapter() as $line) {
            self::assertNull($line->validityEnd);
        }
    }

    public function testDeclarabilityIsRead(): void
    {
        $declarable = array_filter(
            $this->chapter(),
            static fn (Commodity $c): bool => $c->isDeclarable(),
        );

        self::assertCount(276, $declarable);
    }

    /**
     * Live chapter 62 carries three productline suffixes, not two. Code
     * 6203491100 appears at 10, 20 and 80 — "Of artificial fibres", then
     * "Trousers and breeches", then the declarable line. Treating 10 as the
     * only grouping suffix reads the middle one as declarable.
     */
    public function testThreeProductlineSuffixesArePresent(): void
    {
        $counts = [];

        foreach ($this->chapter() as $line) {
            $counts[$line->productlineSuffix] = ($counts[$line->productlineSuffix] ?? 0) + 1;
        }

        ksort($counts);

        self::assertSame(['10' => 66, '20' => 2, '80' => 396], $counts);
    }

    public function testASecondLevelGroupingIsNotMistakenForDeclarable(): void
    {
        $chain = array_values(array_filter(
            $this->chapter(),
            static fn (Commodity $c): bool => $c->code === '6203491100',
        ));

        self::assertCount(3, $chain);

        foreach ($chain as $line) {
            if ($line->productlineSuffix === Commodity::SUFFIX_DECLARABLE) {
                self::assertTrue($line->isDeclarable());
                self::assertFalse($line->isIntermediate());
            } else {
                self::assertFalse($line->isDeclarable());
                self::assertTrue($line->isIntermediate());
            }
        }
    }

    /**
     * 66 codes in chapter 62 appear on more than one line. Keying storage on
     * the code rather than the SID would lose one of each pair.
     */
    public function testCommodityCodesAreNotUnique(): void
    {
        $byCode = [];

        foreach ($this->chapter() as $line) {
            $byCode[$line->code] = ($byCode[$line->code] ?? 0) + 1;
        }

        $shared = array_filter($byCode, static fn (int $n): bool => $n > 1);

        self::assertCount(66, $shared);
        self::assertSame(396, count($byCode), 'Fewer distinct codes than lines');
    }

    // ---------------------------------------------------------- end to end

    /**
     * The check that would have caught the two bugs real data exposed: parse
     * a whole live chapter, load it, and resolve through it.
     */
    public function testResolvingThroughAWholeParsedChapter(): void
    {
        $repository = new InMemoryCommodityRepository($this->chapter());
        $resolver = new CommodityResolver($repository);

        $unweighted = $resolver->resolve('620130');
        $light = $resolver->resolve('620130', [MeasuredProperty::NET_WEIGHT => 0.5]);
        $heavy = $resolver->resolve('620130', [MeasuredProperty::NET_WEIGHT => 1.5]);

        self::assertSame(8, $unweighted->candidateCount());
        self::assertSame(4, $light->candidateCount());
        self::assertSame(4, $heavy->candidateCount());
        self::assertTrue($light->narrowedByMeasurement);
        self::assertTrue($heavy->narrowedByMeasurement);
    }

    /**
     * The tariff separates a quantity from its unit with a non-breaking space:
     * the live bytes for "1 kg" are 31 c2 a0 6b 67. PCRE's \s does not match
     * U+00A0 outside Unicode mode, so without normalisation every weight
     * condition in the nomenclature reads as none and narrowing does nothing.
     */
    public function testWeightDescriptionsContainNonBreakingSpaces(): void
    {
        $weightLines = array_values(array_filter(
            $this->chapter(),
            static fn (Commodity $c): bool => str_contains($c->description, 'Of a weight'),
        ));

        self::assertNotEmpty($weightLines);
        self::assertStringContainsString(
            "\u{00A0}",
            $weightLines[0]->description,
            'A plain space here would mean the fixture no longer reflects the API',
        );
    }

    public function testTheDuplicateCodePairResolvesToItsDeclarableLine(): void
    {
        $resolver = new CommodityResolver(new InMemoryCommodityRepository($this->chapter()));

        $resolution = $resolver->resolve('6201200011');

        self::assertTrue($resolution->isResolved());
        self::assertSame(106845, $resolution->commodity?->sid);
    }

    // -------------------------------------------------------------- streaming

    public function testStreamingYieldsTheSameLines(): void
    {
        $streamed = iterator_to_array($this->parser->stream($this->chapterCsv()), false);

        self::assertCount(464, $streamed);
        self::assertSame(43115, $streamed[0]->sid);
    }

    // --------------------------------------------------------------- failures

    /**
     * An empty response must fail rather than parse as an empty chapter. A
     * sync that treats it as success wipes every line the mirror held.
     */
    public function testAnEmptyResponseThrows(): void
    {
        $this->expectException(TariffParseException::class);

        $this->parser->parse('');
    }

    public function testAResponseMissingRequiredColumnsThrows(): void
    {
        $this->expectException(TariffParseException::class);
        $this->expectExceptionMessage('Product Line Suffix');

        $this->parser->parse("SID,Goods Nomenclature Item ID,Description\n1,6201000000,Coats\n");
    }

    /**
     * One malformed line should not cost a whole chapter.
     */
    public function testRowsWithoutAnIdentityAreSkippedRatherThanFailing(): void
    {
        $csv = "SID,Goods Nomenclature Item ID,Indents,Description,Product Line Suffix,"
            . "Href,Formatted description,Start date,End date,Declarable,Parent SID\n"
            . "43115,6200000000,0,Chapter,80,/uk,Chapter,1971-12-31 00:00:00 UTC,,false,\n"
            . ",,,,,,,,,,\n"
            . "43116,6201000000,0,Heading,80,/uk,Heading,1972-01-01 00:00:00 UTC,,false,43115\n";

        $lines = $this->parser->parse($csv);

        self::assertCount(2, $lines);
    }

    public function testAByteOrderMarkOnTheHeaderIsTolerated(): void
    {
        $csv = "\u{FEFF}SID,Goods Nomenclature Item ID,Indents,Description,Product Line Suffix,"
            . "Href,Formatted description,Start date,End date,Declarable,Parent SID\n"
            . "43115,6200000000,0,Chapter,80,/uk,Chapter,1971-12-31 00:00:00 UTC,,true,\n";

        $lines = $this->parser->parse($csv);

        self::assertCount(1, $lines);
        self::assertSame(43115, $lines[0]->sid);
    }
}
