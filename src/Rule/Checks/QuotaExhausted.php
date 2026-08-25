<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\Measure;
use Davix\Customs\Tariff\QuotaDefinition;

/**
 * A preferential rate the merchant may be relying on has run out, or is about
 * to.
 *
 * The gap between what a measure says and what a merchant will actually pay. A
 * measure states that goods from this origin attract 0% under order number
 * 057031; it says nothing about whether that quota still has volume. Quotas
 * are finite and annual, and a popular one can close months before the year
 * does. A merchant who priced a product against it in January and has not
 * looked since is paying full duty and does not know.
 *
 * Warns before exhaustion as well as after. Learning that a quota closed last
 * week is useful; learning that it is 92% used, while there is still time to
 * bring a shipment forward or reprice, is worth considerably more.
 *
 * Silent without an origin. A quota open only to Costa Rica says nothing about
 * a merchant's Vietnamese stock, and reporting every quota on a commodity
 * regardless of origin would be noise.
 */
final class QuotaExhausted extends MeasureRule
{
    public const CODE = 'quota_exhausted';

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
        $quotas = $context->quotas;
        $origin = $this->originOf($data);

        if ($quotas === null || $quotas->isEmpty() || $origin === null) {
            return null;
        }

        $relevant = $quotas
            ->forOrigin($origin)
            ->inForceOn($context->evaluatedAt);

        if ($relevant->isEmpty()) {
            return null;
        }

        $exhausted = $relevant->exhausted()->first();

        if ($exhausted !== null) {
            return $this->issue($exhausted, $origin, $context, $this->exhaustionVariant($exhausted));
        }

        $low = $relevant->runningLow($context->settings->quotaLowThreshold)->first();

        if ($low !== null) {
            return $this->issue($low, $origin, $context, 'running_low');
        }

        return null;
    }

    /**
     * Distinguishes why a quota is unavailable.
     *
     * A quota that has been used up will reopen next period; one that has been
     * suspended or blocked may not, and the merchant's response differs.
     */
    private function exhaustionVariant(QuotaDefinition $quota): string
    {
        return match (true) {
            $quota->isBlocked() => 'blocked',
            $quota->isSuspended() => 'suspended',
            $quota->balance !== null && $quota->balance <= 0.0 => 'used_up',
            default => 'not_open',
        };
    }

    private function issue(
        QuotaDefinition $quota,
        string $origin,
        EvaluationContext $context,
        string $variant,
    ): Issue {
        $remaining = $quota->remainingFraction();

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'origin' => $origin,
                'order_number' => $quota->orderNumber,
                'status' => $quota->status,
                'balance' => $quota->describeBalance(),
                'remaining_percentage' => $remaining === null ? null : round($remaining * 100, 1),
                'reopens_on' => $quota->validityEnd?->modify('+1 day')->format('Y-m-d'),
                'standard_rate' => $this->standardRateFor($quota, $context),
            ],
            variant: $variant,
        );
    }

    /**
     * What the merchant pays instead, when the quota rate is unavailable.
     *
     * The number that makes the issue actionable rather than merely
     * informative: "you will pay 12% rather than 0%" is a decision, whereas
     * "quota exhausted" is a fact needing research.
     */
    private function standardRateFor(QuotaDefinition $quota, EvaluationContext $context): ?string
    {
        $measures = $context->measuresForDirection();

        if ($measures === null) {
            return null;
        }

        $standard = $measures->ofType(\Davix\Customs\Tariff\MeasureType::THIRD_COUNTRY_DUTY)->first();

        return $standard instanceof Measure && $standard->hasDuty()
            ? (string) $standard->dutyExpression
            : null;
    }
}
