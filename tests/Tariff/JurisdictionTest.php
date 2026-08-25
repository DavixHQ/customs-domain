<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Tariff;

use Davix\Customs\Provider\FrozenClock;
use Davix\Customs\Provider\Hmrc\CommodityMapper;
use Davix\Customs\Provider\Hmrc\HmrcClient;
use Davix\Customs\Provider\Hmrc\HmrcClientOptions;
use Davix\Customs\Tariff\CommodityDetail;
use Davix\Customs\Tariff\InMemoryCommodityRepository;
use Davix\Customs\Tariff\Jurisdiction;
use Davix\Customs\Tests\Support\ArrayCache;
use Davix\Customs\Tests\Support\FakeHttpClient;
use Davix\Customs\Tests\Support\RecordingSleeper;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * Great Britain and Northern Ireland are separate tariffs with byte-identical
 * response shapes. Every test here exists because nothing about a reply
 * reveals which one answered, so the only defence is carrying the choice
 * explicitly and checking it.
 */
final class JurisdictionTest extends TestCase
{
    private function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/Api/' . $name);

        self::assertIsString($body, sprintf('Fixture %s is unreadable', $name));

        return $body;
    }

    private function detail(string $name): CommodityDetail
    {
        return (new CommodityMapper())->mapFromJson($this->fixture($name));
    }

    public function testBaseUrisDiffer(): void
    {
        self::assertStringEndsWith('/api/v2', Jurisdiction::Uk->baseUri());
        self::assertStringContainsString('/xi/', Jurisdiction::NorthernIreland->baseUri());
        self::assertNotSame(Jurisdiction::Uk->baseUri(), Jurisdiction::NorthernIreland->baseUri());
    }

    public function testAResponseReportsWhichTariffAnsweredIt(): void
    {
        self::assertSame(Jurisdiction::Uk, $this->detail('commodity-6201401019.json')->jurisdiction());
        self::assertSame(
            Jurisdiction::NorthernIreland,
            $this->detail('commodity-xi-6201401019.json')->jurisdiction(),
        );
    }

    /**
     * The same commodity carries a different measure set under each tariff.
     * That divergence is the reason the distinction matters and the reason a
     * misconfigured client is dangerous rather than merely untidy.
     */
    public function testTheSameCommodityDiffersBetweenJurisdictions(): void
    {
        $uk = $this->detail('commodity-6201401019.json');
        $xi = $this->detail('commodity-xi-6201401019.json');

        self::assertSame($uk->code(), $xi->code(), 'Same commodity code');
        self::assertNotSame(
            $uk->measures->count(),
            $xi->measures->count(),
            'Different measures, which is precisely why the tariffs are separate',
        );
    }

    /**
     * Identical structure is what makes a misconfiguration invisible. There is
     * no malformed field to notice, no missing relationship, nothing that
     * would fail a mapper - only a different answer.
     */
    public function testTheTwoResponsesAreStructurallyIdentical(): void
    {
        $uk = $this->detail('commodity-6201401019.json');
        $xi = $this->detail('commodity-xi-6201401019.json');

        self::assertSame($uk->commodity->sid, $xi->commodity->sid);
        self::assertSame($uk->commodity->productlineSuffix, $xi->commodity->productlineSuffix);
        self::assertSame($uk->isDeclarable(), $xi->isDeclarable());
        self::assertCount(count($uk->ancestors), $xi->ancestors);
    }

    public function testIsForChecksAgainstWhatWasExpected(): void
    {
        $xi = $this->detail('commodity-xi-6201401019.json');

        self::assertTrue($xi->isFor(Jurisdiction::NorthernIreland));
        self::assertFalse(
            $xi->isFor(Jurisdiction::Uk),
            'A host asking for the UK tariff and receiving XI should be able to tell',
        );
    }

    public function testSourceParsing(): void
    {
        self::assertSame(Jurisdiction::Uk, Jurisdiction::fromSource('uk'));
        self::assertSame(Jurisdiction::NorthernIreland, Jurisdiction::fromSource('XI'));
        self::assertNull(Jurisdiction::fromSource(null));
        self::assertNull(Jurisdiction::fromSource('something else'));
    }

    // --------------------------------------------------------------- client

    public function testOptionsForAJurisdictionUseItsBaseUri(): void
    {
        $options = HmrcClientOptions::for(Jurisdiction::NorthernIreland);

        self::assertSame(Jurisdiction::NorthernIreland->baseUri(), $options->baseUri);
        self::assertSame(Jurisdiction::NorthernIreland, $options->jurisdiction);
    }

    public function testOptionsForAJurisdictionKeepOtherSettings(): void
    {
        $template = new HmrcClientOptions(maxAttempts: 7, jitter: false, userAgent: 'test');
        $options = HmrcClientOptions::for(Jurisdiction::NorthernIreland, $template);

        self::assertSame(7, $options->maxAttempts);
        self::assertFalse($options->jitter);
        self::assertSame('test', $options->userAgent);
    }

    public function testTheClientRequestsTheJurisdictionsService(): void
    {
        $http = new FakeHttpClient([
            new Response(200, [], $this->fixture('commodity-xi-6201401019.json')),
        ]);

        $client = new HmrcClient(
            httpClient: $http,
            requestFactory: new Psr17Factory(),
            options: HmrcClientOptions::for(Jurisdiction::NorthernIreland),
            logger: new NullLogger(),
            clock: FrozenClock::at('2026-08-22'),
            sleeper: new RecordingSleeper(),
        );

        $detail = $client->commodity('6201401019');

        self::assertSame(Jurisdiction::NorthernIreland, $client->jurisdiction());
        self::assertStringContainsString('/xi/api/v2/commodities/', (string) $http->sent[0]->getUri());
        self::assertTrue($detail->isFor(Jurisdiction::NorthernIreland));
    }

    /**
     * Without the jurisdiction in the cache key, a store serving both
     * territories caches one commodity's UK answer and serves it for the XI
     * lookup - a wrong duty rate that no response would contradict.
     */
    public function testCacheKeysAreScopedByJurisdiction(): void
    {
        $cache = new ArrayCache();

        foreach ([Jurisdiction::Uk, Jurisdiction::NorthernIreland] as $jurisdiction) {
            $body = $jurisdiction === Jurisdiction::Uk
                ? $this->fixture('commodity-6201401019.json')
                : $this->fixture('commodity-xi-6201401019.json');

            $client = new HmrcClient(
                httpClient: new FakeHttpClient([new Response(200, [], $body)]),
                requestFactory: new Psr17Factory(),
                options: HmrcClientOptions::for($jurisdiction),
                cache: $cache,
                logger: new NullLogger(),
                clock: FrozenClock::at('2026-08-22'),
                sleeper: new RecordingSleeper(),
            );

            $client->commodity('6201401019');
        }

        self::assertCount(2, $cache->data, 'One entry per jurisdiction, not one shared');

        foreach (array_keys($cache->data) as $key) {
            self::assertMatchesRegularExpression('/\.(uk|xi)\./', (string) $key);
        }
    }

    // ----------------------------------------------------------- repository

    /**
     * A mirror declares which tariff it holds so a host can notice it is
     * reading a Great Britain mirror against a Northern Ireland client.
     */
    public function testAMirrorDeclaresItsJurisdiction(): void
    {
        self::assertSame(
            Jurisdiction::Uk,
            (new InMemoryCommodityRepository())->jurisdiction(),
            'Great Britain is the default, being the common case',
        );

        self::assertSame(
            Jurisdiction::NorthernIreland,
            (new InMemoryCommodityRepository([], Jurisdiction::NorthernIreland))->jurisdiction(),
        );
    }

    public function testAMismatchIsDetectable(): void
    {
        $mirror = new InMemoryCommodityRepository([], Jurisdiction::Uk);

        $client = new HmrcClient(
            httpClient: new FakeHttpClient([]),
            requestFactory: new Psr17Factory(),
            options: HmrcClientOptions::for(Jurisdiction::NorthernIreland),
            logger: new NullLogger(),
            clock: FrozenClock::at('2026-08-22'),
            sleeper: new RecordingSleeper(),
        );

        self::assertNotSame(
            $mirror->jurisdiction(),
            $client->jurisdiction(),
            'A host can compare these before scanning rather than discovering it in the data',
        );
    }
}
