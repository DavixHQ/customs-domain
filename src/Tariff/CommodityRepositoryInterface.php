<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * Read access to the local nomenclature mirror.
 *
 * The host application implements this over whatever storage it uses a
 * Magento resource model over `customsradar_commodity`, a WordPress table, a
 * SQLite file. This package never knows which.
 *
 * Implementations are scoped to a single jurisdiction at construction rather
 * than taking it as a parameter on every call. Supporting the Northern Ireland
 * tariff alongside the UK one means constructing a second repository, which
 * keeps this interface from growing a parameter that almost every caller would
 * pass the same value to.
 *
 * Every method reads the mirror only. Nothing here makes a network call
 * that is what makes the offline rules evaluable with zero HTTP per product,
 * and what stops a provider outage from flagging an entire catalogue as
 * broken.
 */
interface CommodityRepositoryInterface
{
    /**
     * Which tariff this mirror holds.
     *
     * Declared rather than assumed, because a Great Britain mirror queried
     * alongside a Northern Ireland client produces answers that are wrong
     * without looking wrong. A host holding both should compare this against
     * its client's jurisdiction before scanning.
     */
    public function jurisdiction(): Jurisdiction;

    /**
     * Find one line by its goods nomenclature SID.
     */
    public function findBySid(int $sid): ?Commodity;

    /**
     * Find every line carrying a commodity code.
     *
     * Returns a list because commodity codes are not unique the same code
     * routinely appears as both an intermediate grouping line and a declarable
     * line. Callers wanting the declarable one should use findDeclarable().
     *
     * @return list<Commodity>
     */
    public function findByCode(string $code): array;

    /**
     * Find the declarable line for a commodity code, if one exists.
     *
     * Returns null both when the code is absent from the mirror and when it
     * exists only as an intermediate grouping line. Those are different
     * situations, and callers that need to tell them apart should use
     * findByCode() and inspect the results.
     */
    public function findDeclarable(string $code): ?Commodity;

    /**
     * Immediate children of a line.
     *
     * @return list<Commodity>
     */
    public function childrenOf(int $parentSid): array;

    /**
     * Every declarable line beneath a point in the tree.
     *
     * This is the candidate set for expansion: given a six-digit subheading a
     * merchant supplied, these are the ten-digit codes they might mean. An
     * empty result means the line itself is the end of the branch.
     *
     * @return list<Commodity>
     */
    public function declarableDescendantsOf(int $sid): array;

    /**
     * Whether the mirror holds any lines for a two-digit chapter.
     *
     * Guards against a partially synced mirror producing false unknown-code
     * issues across an entire chapter. A scan should suppress mirror-dependent
     * rules for chapters this returns false for rather than reporting every
     * product in them as carrying a code that does not exist.
     */
    public function hasChapter(string $chapter): bool;
}
