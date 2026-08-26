<?php

declare(strict_types=1);

namespace Davix\Customs\Scan;

use DateTimeImmutable;

/**
 * What a scan is allowed to do, and how hard it should work.
 *
 * Separate from RuleSettings, which says what counts as a problem. This says
 * what the scan may spend to find out.
 */
final class ScanOptions
{
    /**
     * The date a withdrawn code is looked up against.
     *
     * Chosen to predate the HS2022 restructure of 1 January 2022, which is
     * where most stale merchant data was orphaned. A code absent from today's
     * tariff but present here was real once, and saying so is a very different
     * conversation from "code not found".
     */
    public const DEFAULT_HISTORIC_BASELINE = '2021-12-31';

    public function __construct(
        /**
         * Fetch commodity measures for products that resolve cleanly. Turning
         * this off gives an offline scan: fast, no HTTP per product, and blind
         * to prohibitions, licences and preferences.
         */
        public readonly bool $fetchMeasures = true,

        /**
         * Fetch quota balances. Separate from measures because it is a second
         * call per commodity and only matters where a preferential rate is
         * actually in play.
         */
        public readonly bool $fetchQuotas = true,

        /**
         * Look up codes missing from the mirror at a historic date, to tell a
         * withdrawn code from one that never existed. One call per distinct
         * unknown code, which on a catalogue with a stale supplier import can
         * be the most valuable HTTP the scan makes.
         */
        public readonly bool $resolveWithdrawnCodes = true,

        /** @var string Date for the withdrawn-code lookup. */
        public readonly string $historicBaseline = self::DEFAULT_HISTORIC_BASELINE,

        /**
         * Abandon the scan if the tariff service fails this many times.
         *
         * A scan that presses on through a hundred consecutive failures
         * produces a catalogue-wide report built on nothing, which is worse
         * than stopping and saying so. Zero disables the limit.
         */
        public readonly int $maxProviderFailures = 10,
    ) {
    }

    /**
     * Options for a scan that makes no network calls at all.
     *
     * What a nightly catalogue-wide run should use: the mirror answers
     * everything, and the measure rules stay silent rather than guessing.
     */
    public static function offline(): self
    {
        return new self(
            fetchMeasures: false,
            fetchQuotas: false,
            resolveWithdrawnCodes: false,
        );
    }

    public function historicBaselineDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->historicBaseline);
    }

    public function usesNetwork(): bool
    {
        return $this->fetchMeasures || $this->fetchQuotas || $this->resolveWithdrawnCodes;
    }
}
