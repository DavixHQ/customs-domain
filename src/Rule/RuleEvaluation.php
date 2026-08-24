<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

/**
 * The outcome of running the rule set against one product.
 *
 * Carries the skipped rules as well as the issues, because "we did not check
 * this" and "we checked this and it was fine" are different claims, and a
 * merchant asking why a product shows no weight warning deserves the honest
 * answer that its commodity code could not be read.
 */
final class RuleEvaluation
{
    /**
     * @param list<Issue> $issues
     * @param array<string, SkipReason> $skipped Rule code to why it did not run.
     */
    public function __construct(
        public readonly array $issues = [],
        public readonly array $skipped = [],
    ) {
    }

    public function hasIssues(): bool
    {
        return $this->issues !== [];
    }

    public function issueCount(): int
    {
        return count($this->issues);
    }

    /**
     * @return list<string>
     */
    public function issueCodes(): array
    {
        return array_values(array_map(
            static fn (Issue $issue): string => $issue->ruleCode,
            $this->issues,
        ));
    }

    public function highestSeverity(): ?Severity
    {
        return Severity::highest(array_map(
            static fn (Issue $issue): Severity => $issue->severity,
            $this->issues,
        ));
    }

    public function hasBlocking(): bool
    {
        return $this->highestSeverity() === Severity::Blocked;
    }

    /**
     * @return list<Issue>
     */
    public function issuesOfSeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (Issue $issue): bool => $issue->severity === $severity,
        ));
    }

    public function issueFor(string $ruleCode): ?Issue
    {
        foreach ($this->issues as $issue) {
            if ($issue->ruleCode === $ruleCode) {
                return $issue;
            }
        }

        return null;
    }

    public function has(string $ruleCode): bool
    {
        return $this->issueFor($ruleCode) !== null;
    }

    public function wasSkipped(string $ruleCode): bool
    {
        return isset($this->skipped[$ruleCode]);
    }

    public function skipReason(string $ruleCode): ?SkipReason
    {
        return $this->skipped[$ruleCode] ?? null;
    }

    /**
     * Issues a merchant could fix in bulk with no further input.
     *
     * @return list<Issue>
     */
    public function automaticallyFixable(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (Issue $issue): bool => $issue->isAutomaticallyFixable(),
        ));
    }
}
