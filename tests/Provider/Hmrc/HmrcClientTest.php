<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Provider\Hmrc;

use Davix\Customs\Exception\TariffUnavailableException;
use Davix\Customs\Provider\FrozenClock;
use Davix\Customs\Provider\Hmrc\HmrcClient;
use Davix\Customs\Provider\Hmrc\HmrcClientOptions;
use Davix\Customs\Tests\Support\ArrayCache;
use Davix\Customs\Tests\Support\BrokenCache;
use Davix\Customs\Tests\Support\FakeHttpClient;
use Davix\Customs\Tests\Support\RecordingSleeper;
use Davix\Customs\Tests\Support\TransportFailure;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use PHPUnit\Framework\TestCase;

final class HmrcClientTest extends TestCase
{
    private FakeHttpClient $http;
    private RecordingSleeper $sleeper;

    /**
     * Real PSR-7 objects rather than hand-written doubles. The message
     * interfaces carry around fifty methods between them, and a partial
     * implementation is a liability that passes until the day something
     * touches the part nobody wrote.
     */
    private Psr17Factory $factory;

    /**
     * @return list<string>
     */
    private function requestedUrls(): array
    {
        return array_map(
            static fn (RequestInterface $request): string => (string) $request->getUri(),
            $this->http->sent,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(int $status, string $body = '', array $headers = []): ResponseInterface
    {
        return new Response($status, $headers, $body);
    }

    /**
     * Collect an iterable that may be either a generator or an array.
     *
     * TariffProviderInterface::chapter() returns iterable deliberately: an
     * implementation that already holds the lines should be free to hand back
     * an array. iterator_to_array() only accepts Traversable on PHP 8.1 — the
     * widening to iterable arrived in 8.2 — so the test normalises rather than
     * narrowing the contract to suit one runtime.
     *
     * @template T
     * @param iterable<T> $lines
     * @return list<T>
     */
    private function collect(iterable $lines): array
    {
        $collected = [];

        foreach ($lines as $line) {
            $collected[] = $line;
        }

        return $collected;
    }

    private function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../../Fixtures/Api/' . $name);

        self::assertIsString($body, sprintf('Fixture %s is unreadable', $name));

        return $body;
    }

    /**
     * @param list<ResponseInterface|\Throwable> $responses
     */
    private function client(
        array $responses,
        ?CacheInterface $cache = null,
        ?HmrcClientOptions $options = null,
    ): HmrcClient {
        $this->http = new FakeHttpClient($responses);
        $this->factory = new Psr17Factory();
        $this->sleeper = new RecordingSleeper();

        return new HmrcClient(
            httpClient: $this->http,
            requestFactory: $this->factory,
            // Jitter off so backoff is assertable; on by default in production,
            // where a fleet retrying in lockstep is the problem it solves.
            options: $options ?? new HmrcClientOptions(
                maxAttempts: 3,
                baseDelaySeconds: 1.0,
                jitter: false,
            ),
            cache: $cache,
            logger: new NullLogger(),
            clock: FrozenClock::at('2026-08-22'),
            sleeper: $this->sleeper,
        );
    }

    // ---------------------------------------------------------------- as_of

    /**
     * The tariff is a function of a date. A lookup without one gives an answer
     * that changes underneath you.
     */
    public function testEveryLookupCarriesAnAsOfDate(): void
    {
        $client = $this->client([$this->response(200, $this->fixture('commodity-6201401019.json'))]);

        $client->commodity('6201401019');

        self::assertStringContainsString('as_of=2026-08-22', $this->requestedUrls()[0]);
        self::assertStringContainsString('/commodities/6201401019', $this->requestedUrls()[0]);
    }

    public function testAnExplicitDateOverridesTheClock(): void
    {
        $client = $this->client([$this->response(200, $this->fixture('commodity-6201401019.json'))]);

        $client->commodity('6201401019', new DateTimeImmutable('2021-12-31'));

        self::assertStringContainsString('as_of=2021-12-31', $this->requestedUrls()[0]);
    }

    public function testCommodityResponsesAreMapped(): void
    {
        $client = $this->client([$this->response(200, $this->fixture('commodity-6201401019.json'))]);

        $detail = $client->commodity('6201401019');

        self::assertSame('6201401019', $detail->code());
        self::assertSame(74, $detail->measures->count());
        self::assertSame(
            'application/vnd.hmrc.2.0+json',
            $this->http->sent[0]->getHeaderLine('Accept'),
        );
    }

    // -------------------------------------------------------------- chapters

    public function testChaptersAreRequestedAsCsvAndParsed(): void
    {
        $client = $this->client([$this->response(200, $this->fixture('chapter-62.csv'))]);

        $lines = $this->collect($client->chapter('62'));

        self::assertCount(464, $lines);
        self::assertStringContainsString('/goods_nomenclatures/chapter/62.csv', $this->requestedUrls()[0]);
        self::assertSame('text/csv', $this->http->sent[0]->getHeaderLine('Accept'));
    }

    /**
     * The sync hashes raw responses to skip chapters that have not changed,
     * which it can only do if it can see the bytes.
     */
    public function testRawChaptersAreAvailableForHashing(): void
    {
        $csv = $this->fixture('chapter-62.csv');
        $client = $this->client([$this->response(200, $csv)]);

        self::assertSame($csv, $client->rawChapter('62'));
    }

    // --------------------------------------------------------------- changes

    public function testChangesAreMapped(): void
    {
        $client = $this->client([$this->response(200, $this->fixture('changes.json'))]);

        $changes = $client->changes(new DateTimeImmutable('2026-08-21'));

        self::assertCount(4, $changes);
        self::assertSame(83810, $changes[0]->goodsNomenclatureSid);
        self::assertSame('7306307780', $changes[0]->code);
        self::assertTrue($changes[0]->isMeasureChange());
        self::assertTrue($changes[0]->isDeclarableLine());
        self::assertSame('2026-08-21', $changes[0]->changedOn?->format('Y-m-d'));
        self::assertStringContainsString('/changes/2026-08-21', $this->requestedUrls()[0]);
    }

    // ----------------------------------------------------------------- retry

    public function testTransientFailuresAreRetriedWithExponentialBackoff(): void
    {
        $client = $this->client([
            $this->response(503),
            $this->response(503),
            $this->response(200, $this->fixture('commodity-6201401019.json')),
        ]);

        $detail = $client->commodity('6201401019');

        self::assertSame('6201401019', $detail->code());
        self::assertSame(3, $this->http->attempts());
        self::assertSame([1.0, 2.0], $this->sleeper->slept);
    }

    /**
     * A Retry-After header is the service saying exactly when it will be
     * ready. Substituting our own arithmetic is both ruder and less effective.
     */
    public function testRetryAfterIsPreferredOverComputedBackoff(): void
    {
        $client = $this->client([
            $this->response(429, '', ['Retry-After' => '7']),
            $this->response(200, $this->fixture('commodity-6201401019.json')),
        ]);

        $client->commodity('6201401019');

        self::assertSame([7.0], $this->sleeper->slept);
    }

    public function testRetryAfterIsCappedByTheConfiguredMaximum(): void
    {
        $client = $this->client(
            [
                $this->response(429, '', ['Retry-After' => '3600']),
                $this->response(200, $this->fixture('commodity-6201401019.json')),
            ],
            null,
            new HmrcClientOptions(maxAttempts: 2, maxDelaySeconds: 30.0, jitter: false),
        );

        $client->commodity('6201401019');

        self::assertSame([30.0], $this->sleeper->slept);
    }

    /**
     * A 404 means the code does not exist. Asking four more times will not
     * change that, and doing so wastes a scan's budget on a settled answer.
     */
    public function testNotFoundIsNotRetried(): void
    {
        $client = $this->client([$this->response(404)]);

        try {
            $client->commodity('9999999999');
            self::fail('Expected a TariffUnavailableException');
        } catch (TariffUnavailableException $e) {
            self::assertFalse($e->retryable);
            self::assertSame(404, $e->statusCode);
        }

        self::assertSame(1, $this->http->attempts());
        self::assertSame([], $this->sleeper->slept);
    }

    public function testRetriesStopAtTheConfiguredLimit(): void
    {
        $client = $this->client([
            $this->response(503),
            $this->response(503),
            $this->response(503),
        ]);

        $this->expectException(TariffUnavailableException::class);

        try {
            $client->commodity('6201401019');
        } finally {
            self::assertSame(3, $this->http->attempts());
        }
    }

    public function testTransportFailuresAreRetried(): void
    {
        $client = $this->client([
            new TransportFailure('connection refused'),
            $this->response(200, $this->fixture('commodity-6201401019.json')),
        ]);

        self::assertSame('6201401019', $client->commodity('6201401019')->code());
        self::assertSame(2, $this->http->attempts());
    }

    public function testRetriesCanBeDisabledEntirely(): void
    {
        $client = $this->client(
            [$this->response(500)],
            null,
            new HmrcClientOptions(maxAttempts: 1),
        );

        try {
            $client->commodity('6201401019');
            self::fail('Expected a TariffUnavailableException');
        } catch (TariffUnavailableException) {
            self::assertSame(1, $this->http->attempts());
        }
    }

    // --------------------------------------------------------------- caching

    public function testResponsesAreCached(): void
    {
        $cache = new ArrayCache();

        $first = $this->client([$this->response(200, $this->fixture('commodity-6201401019.json'))], $cache);
        $first->commodity('6201401019');

        self::assertSame(1, $cache->writes);

        $second = $this->client([], $cache);
        $detail = $second->commodity('6201401019');

        self::assertSame(0, $this->http->attempts(), 'Second lookup should not hit the network');
        self::assertSame('6201401019', $detail->code());
    }

    /**
     * The date belongs in the key. Without it, a scan pinned to a historic
     * date would be served today's answer.
     */
    public function testCacheKeysAreScopedByDate(): void
    {
        $cache = new ArrayCache();
        $client = $this->client([$this->response(200, $this->fixture('commodity-6201401019.json'))], $cache);

        $client->commodity('6201401019');

        $keys = array_keys($cache->data);

        self::assertCount(1, $keys);
        self::assertStringContainsString('2026-08-22', $keys[0]);
        self::assertStringContainsString('6201401019', $keys[0]);
    }

    /**
     * Losing a cache is an optimisation problem. Failing the scan over it is
     * an outage.
     */
    public function testABrokenCacheDoesNotStopTheRequest(): void
    {
        $client = $this->client(
            [$this->response(200, $this->fixture('commodity-6201401019.json'))],
            new BrokenCache(),
        );

        self::assertSame('6201401019', $client->commodity('6201401019')->code());
    }

    public function testCachingCanBeDisabledByTtl(): void
    {
        $cache = new ArrayCache();
        $client = $this->client(
            [$this->response(200, $this->fixture('commodity-6201401019.json'))],
            $cache,
            new HmrcClientOptions(commodityCacheTtl: 0),
        );

        $client->commodity('6201401019');

        self::assertSame(0, $cache->writes);
    }

    // ---------------------------------------------------------- availability

    public function testAvailabilityIsReportedRatherThanThrown(): void
    {
        self::assertTrue($this->client([$this->response(200, '{}')])->isAvailable());

        $failing = $this->client([
            $this->response(503),
            $this->response(503),
            $this->response(503),
        ]);

        self::assertFalse($failing->isAvailable());
    }

    // ------------------------------------------------------------- malformed

    public function testAnUnreadableBodyIsNotRetried(): void
    {
        $client = $this->client([$this->response(200, 'not json at all')]);

        try {
            $client->commodity('6201401019');
            self::fail('Expected a TariffUnavailableException');
        } catch (TariffUnavailableException $e) {
            self::assertFalse(
                $e->retryable,
                'A well-formed HTTP response with a broken body will not fix itself',
            );
        }
    }

    // --------------------------------------------------------------- options

    public function testBackoffDoublesAndIsCapped(): void
    {
        $options = new HmrcClientOptions(baseDelaySeconds: 2.0, maxDelaySeconds: 10.0);

        self::assertSame(2.0, $options->delayForAttempt(1));
        self::assertSame(4.0, $options->delayForAttempt(2));
        self::assertSame(8.0, $options->delayForAttempt(3));
        self::assertSame(10.0, $options->delayForAttempt(4));
        self::assertSame(10.0, $options->delayForAttempt(9));
    }

    public function testBaseUriCanBeOverridden(): void
    {
        $client = $this->client(
            [$this->response(200, $this->fixture('commodity-6201401019.json'))],
            null,
            (new HmrcClientOptions())->withBaseUri('https://example.test/api/'),
        );

        $client->commodity('6201401019');

        self::assertStringStartsWith(
            'https://example.test/api/commodities/',
            $this->requestedUrls()[0],
        );
    }
}