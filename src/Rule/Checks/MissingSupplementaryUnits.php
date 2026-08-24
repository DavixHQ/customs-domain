<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\Severity;

/**
 * The commodity is declared in a supplementary unit the product does not
 * record.
 *
 * Some commodities are counted rather than weighed: pairs, items, litres,
 * square metres. Where the tariff asks for one, a declaration without it is
 * incomplete and the entry is rejected - a delay that costs far more than the
 * five seconds it takes to record the number.
 *
 * The unit itself comes from the commodity, never from the product, which is
 * why this rule needs measures at all. A merchant cannot be expected to know
 * that their garment is declared in number of items until the tariff says so.
 */
final class MissingSupplementaryUnits extends MeasureRule
{
    public const CODE = 'missing_supplementary_units';

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

        if ($detail === null || !$detail->requiresSupplementaryUnit()) {
            return null;
        }

        $quantity = $data->supplementaryQuantity();

        if ($quantity !== null && $quantity > 0.0) {
            return null;
        }

        $measure = $detail->measures->supplementaryUnits()->first();

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'code' => $detail->code(),
                'unit' => $detail->commodity->supplementaryUnit
                    ?? $measure?->dutyExpression?->base,
            ],
            remediation: RemediationHint::requiresInput('set_supplementary_quantity'),
        );
    }
}
