<?php

declare(strict_types=1);

namespace Davix\Customs\Provider;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real clock, fixed to UTC.
 *
 * Deliberately not the host's local timezone. Tariff validity dates are UK
 * dates, and a merchant in Auckland asking for today's measures at 9am local
 * time is asking about yesterday in London. Resolving in UTC keeps every
 * merchant asking the same question on the same day.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
