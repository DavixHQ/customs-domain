<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

use DateTimeImmutable;

/**
 * A tariff quota and how much of it is left.
 *
 * The piece that turns a preferential rate from a promise into a fact. A
 * measure saying goods from Costa Rica attract 0% under order number 057031 is
 * only useful if that quota still has volume in it - and quotas run out. A
 * merchant who has been quoting a landed cost against an exhausted quota is
 * paying full duty and does not know it.
 *
 * Volumes arrive as strings rather than numbers, so they are parsed here once
 * rather than at every use.
 */
final class QuotaDefinition
{
    public const STATUS_OPEN = 'Open';

    public function __construct(
        public readonly int $sid,
        public readonly string $orderNumber,
        public readonly string $status,
        public readonly ?float $initialVolume = null,
        public readonly ?float $balance = null,
        public readonly ?string $measurementUnit = null,
        public readonly ?string $monetaryUnit = null,
        public readonly ?DateTimeImmutable $validityStart = null,
        public readonly ?DateTimeImmutable $validityEnd = null,
        public readonly ?DateTimeImmutable $lastAllocation = null,
        public readonly ?DateTimeImmutable $suspensionStart = null,
        public readonly ?DateTimeImmutable $suspensionEnd = null,
        public readonly ?DateTimeImmutable $blockingStart = null,
        public readonly ?DateTimeImmutable $blockingEnd = null,
        /** @var list<string> */
        public readonly array $originCodes = [],
        public readonly ?string $description = null,
    ) {
    }

    public function isOpen(): bool
    {
        return strcasecmp($this->status, self::STATUS_OPEN) === 0;
    }

    /**
     * Whether the quota has been used up.
     *
     * A zero balance and a status other than open are both treated as
     * exhausted, because either means a merchant relying on this rate will not
     * get it. The two are distinguished in the message rather than here.
     */
    public function isExhausted(): bool
    {
        if ($this->balance !== null && $this->balance <= 0.0) {
            return true;
        }

        return !$this->isOpen();
    }

    public function isSuspended(): bool
    {
        return $this->suspensionStart !== null;
    }

    public function isBlocked(): bool
    {
        return $this->blockingStart !== null;
    }

    /**
     * How much of the quota remains, from 0.0 to 1.0, or null when the
     * volumes are not both known.
     */
    public function remainingFraction(): ?float
    {
        if ($this->balance === null || $this->initialVolume === null || $this->initialVolume <= 0.0) {
            return null;
        }

        return max(0.0, min(1.0, $this->balance / $this->initialVolume));
    }

    /**
     * Whether the quota is close enough to running out to be worth warning
     * about while there is still time to plan.
     *
     * Telling a merchant a quota is exhausted is useful. Telling them it is
     * nearly exhausted, before they commit to a shipment priced against it, is
     * considerably more so.
     */
    public function isRunningLow(float $threshold = 0.1): bool
    {
        $remaining = $this->remainingFraction();

        return $remaining !== null && $remaining > 0.0 && $remaining <= $threshold;
    }

    public function isInForceOn(DateTimeImmutable $date): bool
    {
        if ($this->validityStart !== null && $date < $this->validityStart) {
            return false;
        }

        if ($this->validityEnd !== null && $date > $this->validityEnd) {
            return false;
        }

        return true;
    }

    public function appliesToOrigin(string $countryCode): bool
    {
        if ($this->originCodes === []) {
            return true;
        }

        return in_array(strtoupper(trim($countryCode)), array_map('strtoupper', $this->originCodes), true);
    }

    /**
     * The balance with its unit, for display.
     */
    public function describeBalance(): ?string
    {
        if ($this->balance === null) {
            return null;
        }

        $unit = $this->measurementUnit ?? $this->monetaryUnit;
        $amount = rtrim(rtrim(number_format($this->balance, 2, '.', ','), '0'), '.');

        return $unit === null ? $amount : sprintf('%s %s', $amount, $unit);
    }
}
