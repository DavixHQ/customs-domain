<?php

declare(strict_types=1);

namespace Davix\Customs\Provider;

use Davix\Customs\Tariff\ChangeRecord;
use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\CommodityDetail;
use DateTimeImmutable;

/**
 * Access to a tariff service.
 *
 * Named for the role rather than the provider, so a host can substitute a
 * recorded provider in tests or a different jurisdiction's service later
 * without every caller knowing.
 *
 * Every method takes an optional `asOf`. The tariff is a function of a date,
 * not a fixed thing — a code valid today may not have been last year and may
 * not be next — so a lookup without a date is a lookup whose answer changes
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
     * as nothing having changed — the feed is only retained for a window, and
     * a request for a date outside it also returns empty. Callers that care
     * about the difference should track their own last successful poll.
     *
     * @return list<ChangeRecord>
     * @throws \Davix\Customs\Exception\TariffUnavailableException
     */
    public function changes(DateTimeImmutable $date): array;

    /**
     * Whether the service is reachable and answering.
     */
    public function isAvailable(): bool;
}
