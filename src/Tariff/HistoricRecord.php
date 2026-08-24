<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

use DateTimeImmutable;

/**
 * What a lookup at the historic baseline date found.
 *
 * Filtering the nomenclature by a date returns only codes valid on that date,
 * so a withdrawn code is simply absent rather than returned with an end date.
 * Every record in a current chapter pull carries a null end date, which makes
 * "not in the mirror" ambiguous on its own — it could be a typo or a code that
 * died in a past revision.
 *
 * Resolving that needs a second lookup against the provider at a baseline
 * predating the HS2022 restructure, which is where most stale merchant data
 * sits. That lookup is a network call, so the host performs it and passes the
 * answer in here.
 *
 * The difference this buys is the difference between a useful product and a
 * frustrating one: "6201.93 was withdrawn on 1 January 2022 — it meant anoraks
 * of man-made fibres" against "code not found".
 */
final class HistoricRecord
{
    /**
     * @param list<string> $successorCodes Codes the withdrawn line was replaced
     *        by. Exactly one means the fix is knowable without asking.
     */
    private function __construct(
        public readonly bool $existed,
        public readonly ?string $description = null,
        public readonly ?DateTimeImmutable $withdrawnOn = null,
        public readonly array $successorCodes = [],
    ) {
    }

    /**
     * The code did not exist at the baseline either, so it was never real.
     */
    public static function absent(): self
    {
        return new self(false);
    }

    /**
     * @param list<string> $successorCodes
     */
    public static function found(
        string $description,
        ?DateTimeImmutable $withdrawnOn = null,
        array $successorCodes = [],
    ): self {
        return new self(true, $description, $withdrawnOn, $successorCodes);
    }

    public function hasSuccessor(): bool
    {
        return $this->successorCodes !== [];
    }

    /**
     * Whether the replacement is unambiguous.
     *
     * One successor means the module already knows the answer and the fix
     * earns a one-click button. Several means the merchant has to choose.
     */
    public function hasSingleSuccessor(): bool
    {
        return count($this->successorCodes) === 1;
    }

    public function soleSuccessor(): ?string
    {
        return $this->hasSingleSuccessor() ? $this->successorCodes[0] : null;
    }

    public function successorCount(): int
    {
        return count($this->successorCodes);
    }
}
