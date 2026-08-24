<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

use DateTimeImmutable;

/**
 * One node in the goods nomenclature tree.
 *
 * The natural key is the goods nomenclature SID, not the commodity code. This
 * matters more than it looks: a commodity code is not unique. In chapter 62,
 * SIDs 106844 and 106845 both carry code 6201200011, distinguished only by
 * their productline suffix — 10 is an intermediate grouping line, 80 is the
 * declarable commodity. Keying anything on the code collides on import and
 * silently drops half the tree.
 *
 * Immutable. Built by the provider mappers from HMRC responses, and by the
 * host application's repository when reading the local mirror.
 */
final class Commodity
{
    /**
     * Productline suffix marking a commodity line — the only suffix that can
     * carry declarable goods.
     */
    public const SUFFIX_DECLARABLE = '80';

    /** First-level intermediate grouping. */
    public const SUFFIX_INTERMEDIATE = '10';

    /**
     * Second-level intermediate grouping, rarer but real.
     *
     * Code 6203491100 carries three lines: suffix 10 "Of artificial fibres",
     * suffix 20 "Trousers and breeches", suffix 80 "Industrial and
     * occupational". Treating 10 as the only grouping suffix misreads the
     * middle one as declarable.
     */
    public const SUFFIX_SECOND_INTERMEDIATE = '20';

    public function __construct(
        public readonly int $sid,
        public readonly string $code,
        public readonly string $productlineSuffix,
        public readonly string $description,
        public readonly bool $declarable,
        public readonly ?int $parentSid = null,
        public readonly int $numberIndents = 0,
        public readonly ?DateTimeImmutable $validityStart = null,
        public readonly ?DateTimeImmutable $validityEnd = null,
        public readonly ?string $supplementaryUnit = null,
        public readonly ?string $href = null,
    ) {
    }

    /**
     * Whether goods can actually be declared against this line.
     *
     * Trusts the explicit flag rather than inferring from the suffix. HMRC
     * supplies declarability directly and the two can disagree at the edges of
     * the tree.
     */
    public function isDeclarable(): bool
    {
        return $this->declarable;
    }

    /**
     * Whether this is a grouping line that exists only to hold children.
     *
     * Defined as "not the commodity suffix" rather than as a list of known
     * grouping suffixes. Live data carries 10, 20 and 80, and no line outside
     * suffix 80 is ever declarable — so an unfamiliar suffix appearing in
     * future is far more safely read as another grouping level than as
     * something a merchant can declare against.
     */
    public function isIntermediate(): bool
    {
        return $this->productlineSuffix !== self::SUFFIX_DECLARABLE;
    }

    public function isRoot(): bool
    {
        return $this->parentSid === null;
    }

    /**
     * Whether this commodity requires a supplementary unit alongside weight.
     */
    public function requiresSupplementaryUnit(): bool
    {
        return $this->supplementaryUnit !== null && $this->supplementaryUnit !== '';
    }

    /**
     * Whether the line was in force on a given date.
     *
     * A null start is treated as always having been in force, and a null end
     * as still in force. Chapter pulls filtered by `as_of` return null end
     * dates throughout, so absence of an end date carries no information on
     * its own.
     */
    public function wasInForceOn(DateTimeImmutable $date): bool
    {
        if ($this->validityStart !== null && $date < $this->validityStart) {
            return false;
        }

        if ($this->validityEnd !== null && $date > $this->validityEnd) {
            return false;
        }

        return true;
    }

    /**
     * Whether the line has a known end date in the future.
     *
     * Feeds the expiring-code rule, which warns merchants before a
     * classification stops being valid rather than after.
     */
    public function expiresAfter(DateTimeImmutable $date): bool
    {
        return $this->validityEnd !== null && $this->validityEnd > $date;
    }

    public function isChildOf(self $other): bool
    {
        return $this->parentSid === $other->sid;
    }

    /**
     * Whether two records describe the same nomenclature line.
     *
     * Compares SIDs, never codes, for the reason described on this class.
     */
    public function isSameAs(self $other): bool
    {
        return $this->sid === $other->sid;
    }
}
