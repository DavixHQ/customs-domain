<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\RuleInterface;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Validation\NormalisationFailure;

/**
 * No commodity code supplied at all.
 *
 * Separated from the format rule because missing and malformed are different
 * problems with different fixes. A merchant filtering for products they need
 * to classify from scratch wants this list; a merchant fixing typos wants the
 * other one. Conflating them puts the wrong products in the wrong bulk action.
 *
 * This is the root of the dependency tree: almost every other code-related
 * check is meaningless without a code to check.
 */
final class MissingHsCode implements RuleInterface
{
    public const CODE = 'missing_hs_code';

    public function code(): string
    {
        return self::CODE;
    }

    public function severity(): Severity
    {
        return Severity::Blocked;
    }

    public function prerequisites(): array
    {
        return [];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        if ($data->hsCode()->failure !== NormalisationFailure::Blank) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'sku' => $data->sku(),
            ],
            remediation: RemediationHint::requiresInput('set_hs_code'),
        );
    }
}
