<?php

declare(strict_types=1);

namespace Davix\Customs\Provider;

/**
 * Waiting, injected so backoff can be tested without actually waiting.
 *
 * A retry test that genuinely sleeps three seconds is a test nobody runs.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
