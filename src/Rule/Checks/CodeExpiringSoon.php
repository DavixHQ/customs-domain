<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\Severity;

/**
 * This commodity code stops being valid on a known date.
 *
 * The rule that turns an audit into a subscription. Everything else here tells
 * a merchant what is wrong today; this one tells them what will be wrong in
 * six weeks, while there is still time to reclassify calmly rather than
 * discovering it from a held shipment.
 *
 * Only fires where an end date is actually published. A chapter pull filtered
 * by `as_of` returns null end dates throughout, since it only contains lines
 * valid on that date — so this depends on the commodity lookup, which carries
 * real validity periods.
 */
final class CodeExpiringSoon extends MeasureRule
{
    public const CODE = 'code_expiring_soon';

    public function code(): string
    {
        return self::CODE;
    }

    public function severity(): Severity
    {
        return Severity::Attention;
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $detail = $context->detail;

        if ($detail === null) {
            return null;
        }

        $endsOn = $detail->commodity->validityEnd;

        if ($endsOn === null) {
            return null;
        }

        $now = $context->evaluatedAt;

        if ($endsOn <= $now) {
            return null;
        }

        $daysRemaining = (int) $now->diff($endsOn)->days;

        if ($daysRemaining > $context->settings->expiryWarningDays) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'code' => $detail->code(),
                'expires_on' => $endsOn->format('Y-m-d'),
                'days_remaining' => $daysRemaining,
                'warning_window_days' => $context->settings->expiryWarningDays,
            ],
        );
    }
}
