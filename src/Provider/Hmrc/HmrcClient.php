<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

use Davix\Customs\Exception\TariffUnavailableException;
use Davix\Customs\Provider\Clock;
use Davix\Customs\Provider\Sleeper;
use Davix\Customs\Provider\SystemClock;
use Davix\Customs\Provider\SystemSleeper;
use Davix\Customs\Provider\TariffProviderInterface;
use Davix\Customs\Tariff\ChangeRecord;
use Davix\Customs\Tariff\CommodityDetail;
use DateTimeImmutable;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Reads the UK Trade Tariff over HTTP.
 *
 * Takes PSR interfaces throughout and constructs nothing: the host supplies
 * the HTTP client, the cache, the logger and the clock. That is what lets this
 * package sit under Magento, WordPress or a plain CLI tool without carrying
 * any of their infrastructure.
 *
 * Three behaviours matter more than the plumbing.
 *
 * Every request carries `as_of`. The tariff is a function of a date, and a
 * lookup without one gives an answer that changes underneath you.
 *
 * Failures are separated by whether retrying could help. A 404 means the code
 * does not exist and asking again will not change that; a 429 or a 503 means
 * ask later. A scan that cannot tell the difference either gives up on
 * transient problems or hammers a service that has asked it to stop.
 *
 * Retries back off exponentially, honour a `Retry-After` header when the
 * service sends one, and jitter by default. Without jitter every store running
 * the same nightly cron retries in lockstep and causes the next rate limit
 * itself.
 */
final class HmrcClient implements TariffProviderInterface
{
    private const CACHE_PREFIX = 'davix.customs.tariff.';
    private const ACCEPT_JSON = 'application/vnd.hmrc.2.0+json';
    private const ACCEPT_CSV = 'text/csv';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly HmrcClientOptions $options = new HmrcClientOptions(),
        private readonly ?CacheInterface $cache = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly Clock $clock = new SystemClock(),
        private readonly Sleeper $sleeper = new SystemSleeper(),
        private readonly CommodityMapper $commodityMapper = new CommodityMapper(),
        private readonly ChapterCsvParser $chapterParser = new ChapterCsvParser(),
        private readonly ChangesMapper $changesMapper = new ChangesMapper(),
    ) {
    }

    public function commodity(string $code, ?DateTimeImmutable $asOf = null): CommodityDetail
    {
        $date = $this->dateFor($asOf);
        $url = $this->url('/commodities/' . rawurlencode($code), $date);
        $cacheKey = $this->cacheKey('commodity', $code, $date);

        $body = $this->cached($cacheKey, $this->options->commodityCacheTtl, fn (): string
            => $this->fetch($url, self::ACCEPT_JSON));

        try {
            return $this->commodityMapper->map(JsonApiDocument::fromJson($body));
        } catch (\JsonException $e) {
            throw TariffUnavailableException::malformed($url, $e);
        }
    }

    /**
     * @return iterable<\Davix\Customs\Tariff\Commodity>
     */
    public function chapter(string $chapter, ?DateTimeImmutable $asOf = null): iterable
    {
        $date = $this->dateFor($asOf);
        $url = $this->url('/goods_nomenclatures/chapter/' . rawurlencode($chapter) . '.csv', $date);
        $cacheKey = $this->cacheKey('chapter', $chapter, $date);

        $body = $this->cached($cacheKey, $this->options->chapterCacheTtl, fn (): string
            => $this->fetch($url, self::ACCEPT_CSV));

        return $this->chapterParser->stream($body);
    }

    /**
     * Fetch a chapter without parsing it.
     *
     * The sync hashes the raw response to skip chapters that have not changed,
     * which is only possible if it can see the bytes.
     */
    public function rawChapter(string $chapter, ?DateTimeImmutable $asOf = null): string
    {
        $date = $this->dateFor($asOf);
        $url = $this->url('/goods_nomenclatures/chapter/' . rawurlencode($chapter) . '.csv', $date);

        return $this->fetch($url, self::ACCEPT_CSV);
    }

    /**
     * @return list<ChangeRecord>
     */
    public function changes(DateTimeImmutable $date): array
    {
        $url = $this->url('/changes/' . $date->format('Y-m-d'), null);

        try {
            return $this->changesMapper->mapJson($this->fetch($url, self::ACCEPT_JSON));
        } catch (\JsonException $e) {
            throw TariffUnavailableException::malformed($url, $e);
        }
    }

    public function isAvailable(): bool
    {
        try {
            $this->fetch($this->url('/sections', null), self::ACCEPT_JSON);

            return true;
        } catch (TariffUnavailableException $e) {
            $this->logger->warning('Tariff service is unavailable', ['reason' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Perform a request, retrying transient failures.
     *
     * @throws TariffUnavailableException
     */
    private function fetch(string $url, string $accept): string
    {
        $attempt = 0;
        $lastFailure = null;

        while ($attempt < $this->options->maxAttempts) {
            ++$attempt;

            try {
                return $this->attempt($url, $accept);
            } catch (TariffUnavailableException $failure) {
                $lastFailure = $failure;

                if (!$failure->retryable || $attempt >= $this->options->maxAttempts) {
                    throw $failure;
                }

                $delay = $this->delayAfter($failure, $attempt);

                $this->logger->info('Retrying tariff request', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'delay' => $delay,
                    'status' => $failure->statusCode,
                ]);

                $this->sleeper->sleep($delay);
            }
        }

        throw TariffUnavailableException::exhausted($url, $attempt, $lastFailure);
    }

    /**
     * @throws TariffUnavailableException
     */
    private function attempt(string $url, string $accept): string
    {
        $request = $this->requestFactory
            ->createRequest('GET', $url)
            ->withHeader('Accept', $accept);

        if ($this->options->userAgent !== null) {
            $request = $request->withHeader('User-Agent', $this->options->userAgent);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw TariffUnavailableException::transport($url, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 404) {
            throw TariffUnavailableException::notFound($url);
        }

        if ($status < 200 || $status >= 300) {
            throw TariffUnavailableException::status($url, $status)
                ->withRetryAfter($this->parseRetryAfter($response->getHeaderLine('Retry-After')));
        }

        return (string) $response->getBody();
    }

    /**
     * How long to wait, preferring the service's own instruction.
     *
     * A `Retry-After` header is the service telling us exactly when it will be
     * ready. Ignoring it in favour of our own arithmetic is both ruder and
     * less effective.
     */
    private function delayAfter(TariffUnavailableException $failure, int $attempt): float
    {
        if ($failure->retryAfterSeconds !== null) {
            return min($failure->retryAfterSeconds, $this->options->maxDelaySeconds);
        }

        $delay = $this->options->delayForAttempt($attempt);

        if (!$this->options->jitter) {
            return $delay;
        }

        // Full jitter: a uniform draw between zero and the computed delay,
        // which spreads a fleet of stores far better than a fixed interval.
        return $delay * (random_int(0, 1000) / 1000);
    }

    /**
     * `Retry-After` is either seconds or an HTTP date.
     */
    private function parseRetryAfter(string $header): ?float
    {
        $value = trim($header);

        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0.0, (float) ($timestamp - $this->clock->now()->getTimestamp()));
    }

    /**
     * @param callable(): string $fetch
     * @throws TariffUnavailableException
     */
    private function cached(string $key, int $ttl, callable $fetch): string
    {
        if ($this->cache === null || $ttl <= 0) {
            return $fetch();
        }

        try {
            $hit = $this->cache->get($key);

            if (is_string($hit)) {
                return $hit;
            }
        } catch (\Throwable $e) {
            // A broken cache must not stop a scan. Losing the optimisation is
            // recoverable; failing the request is not.
            $this->logger->warning('Tariff cache read failed', ['key' => $key, 'error' => $e->getMessage()]);
        }

        $body = $fetch();

        try {
            $this->cache->set($key, $body, $ttl);
        } catch (\Throwable $e) {
            $this->logger->warning('Tariff cache write failed', ['key' => $key, 'error' => $e->getMessage()]);
        }

        return $body;
    }

    private function cacheKey(string $kind, string $identifier, DateTimeImmutable $date): string
    {
        return self::CACHE_PREFIX . $kind . '.' . $identifier . '.' . $date->format('Y-m-d');
    }

    private function url(string $path, ?DateTimeImmutable $asOf): string
    {
        $url = rtrim($this->options->baseUri, '/') . $path;

        return $asOf === null ? $url : $url . '?as_of=' . $asOf->format('Y-m-d');
    }

    private function dateFor(?DateTimeImmutable $asOf): DateTimeImmutable
    {
        return $asOf ?? $this->clock->now();
    }
}
