<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

/**
 * How badly a merchant needs to care.
 *
 * Three levels, deliberately. Merchants read severity before they read
 * anything else, so it drives dashboard ordering, badge colour and
 * notification thresholds. A fourth level would dilute all of them.
 */
enum Severity: string
{
    /** Do not ship this. The data is wrong or the goods are controlled. */
    case Blocked = 'blocked';

    /** The data is incomplete or questionable, but nothing is stopping. */
    case Attention = 'attention';

    /** There is money on the table - a lower rate the merchant could claim. */
    case Opportunity = 'opportunity';

    /**
     * Sort weight, descending by urgency.
     *
     * Used for dashboard ordering, so blocked issues always surface above
     * everything else regardless of count.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Blocked => 3,
            self::Attention => 2,
            self::Opportunity => 1,
        };
    }

    public function isMoreSevereThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /**
     * Pick the most severe of a set, or null when the set is empty.
     *
     * @param iterable<self> $severities
     */
    public static function highest(iterable $severities): ?self
    {
        $highest = null;

        foreach ($severities as $severity) {
            if ($highest === null || $severity->isMoreSevereThan($highest)) {
                $highest = $severity;
            }
        }

        return $highest;
    }
}
