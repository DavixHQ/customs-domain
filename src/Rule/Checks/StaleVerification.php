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
 * Customs data was confirmed once and has not been looked at since.
 *
 * Classification decisions age. The nomenclature is revised, goods change
 * specification, and a code that was right three years ago may not be right
 * now. Re-confirming is cheap; discovering the drift at the border is not.
 *
 * Fires only for products someone has previously verified. A product that has
 * never been verified is not stale - it is new, and on a first scan that would
 * be every product in the catalogue, burying the issues that need attention
 * under thousands that do not. Finding never-verified products is what the
 * grid's saved filter is for.
 *
 * The bulk fix here is genuinely automatic: re-verifying is stamping today's
 * date across a selection, which is the one remediation where the module
 * really does already know the answer.
 */
final class StaleVerification implements RuleInterface
{
    public const CODE = 'stale_verification';

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
        $verifiedAt = $data->verifiedAt();

        if ($verifiedAt === null) {
            return null;
        }

        $months = $context->settings->staleVerificationMonths;

        if ($months <= 0) {
            return null;
        }

        $threshold = $context->evaluatedAt->modify(sprintf('-%d months', $months));

        if ($verifiedAt > $threshold) {
            return null;
        }

        $age = $verifiedAt->diff($context->evaluatedAt);

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'verified_at' => $verifiedAt->format('Y-m-d'),
                'months_since' => ($age->y * 12) + $age->m,
                'threshold_months' => $months,
            ],
            remediation: RemediationHint::automatic('mark_as_verified'),
        );
    }
}
