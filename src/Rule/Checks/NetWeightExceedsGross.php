<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\RuleInterface;
use Davix\Customs\Rule\Severity;

/**
 * Net weight is greater than gross weight, which is physically impossible.
 *
 * Gross weight includes the packaging that net weight excludes, so net can
 * equal gross but never exceed it. When it does, the data is wrong in a way
 * that matters twice over: weight drives duty on many commodities and it
 * narrows classification throughout the apparel chapters, so a bad weight
 * quietly produces a confident wrong answer rather than an obvious failure.
 *
 * A ratio near a thousand is called out separately because it is almost always
 * grams entered into a kilogram field — the single most common unit error in
 * customs data, and one a merchant fixes in seconds once told.
 */
final class NetWeightExceedsGross implements RuleInterface
{
    public const CODE = 'net_weight_exceeds_gross';

    /** Tolerance for floating point noise and legitimate rounding, in kg. */
    private const TOLERANCE_KG = 0.001;

    /** Ratio range treated as a probable grams-for-kilograms mistake. */
    private const UNIT_ERROR_LOWER = 500.0;
    private const UNIT_ERROR_UPPER = 2000.0;

    public function code(): string
    {
        return self::CODE;
    }

    public function severity(): Severity
    {
        return Severity::Attention;
    }

    public function prerequisites(): array
    {
        return [];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $net = $data->netWeight();
        $gross = $data->grossWeight();

        if ($net === null || $gross === null || $net <= 0.0 || $gross <= 0.0) {
            return null;
        }

        if ($net <= $gross + self::TOLERANCE_KG) {
            return null;
        }

        $ratio = $net / $gross;
        $looksLikeUnitError = $ratio >= self::UNIT_ERROR_LOWER && $ratio <= self::UNIT_ERROR_UPPER;

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'net_weight' => $net,
                'gross_weight' => $gross,
                'ratio' => round($ratio, 2),
                'suggested_net_weight' => $looksLikeUnitError ? round($net / 1000, 4) : null,
            ],
            variant: $looksLikeUnitError ? 'probable_unit_error' : 'inconsistent',
            remediation: RemediationHint::requiresInput('set_net_weight'),
        );
    }
}
