<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * Which tariff a lookup is against.
 *
 * Since the Windsor Framework, goods entering Northern Ireland are assessed
 * against a different tariff from goods entering Great Britain, and HMRC
 * publishes them as two services. The same commodity code can carry different
 * duty, different quotas and different controls in each.
 *
 * The two responses are structurally identical - same fields, same
 * relationships, same everything but the content and a `source` marker - which
 * is a mercy for the mapper and a hazard for everyone else. Nothing about an
 * XI response looks wrong when read as a UK one; it is simply answering a
 * different question. So a jurisdiction has to be chosen deliberately and
 * carried explicitly, because there is no shape to check it against.
 */
enum Jurisdiction: string
{
    /** Great Britain. */
    case Uk = 'uk';

    /** Northern Ireland, under the Windsor Framework. */
    case NorthernIreland = 'xi';

    private const HOST = 'https://www.trade-tariff.service.gov.uk';

    public function baseUri(): string
    {
        return match ($this) {
            self::Uk => self::HOST . '/api/v2',
            self::NorthernIreland => self::HOST . '/xi/api/v2',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Uk => 'United Kingdom',
            self::NorthernIreland => 'Northern Ireland',
        };
    }

    public function isNorthernIreland(): bool
    {
        return $this === self::NorthernIreland;
    }

    /**
     * Read the jurisdiction a response says it came from.
     *
     * The duty calculator metadata carries a `source` marker. Comparing it
     * against what was asked for is the only way to notice a misconfigured
     * base URI, since the payloads are otherwise indistinguishable.
     */
    public static function fromSource(?string $source): ?self
    {
        return $source === null ? null : self::tryFrom(strtolower(trim($source)));
    }
}
