<?php

declare(strict_types=1);

namespace Davix\Customs\Scan;

use Davix\Customs\Rule\RuleEvaluation;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\Resolution;

/**
 * What a scan found for one product.
 */
final class ProductScanResult
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $sku,
        public readonly RuleEvaluation $evaluation,
        public readonly Resolution $resolution,
        public readonly bool $measuresFetched = false,
        /**
         * Why the tariff service could not be consulted for this product, if
         * it could not.
         *
         * Recorded rather than thrown. A provider outage midway through a
         * catalogue should leave the offline findings intact and mark what
         * could not be checked, not discard 4,000 products of good work.
         */
        public readonly ?string $providerFailure = null,
    ) {
    }

    public function hasIssues(): bool
    {
        return $this->evaluation->hasIssues();
    }

    public function issueCount(): int
    {
        return $this->evaluation->issueCount();
    }

    public function highestSeverity(): ?Severity
    {
        return $this->evaluation->highestSeverity();
    }

    public function isBlocked(): bool
    {
        return $this->evaluation->hasBlocking();
    }

    /**
     * Whether some checks could not run, so the product's state is partly
     * unknown rather than clean.
     */
    public function isIncomplete(): bool
    {
        return $this->providerFailure !== null;
    }
}
