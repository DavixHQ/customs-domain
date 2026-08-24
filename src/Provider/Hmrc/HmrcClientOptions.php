<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

/**
 * How the client should behave. Plain values, supplied by the host from its
 * own configuration.
 */
final class HmrcClientOptions
{
    public const DEFAULT_BASE_URI = 'https://www.trade-tariff.service.gov.uk/api/v2';

    /**
     * @param int $maxAttempts Total tries including the first, so 3 means two
     *        retries. Beyond a handful, a struggling service is better left
     *        alone than hammered.
     * @param float $baseDelaySeconds First backoff interval; doubles each retry.
     * @param float $maxDelaySeconds Ceiling on any single wait, so a long
     *        retry chain cannot stall a scan indefinitely.
     * @param int $commodityCacheTtl Seconds to cache a commodity lookup.
     *        Measures change daily at most, and a scan may ask for the same
     *        code thousands of times in a run.
     * @param int $chapterCacheTtl Chapter pulls are large and change rarely.
     *        Zero disables caching for them, which is usually right — the sync
     *        already hashes responses to skip unchanged chapters.
     * @param bool $jitter Spread retries so a fleet of stores hitting a rate
     *        limit does not retry in lockstep and cause the next one.
     */
    public function __construct(
        public readonly string $baseUri = self::DEFAULT_BASE_URI,
        public readonly int $maxAttempts = 3,
        public readonly float $baseDelaySeconds = 1.0,
        public readonly float $maxDelaySeconds = 30.0,
        public readonly int $commodityCacheTtl = 86400,
        public readonly int $chapterCacheTtl = 0,
        public readonly bool $jitter = true,
        public readonly ?string $userAgent = null,
    ) {
    }

    public function withBaseUri(string $baseUri): self
    {
        return new self(
            rtrim($baseUri, '/'),
            $this->maxAttempts,
            $this->baseDelaySeconds,
            $this->maxDelaySeconds,
            $this->commodityCacheTtl,
            $this->chapterCacheTtl,
            $this->jitter,
            $this->userAgent,
        );
    }

    /**
     * Backoff for a given attempt, doubling and capped.
     */
    public function delayForAttempt(int $attempt): float
    {
        $delay = $this->baseDelaySeconds * (2 ** max(0, $attempt - 1));

        return min($delay, $this->maxDelaySeconds);
    }
}
