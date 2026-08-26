<?php

declare(strict_types=1);

namespace Davix\Customs\Provider;

use Davix\Customs\Tariff\CertificateIndex;
use Davix\Customs\Tariff\ChangeRecord;
use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\CommodityDetail;
use Davix\Customs\Tariff\HistoricRecord;
use Davix\Customs\Tariff\Jurisdiction;
use Davix\Customs\Tariff\QuotaSet;
use DateTimeImmutable;

/**
 * Access to a tariff service.
 *
 * Named for the role rather than the provider, so a host can substitute a
 * recorded provider in tests or a different jurisdiction's service later
 * without every caller knowing.
 *
 * Every method takes an optional `asOf`. The tariff is a function of a date,
 * not a fixed thing, a code valid today may not have been last year and may
 * not be next, so a lookup without a date is a lookup whose answer changes
 * underneath you.
 */
interface TariffProviderInterface
{
    /**
     * One commodity with its measures.
     *
     * @throws \Davix\Customs\Exception\TariffUnavailableException
     */
    public function commodity(string $code, ?DateTimeImmutable $asOf = null): CommodityDetail;

    /**
     * Every nomenclature line in a chapter, for the mirror sync.
     *
     * Returns an iterable so a caller can stream rather than hold a whole
     * chapter in memory.
     *
     * @return iterable<Commodity>
     * @throws \Davix\Customs\Exception\TariffUnavailableException
     * @throws \Davix\Customs\Exception\TariffParseException
     */
    public function chapter(string $chapter, ?DateTimeImmutable $asOf = null): iterable;

    /**
     * What changed on a given date.
     *
     * An empty result means the feed reported nothing, which is not the same
     * as nothing having changed, the feed is only retained for a window, and
     * a request for a date outside it also returns empty. Callers that care
     * about the difference should track their own last successful poll.
     *
     * @return list<ChangeRecord>
     * @throws \Davix\Customs\Exception\TariffUnavailableException
     */
    public function changes(DateTimeImmutable $date): array;

    /**
     * Every certificate the tariff defines, indexed by document code.
     *
     * Fetched whole rather than per code. The set is a few hundred entries in
     * one unpaginated response, and a scan reporting controls across a
     * catalogue would otherwise make a call per code per product.
     *
     * @throws \Davix\Customs\Exception\TariffUnavailableException
     */
    public function certificates(?DateTimeImmutable $asOf = null): CertificateIndex;

    /**
     * What the nomenclature looked like at a past date.
     *
     * The lookup that separates a withdrawn code from one that never existed.
     * Filtering by `as_of` returns only lines valid on that date, so a code
     * withdrawn in a past revision is simply absent from a current pull - and
     * absent is indistinguishable from never having existed without asking
     * again at a date before the revision.
     *
     * Returns a record describing what was found, never throwing for a code
     * that is genuinely absent at the baseline: that is an answer, not a
     * failure.
     *
     * @throws \Davix\Customs\Exception\TariffUnavailableException when the
     *         service itself cannot be reached
     */
    public function historicRecord(string $code, DateTimeImmutable $baseline): HistoricRecord;

    /**
     * Tariff quotas attached to a commodity, with their remaining balances.
     *
     * Balances are the reason this is a separate call: a measure states that a
     * preferential rate exists under an order number, and says nothing about
     * whether the quota still has volume in it. A merchant quoting a landed
     * cost against an exhausted quota is paying full duty without knowing.
     *
     * @throws \Davix\Customs\Exception\TariffUnavailableException
     */
    public function quotas(string $code, ?DateTimeImmutable $asOf = null): QuotaSet;

    /**
     * Which tariff this provider queries.
     *
     * Great Britain and Northern Ireland are separate tariffs with identical
     * response shapes, so nothing about a reply reveals a client pointed at
     * the wrong one. A host holding a mirror should compare this against the
     * mirror's own jurisdiction before scanning.
     */
    public function jurisdiction(): Jurisdiction;

    /**
     * Whether the service is reachable and answering.
     */
    public function isAvailable(): bool;
}
