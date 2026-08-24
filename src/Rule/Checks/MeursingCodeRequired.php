<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\Severity;

/**
 * The duty on these goods depends on their composition, via a Meursing code.
 *
 * Applies to composite agricultural goods - confectionery, baked goods,
 * preparations - where duty is calculated from milk fat, milk protein, starch
 * and sucrose content rather than from the commodity code alone. A merchant
 * who does not know this exists cannot compute their landed cost and will be
 * surprised at the border.
 *
 * Awareness rather than a data gap: the module cannot derive the code, and
 * says so. Reported because a merchant who has never heard of a Meursing code
 * is far worse off than one who has and needs to go and find theirs.
 */
final class MeursingCodeRequired extends MeasureRule
{
    public const CODE = 'meursing_code_required';

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

        if ($detail === null || !$detail->flags->meursingCode) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'code' => $detail->code(),
                'composition' => $data->composition(),
            ],
            remediation: RemediationHint::requiresInput('record_meursing_code'),
        );
    }
}
