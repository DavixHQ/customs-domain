<?php

declare(strict_types=1);

namespace Davix\Customs\Provider;

use DateTimeImmutable;

/**
 * The current time, injected rather than read.
 *
 * Every request the client makes carries an `as_of` date, so "now" is an input
 * to the provider, not an ambient fact. Injecting it keeps the client's
 * behaviour reproducible in tests and lets a host pin an evaluation to a fixed
 * date when it needs to.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
