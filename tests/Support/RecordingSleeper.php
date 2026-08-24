<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Support;

use Davix\Customs\Provider\Sleeper;

/**
 * Records what it was asked to wait instead of waiting.
 *
 * A backoff test that genuinely sleeps seven seconds is a test nobody runs,
 * and one that is quietly removed the first time the suite feels slow.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
    }

    public function total(): float
    {
        return array_sum($this->slept);
    }
}
