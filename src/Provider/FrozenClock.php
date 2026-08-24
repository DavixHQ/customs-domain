<?php

declare(strict_types=1);

namespace Davix\Customs\Provider;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock that does not move. For tests, and for pinning a whole scan to one
 * date so every product in it is evaluated against the same tariff.
 */
final class FrozenClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {
    }

    public static function at(string $date): self
    {
        return new self(new DateTimeImmutable($date, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
