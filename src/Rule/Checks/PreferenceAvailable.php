<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\Measure;
use Davix\Customs\Tariff\MeasureType;

/**
 * A lower rate is available for goods from this origin than the merchant is
 * probably paying.
 *
 * The rule that pays for the module. Third country duty is the default anyone
 * lands on without doing anything; a preferential rate under a trade agreement
 * frequently takes it to zero, and claiming it needs nothing more than the
 * right paperwork the merchant may already be entitled to.
 *
 * Only compares plain percentages. A compound duty such as "12.00 % + 8.50
 * EUR/kg" has no single rate, and inventing one to make the comparison work
 * would understate what is actually paid and overstate the saving. Silence is
 * better than a number a merchant might act on.
 *
 * Reported as an opportunity rather than a problem, and deliberately not
 * automatically fixable: claiming preference requires proof of origin the
 * module cannot supply and should not imply the merchant already holds.
 */
final class PreferenceAvailable extends MeasureRule
{
    public const CODE = 'preference_available';

    public function code(): string
    {
        return self::CODE;
    }

    public function severity(): Severity
    {
        return Severity::Opportunity;
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $measures = $this->applicableMeasures($data, $context);

        if ($measures === null || !$this->hasKnownOrigin($data)) {
            return null;
        }

        $preference = $this->lowestPreference($measures->preferences()->all());

        if ($preference === null) {
            return null;
        }

        $standard = $measures->ofType(MeasureType::THIRD_COUNTRY_DUTY)->first();

        if ($standard === null) {
            return null;
        }

        $standardRate = $standard->dutyExpression?->percentage();
        $preferentialRate = $preference->dutyExpression?->percentage();

        if ($standardRate === null || $preferentialRate === null) {
            return null;
        }

        if ($preferentialRate >= $standardRate) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'origin' => $this->originOf($data),
                'standard_rate' => (string) $standard->dutyExpression,
                'preferential_rate' => (string) $preference->dutyExpression,
                'saving_percentage_points' => round($standardRate - $preferentialRate, 2),
                'area' => $preference->geographicalArea?->description,
                'is_quota' => $preference->isQuota(),
                'order_number' => $preference->orderNumber,
            ],
            variant: $preference->isQuota() ? 'under_quota' : 'unrestricted',
        );
    }

    /**
     * @param list<Measure> $preferences
     */
    private function lowestPreference(array $preferences): ?Measure
    {
        $best = null;
        $bestRate = null;

        foreach ($preferences as $preference) {
            $rate = $preference->dutyExpression?->percentage();

            if ($rate === null) {
                continue;
            }

            if ($bestRate === null || $rate < $bestRate) {
                $best = $preference;
                $bestRate = $rate;
            }
        }

        return $best;
    }
}
