<?php

declare(strict_types=1);

namespace Davix\Customs\Scan;

use Davix\Customs\Exception\TariffUnavailableException;
use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Provider\Clock;
use Davix\Customs\Provider\SystemClock;
use Davix\Customs\Provider\TariffProviderInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\RulePool;
use Davix\Customs\Rule\RuleSettings;
use Davix\Customs\Tariff\CertificateIndex;
use Davix\Customs\Tariff\CommodityDetail;
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\HistoricRecord;
use Davix\Customs\Tariff\QuotaSet;
use Davix\Customs\Tariff\Resolution;
use Davix\Customs\Tariff\ResolutionOutcome;
use Generator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs the rule set across a catalogue.
 *
 * Lives here rather than in each platform module because the expensive
 * decisions are identical everywhere and easy to get quietly wrong.
 *
 * The one that matters most is deduplication. Commodity lookups are per
 * distinct code, not per product: a 5,000-product apparel catalogue commonly
 * holds a couple of hundred distinct codes, so fetching per product is 5,000
 * calls where 200 will do. Certificates are fetched once for the whole scan.
 * That arithmetic is the difference between a scan finishing in two minutes
 * and taking forty, and nothing about it is platform-specific.
 *
 * The second is ordering. Resolve against the mirror, run everything the
 * mirror can answer, and only reach for the network where it can add
 * something. A code that resolved to several candidates has no single measure
 * set, so fetching for it buys nothing; a code missing from the mirror has no
 * measures to fetch at all, but is exactly the case where a historic lookup
 * earns its call.
 *
 * The third is failure. A provider outage midway through a catalogue leaves
 * the offline findings intact and marks what could not be checked, rather than
 * discarding 4,000 products of good work or reporting them as clean.
 *
 * What stays with the host: iterating the catalogue, persistence, queueing,
 * progress and cancellation. Those look nothing alike across platforms.
 *
 * One scanner per scan. In-memory caches live for its lifetime, and a host
 * scanning in batches can construct one per batch without losing much, because
 * the provider's own cache spans them.
 */
final class ProductScanner
{
    /** @var array<string, CommodityDetail|null> */
    private array $details = [];

    /** @var array<string, QuotaSet|null> */
    private array $quotas = [];

    /** @var array<string, HistoricRecord|null> */
    private array $historic = [];

    private ?CertificateIndex $certificates = null;

    private bool $certificatesAttempted = false;

    private int $consecutiveFailures = 0;

    private ScanSummary $summary;

    public function __construct(
        private readonly RulePool $rules,
        private readonly CommodityResolver $resolver,
        private readonly ?TariffProviderInterface $provider = null,
        private readonly RuleSettings $settings = new RuleSettings(),
        private readonly ScanOptions $options = new ScanOptions(),
        private readonly Clock $clock = new SystemClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->summary = new ScanSummary();
    }

    /**
     * Scan a catalogue, yielding one result per product.
     *
     * A generator, so a host can stream 50,000 products without holding them,
     * and can stop early by breaking out of the loop.
     *
     * @param iterable<ProductCustomsDataInterface> $products
     * @return Generator<int, ProductScanResult>
     */
    public function scan(iterable $products): Generator
    {
        foreach ($products as $product) {
            $result = $this->scanOne($product);

            $this->summary->record($result);

            yield $result;
        }
    }

    /**
     * Totals for everything scanned so far.
     *
     * Meaningful during iteration as well as after it, so a host can report
     * progress without counting separately.
     */
    public function summary(): ScanSummary
    {
        return $this->summary;
    }

    public function scanOne(ProductCustomsDataInterface $product): ProductScanResult
    {
        $normalised = $product->hsCode();
        $code = $normalised->isSuccess() ? $normalised->code() : '';

        $resolution = $code === ''
            ? Resolution::notInMirror('')
            : $this->resolver->resolve($code, $product->measuredProperties());

        $failure = null;
        $detail = null;
        $quotas = null;
        $historicRecord = null;

        try {
            $historicRecord = $this->historicFor($code, $resolution);
            $detail = $this->detailFor($code, $resolution);
            $quotas = $detail === null ? null : $this->quotasFor($code);
        } catch (TariffUnavailableException $e) {
            $failure = $e->getMessage();
            $this->noteFailure($e);
        }

        $context = new EvaluationContext(
            evaluatedAt: $this->clock->now(),
            settings: $this->settings,
            resolution: $resolution,
            historic: $historicRecord,
            detail: $detail,
            certificates: $detail === null ? null : $this->certificateIndex(),
            quotas: $quotas,
        );

        return new ProductScanResult(
            identifier: $product->identifier(),
            sku: $product->sku(),
            evaluation: $this->rules->evaluate($product, $context),
            resolution: $resolution,
            measuresFetched: $detail !== null,
            providerFailure: $failure,
        );
    }

    /**
     * Look a missing code up at the historic baseline.
     *
     * Only for codes the mirror does not hold, and only when the outcome was
     * conclusive - an unmirrored chapter proves nothing about the code, so
     * spending a call to confirm nothing would be waste on top of a sync
     * failure.
     */
    private function historicFor(string $code, Resolution $resolution): ?HistoricRecord
    {
        if (!$this->options->resolveWithdrawnCodes || $this->provider === null || $code === '') {
            return null;
        }

        if ($resolution->outcome !== ResolutionOutcome::NotInMirror) {
            return null;
        }

        if (array_key_exists($code, $this->historic)) {
            return $this->historic[$code];
        }

        $record = $this->provider->historicRecord($code, $this->options->historicBaselineDate());

        $this->summary->recordProviderCall();
        $this->consecutiveFailures = 0;

        return $this->historic[$code] = $record;
    }

    /**
     * Measures are worth fetching only for a code that resolved to exactly one
     * declarable commodity.
     *
     * Anything else has no single measure set to speak of: an ambiguous code
     * spans several classifications at once, and a missing one has none.
     */
    private function detailFor(string $code, Resolution $resolution): ?CommodityDetail
    {
        if (!$this->options->fetchMeasures || $this->provider === null) {
            return null;
        }

        if (!$resolution->isResolved() || $resolution->commodity === null) {
            return null;
        }

        // Keyed on the resolved commodity rather than the merchant's input, so
        // a hundred products written as 620130, 6201.30 and 6201300000 share
        // one lookup.
        $resolved = $resolution->commodity->code;

        if (array_key_exists($resolved, $this->details)) {
            return $this->details[$resolved];
        }

        $detail = $this->provider->commodity($resolved, $this->clock->now());

        $this->summary->recordProviderCall();
        $this->consecutiveFailures = 0;

        return $this->details[$resolved] = $detail;
    }

    private function quotasFor(string $code): ?QuotaSet
    {
        if (!$this->options->fetchQuotas || $this->provider === null || $code === '') {
            return null;
        }

        if (array_key_exists($code, $this->quotas)) {
            return $this->quotas[$code];
        }

        $quotas = $this->provider->quotas($code, $this->clock->now());

        $this->summary->recordProviderCall();
        $this->consecutiveFailures = 0;

        return $this->quotas[$code] = $quotas;
    }

    /**
     * The certificate listing, fetched at most once per scan.
     *
     * A few hundred entries in a single response, and every control on every
     * product resolves against the same set. Fetching per product would be
     * thousands of identical calls; failing to fetch it costs only readability,
     * so a failure here is logged and swallowed rather than marking products
     * incomplete.
     */
    private function certificateIndex(): ?CertificateIndex
    {
        if ($this->certificatesAttempted || $this->provider === null || !$this->options->fetchMeasures) {
            return $this->certificates;
        }

        $this->certificatesAttempted = true;

        try {
            $this->certificates = $this->provider->certificates($this->clock->now());
            $this->summary->recordProviderCall();
        } catch (TariffUnavailableException $e) {
            $this->logger->warning('Could not fetch certificate descriptions', [
                'reason' => $e->getMessage(),
            ]);
        }

        return $this->certificates;
    }

    /**
     * @throws TariffUnavailableException when the service has failed enough
     *         times that continuing would produce a report built on nothing
     */
    private function noteFailure(TariffUnavailableException $failure): void
    {
        $this->summary->recordProviderFailure();
        ++$this->consecutiveFailures;

        $this->logger->warning('Tariff lookup failed during scan', [
            'reason' => $failure->getMessage(),
            'consecutive' => $this->consecutiveFailures,
        ]);

        $limit = $this->options->maxProviderFailures;

        if ($limit > 0 && $this->consecutiveFailures >= $limit) {
            throw $failure;
        }
    }
}
