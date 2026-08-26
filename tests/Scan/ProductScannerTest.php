<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Scan;

use Davix\Customs\Exception\TariffUnavailableException;
use Davix\Customs\Product\ProductCustomsData;
use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Provider\FrozenClock;
use Davix\Customs\Provider\Hmrc\ChapterCsvParser;
use Davix\Customs\Rule\DefaultRuleSet;
use Davix\Customs\Rule\RuleSettings;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Scan\ProductScanner;
use Davix\Customs\Scan\ScanOptions;
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\InMemoryCommodityRepository;
use Davix\Customs\Tests\Support\RecordingProvider;
use PHPUnit\Framework\TestCase;

final class ProductScannerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/Api';

    private function resolver(): CommodityResolver
    {
        $csv = file_get_contents(self::FIXTURES . '/chapter-62.csv');

        self::assertIsString($csv);

        return new CommodityResolver(
            new InMemoryCommodityRepository((new ChapterCsvParser())->parse($csv)),
        );
    }

    private function scanner(
        RecordingProvider $provider,
        ?ScanOptions $options = null,
    ): ProductScanner {
        return new ProductScanner(
            rules: DefaultRuleSet::fullPool(),
            resolver: $this->resolver(),
            provider: $provider,
            settings: new RuleSettings(),
            options: $options ?? new ScanOptions(),
            clock: FrozenClock::at('2026-08-22'),
        );
    }

    private function provider(?TariffUnavailableException $failure = null): RecordingProvider
    {
        return new RecordingProvider(self::FIXTURES, $failure);
    }

    private function product(
        int $index,
        string $code = '6201301011',
        ?string $origin = 'VN',
    ): ProductCustomsDataInterface {
        return ProductCustomsData::fromRawCode(
            identifier: (string) $index,
            sku: sprintf('SKU-%d', $index),
            name: 'Mountain Parka',
            rawHsCode: $code,
            countryOfOrigin: $origin,
            customsDescription: "Men's cotton parka, hooded",
            netWeight: 1.2,
            grossWeight: 1.4,
            composition: '100% cotton',
        );
    }

    /**
     * @param iterable<ProductCustomsDataInterface> $products
     * @return list<\Davix\Customs\Scan\ProductScanResult>
     */
    private function collect(ProductScanner $scanner, iterable $products): array
    {
        $results = [];

        foreach ($scanner->scan($products) as $result) {
            $results[] = $result;
        }

        return $results;
    }

    // --------------------------------------------------------- deduplication

    /**
     * The reason this class exists.
     *
     * Deduplication is invisible in a scan's output - the findings are
     * identical either way - and shows up only as the difference between a
     * scan finishing in two minutes and taking forty. Counting the calls is
     * the only way to notice it has regressed.
     */
    public function testLookupsAreMadePerCommodityCodeNotPerProduct(): void
    {
        $provider = $this->provider();

        $products = array_map(fn (int $i): ProductCustomsDataInterface => $this->product($i), range(1, 200));

        $scanner = $this->scanner($provider);

        self::assertCount(200, $this->collect($scanner, $products));
        self::assertSame(200, $scanner->summary()->products());
        self::assertSame(1, $provider->callsTo('commodity'), '200 products, one distinct code');
        self::assertSame(1, $provider->callsTo('quotas'));
        self::assertSame(1, $provider->callsTo('certificates'), 'Fetched once for the whole scan');
    }

    /**
     * Keyed on the resolved commodity rather than what the merchant typed, so
     * a catalogue written inconsistently still shares one lookup.
     */
    public function testDifferentSpellingsOfOneCodeShareALookup(): void
    {
        $provider = $this->provider();

        $products = [
            $this->product(1, '6201301011'),
            $this->product(2, '6201.30.10.11'),
            $this->product(3, '6201 30 10 11'),
        ];

        $this->collect($this->scanner($provider), $products);

        self::assertSame(1, $provider->callsTo('commodity'));
        self::assertCount(1, array_unique($provider->commodityCodesRequested));
    }

    public function testTheSummaryReportsWhatTheScanCost(): void
    {
        $provider = $this->provider();
        $scanner = $this->scanner($provider);

        $this->collect($scanner, array_map(
            fn (int $i): ProductCustomsDataInterface => $this->product($i),
            range(1, 50),
        ));

        $summary = $scanner->summary();

        self::assertSame(50, $summary->products());
        self::assertSame(1, $summary->distinctCodes());
        self::assertGreaterThan(0, $summary->callsSaved());
    }

    // -------------------------------------------------------------- ordering

    /**
     * Measures are fetched only where they can add something. An ambiguous
     * code spans several classifications at once, so there is no single
     * measure set to fetch.
     */
    public function testMeasuresAreNotFetchedForAnAmbiguousCode(): void
    {
        $provider = $this->provider();

        $this->collect($this->scanner($provider), [$this->product(1, '620130')]);

        self::assertSame(0, $provider->callsTo('commodity'));
    }

    public function testMeasuresAreNotFetchedForACodeMissingFromTheMirror(): void
    {
        $provider = $this->provider();

        $this->collect($this->scanner($provider), [$this->product(1, '6299999999')]);

        self::assertSame(0, $provider->callsTo('commodity'));
    }

    /**
     * A national chapter is not a missing code. Chapters 98 and 99 are real
     * but sit outside the standard nomenclature the sync pulls, so spending a
     * historic lookup to confirm the mirror never held them is waste.
     */
    public function testANationalChapterDoesNotTriggerAHistoricLookup(): void
    {
        $provider = $this->provider();

        $this->collect($this->scanner($provider), [$this->product(1, '9901000000')]);

        self::assertSame(0, $provider->callsTo('historic'));
        self::assertSame(0, $provider->callsTo('commodity'));
    }

    /**
     * A missing code is exactly where a historic lookup earns its call: it is
     * the difference between "withdrawn on 1 January 2022" and "not found".
     */
    public function testAMissingCodeTriggersAHistoricLookup(): void
    {
        $provider = $this->provider();

        $results = $this->collect($this->scanner($provider), [$this->product(1, '6299999999')]);

        self::assertSame(1, $provider->callsTo('historic'));
        self::assertTrue($results[0]->evaluation->has('withdrawn_code'));
    }

    public function testHistoricLookupsAreAlsoDeduplicated(): void
    {
        $provider = $this->provider();

        $products = array_map(
            fn (int $i): ProductCustomsDataInterface => $this->product($i, '6299999999'),
            range(1, 30),
        );

        $this->collect($this->scanner($provider), $products);

        self::assertSame(1, $provider->callsTo('historic'));
    }

    // --------------------------------------------------------------- offline

    public function testAnOfflineScanMakesNoNetworkCalls(): void
    {
        $provider = $this->provider();

        $results = $this->collect(
            $this->scanner($provider, ScanOptions::offline()),
            array_map(fn (int $i): ProductCustomsDataInterface => $this->product($i), range(1, 20)),
        );

        self::assertSame(0, $provider->totalCalls());
        self::assertCount(20, $results);

        foreach ($results as $result) {
            self::assertFalse($result->measuresFetched);
            self::assertFalse($result->isIncomplete(), 'Not fetching is not a failure');
        }
    }

    public function testAScannerWithNoProviderStillRunsTheOfflineRules(): void
    {
        $scanner = new ProductScanner(
            rules: DefaultRuleSet::fullPool(),
            resolver: $this->resolver(),
            provider: null,
            clock: FrozenClock::at('2026-08-22'),
        );

        $results = $this->collect($scanner, [$this->product(1, origin: null)]);

        self::assertTrue($results[0]->evaluation->has('missing_origin'));
        self::assertFalse($results[0]->measuresFetched);
    }

    // --------------------------------------------------------------- failure

    /**
     * An outage midway through a catalogue must not discard the work already
     * done, nor report unchecked products as clean.
     */
    public function testOfflineFindingsSurviveAProviderOutage(): void
    {
        $provider = $this->provider(new TariffUnavailableException('unavailable', 503, true));

        $scanner = $this->scanner($provider, new ScanOptions(maxProviderFailures: 0));

        $results = $this->collect($scanner, [
            ProductCustomsData::fromRawCode(
                identifier: '1',
                sku: 'SKU-1',
                name: 'Parka',
                rawHsCode: '6201301011',
                countryOfOrigin: null,
                customsDescription: 'Parka',
            ),
        ]);

        $result = $results[0];

        self::assertTrue($result->isIncomplete(), 'The product is partly unchecked, not clean');
        self::assertTrue($result->evaluation->has('missing_origin'));
        self::assertTrue($result->evaluation->has('description_is_product_name'));
        self::assertNotNull($result->providerFailure);
    }

    /**
     * Pressing on through a hundred consecutive failures produces a
     * catalogue-wide report built on nothing, which is worse than stopping.
     */
    public function testAScanAbandonsItselfAfterRepeatedFailures(): void
    {
        $provider = $this->provider(new TariffUnavailableException('unavailable', 503, true));
        $scanner = $this->scanner($provider, new ScanOptions(maxProviderFailures: 5));

        $this->expectException(TariffUnavailableException::class);

        $this->collect($scanner, array_map(
            fn (int $i): ProductCustomsDataInterface => $this->product($i, '6299999999'),
            range(1, 50),
        ));
    }

    public function testTheFailureLimitCanBeDisabled(): void
    {
        $provider = $this->provider(new TariffUnavailableException('unavailable', 503, true));
        $scanner = $this->scanner($provider, new ScanOptions(maxProviderFailures: 0));

        $results = $this->collect($scanner, array_map(
            fn (int $i): ProductCustomsDataInterface => $this->product($i, '6299999999'),
            range(1, 30),
        ));

        self::assertCount(30, $results);
        self::assertSame(30, $scanner->summary()->incomplete());
    }

    // --------------------------------------------------------------- results

    public function testACleanProductIsReportedAsClean(): void
    {
        $scanner = $this->scanner($this->provider(), ScanOptions::offline());

        $results = $this->collect($scanner, [$this->product(1)]);

        self::assertFalse($results[0]->hasIssues());
        self::assertFalse($results[0]->isBlocked());
        self::assertNull($results[0]->highestSeverity());
    }

    /**
     * Products and issues are different numbers, and a dashboard conflating
     * them will not add up.
     */
    public function testProductsAndIssuesAreCountedSeparately(): void
    {
        $scanner = $this->scanner($this->provider(), ScanOptions::offline());

        $this->collect($scanner, [
            ProductCustomsData::fromRawCode('1', 'SKU-1', 'Parka', '6201301011', countryOfOrigin: null),
        ]);

        $summary = $scanner->summary();

        self::assertSame(1, $summary->products());
        self::assertSame(1, $summary->productsWithIssues());
        self::assertGreaterThan(1, $summary->issues(), 'One product, several issues');
        self::assertGreaterThan(0, $summary->countOfSeverity(Severity::Attention));
    }

    public function testTheSummaryIsReadableDuringIteration(): void
    {
        $scanner = $this->scanner($this->provider(), ScanOptions::offline());
        $products = array_map(fn (int $i): ProductCustomsDataInterface => $this->product($i), range(1, 10));

        $seen = 0;

        foreach ($scanner->scan($products) as $result) {
            ++$seen;

            self::assertSame(
                $seen,
                $scanner->summary()->products(),
                'A host should be able to report progress without counting separately',
            );
        }
    }

    /**
     * A generator, so a host can stop early without scanning the rest.
     */
    public function testAScanCanBeStoppedEarly(): void
    {
        $provider = $this->provider();
        $scanner = $this->scanner($provider);

        $products = array_map(
            fn (int $i): ProductCustomsDataInterface => $this->product($i),
            range(1, 1000),
        );

        $seen = 0;

        foreach ($scanner->scan($products) as $result) {
            if (++$seen === 5) {
                break;
            }
        }

        self::assertSame(5, $scanner->summary()->products());
    }
}
