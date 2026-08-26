<?php

declare(strict_types=1);

namespace Davix\Customs\Scan;

use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\Severity;

/**
 * Running totals for a scan.
 *
 * Counts products and issues separately and says so, because they are
 * different numbers and a dashboard that conflates them will not add up. One
 * product with a missing origin and an expired code is one product and two
 * issues; a merchant who sees "247" in one place and "case 189" in another
 * deserves to know which is which.
 *
 * Also counts the network calls made, which is the number that tells a
 * merchant why a scan took eleven minutes.
 */
final class ScanSummary
{
    private int $products = 0;
    private int $productsWithIssues = 0;
    private int $issues = 0;
    private int $incomplete = 0;

    /** @var array<string, int> */
    private array $bySeverity = [];

    /** @var array<string, int> */
    private array $byRule = [];

    /** @var array<string, true> */
    private array $distinctCodes = [];

    private int $providerCalls = 0;
    private int $providerFailures = 0;

    public function record(ProductScanResult $result): void
    {
        ++$this->products;

        if ($result->isIncomplete()) {
            ++$this->incomplete;
        }

        $code = $result->resolution->code;

        if ($code !== '') {
            $this->distinctCodes[$code] = true;
        }

        if (!$result->hasIssues()) {
            return;
        }

        ++$this->productsWithIssues;

        foreach ($result->evaluation->issues as $issue) {
            ++$this->issues;
            $this->countIssue($issue);
        }
    }

    public function recordProviderCall(): void
    {
        ++$this->providerCalls;
    }

    public function recordProviderFailure(): void
    {
        ++$this->providerFailures;
    }

    public function products(): int
    {
        return $this->products;
    }

    /**
     * Products carrying at least one issue.
     *
     * The number a dashboard tile should show. Merchants think in products,
     * because a product is the thing they fix.
     */
    public function productsWithIssues(): int
    {
        return $this->productsWithIssues;
    }

    /**
     * Issues found. Always at least the product count, usually more.
     */
    public function issues(): int
    {
        return $this->issues;
    }

    public function clean(): int
    {
        return $this->products - $this->productsWithIssues;
    }

    /**
     * Products where some check could not run, so their state is partly
     * unknown rather than clean.
     */
    public function incomplete(): int
    {
        return $this->incomplete;
    }

    public function countOfSeverity(Severity $severity): int
    {
        return $this->bySeverity[$severity->value] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function byRule(): array
    {
        arsort($this->byRule);

        return $this->byRule;
    }

    /**
     * How many distinct commodity codes the catalogue holds.
     *
     * The number that explains the scan's cost. A 5,000-product apparel
     * catalogue commonly holds a couple of hundred distinct codes, and every
     * lookup is per code rather than per product.
     */
    public function distinctCodes(): int
    {
        return count($this->distinctCodes);
    }

    public function providerCalls(): int
    {
        return $this->providerCalls;
    }

    public function providerFailures(): int
    {
        return $this->providerFailures;
    }

    /**
     * Lookups avoided by deduplicating on commodity code.
     */
    public function callsSaved(): int
    {
        return max(0, $this->products - $this->providerCalls);
    }

    private function countIssue(Issue $issue): void
    {
        $severity = $issue->severity->value;

        $this->bySeverity[$severity] = ($this->bySeverity[$severity] ?? 0) + 1;
        $this->byRule[$issue->ruleCode] = ($this->byRule[$issue->ruleCode] ?? 0) + 1;
    }
}
