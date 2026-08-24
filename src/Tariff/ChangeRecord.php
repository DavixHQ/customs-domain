<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

use DateTimeImmutable;

/**
 * One entry from the daily changes feed.
 *
 * The feed is what turns a one-off audit into a subscription. A merchant who
 * classified everything correctly last year has no reason to look again until
 * something moves, and this is how they find out that it did.
 *
 * Entries identify the nomenclature line by SID and code together, so matching
 * against a catalogue can be exact rather than by string comparison on codes
 * that are not unique.
 */
final class ChangeRecord
{
    public const TYPE_MEASURE = 'measure';
    public const TYPE_COMMODITY = 'goods_nomenclature';

    public function __construct(
        public readonly int $goodsNomenclatureSid,
        public readonly string $code,
        public readonly string $changeType,
        public readonly ?DateTimeImmutable $changedOn = null,
        public readonly string $productlineSuffix = '',
        public readonly bool $endLine = false,
    ) {
    }

    /**
     * Whether the measures on a commodity changed rather than the commodity
     * itself. By far the commonest kind, and the one that changes a duty rate
     * without changing anything a merchant stored.
     */
    public function isMeasureChange(): bool
    {
        return $this->changeType === self::TYPE_MEASURE;
    }

    public function isCommodityChange(): bool
    {
        return $this->changeType === self::TYPE_COMMODITY;
    }

    /**
     * Whether this line is one goods can be declared against, and so one a
     * merchant might actually hold.
     */
    public function isDeclarableLine(): bool
    {
        return $this->endLine;
    }

    public function affects(string $code): bool
    {
        return $this->code === $code;
    }
}
