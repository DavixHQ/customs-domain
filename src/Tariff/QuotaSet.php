<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

use DateTimeImmutable;

/**
 * The quotas attached to a commodity, with the filters rules need.
 */
final class QuotaSet
{
    /**
     * @param list<QuotaDefinition> $quotas
     */
    public function __construct(
        private readonly array $quotas = [],
    ) {
    }

    /**
     * @return list<QuotaDefinition>
     */
    public function all(): array
    {
        return $this->quotas;
    }

    public function count(): int
    {
        return count($this->quotas);
    }

    public function isEmpty(): bool
    {
        return $this->quotas === [];
    }

    public function forOrigin(string $countryCode): self
    {
        return $this->filter(
            static fn (QuotaDefinition $q): bool => $q->appliesToOrigin($countryCode),
        );
    }

    public function inForceOn(DateTimeImmutable $date): self
    {
        return $this->filter(
            static fn (QuotaDefinition $q): bool => $q->isInForceOn($date),
        );
    }

    public function exhausted(): self
    {
        return $this->filter(
            static fn (QuotaDefinition $q): bool => $q->isExhausted(),
        );
    }

    public function open(): self
    {
        return $this->filter(
            static fn (QuotaDefinition $q): bool => !$q->isExhausted(),
        );
    }

    public function runningLow(float $threshold = 0.1): self
    {
        return $this->filter(
            static fn (QuotaDefinition $q): bool => $q->isRunningLow($threshold),
        );
    }

    public function forOrderNumber(string $orderNumber): ?QuotaDefinition
    {
        $wanted = ltrim(trim($orderNumber), '0');

        foreach ($this->quotas as $quota) {
            if (ltrim($quota->orderNumber, '0') === $wanted) {
                return $quota;
            }
        }

        return null;
    }

    public function first(): ?QuotaDefinition
    {
        return $this->quotas[0] ?? null;
    }

    /**
     * @param callable(QuotaDefinition): bool $predicate
     */
    private function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->quotas, $predicate)));
    }
}
