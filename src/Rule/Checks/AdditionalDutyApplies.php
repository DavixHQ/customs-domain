<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\MeasureTypeSeries;

/**
 * An additional duty or trade restriction applies to these goods from this
 * origin.
 *
 * Named for what it detects rather than for anti-dumping, because the live
 * data does not support the narrower name. A men's cotton parka comes back
 * with `trade_defence: true`, and the measures behind it are a 35% additional
 * duty on Russia and Belarus plus restrictions on North Korea and Ukraine —
 * sanctions rather than dumping.
 *
 * That same finding is why the precomputed flag is not the answer. It is set
 * at commodity level for goods where such a measure exists for *some* origin,
 * so firing on the flag alone would report a 35% surcharge on every apparel
 * product in a catalogue regardless of where it came from. The flag is used
 * only to decide whether looking is worthwhile; the measures decide whether
 * anything applies.
 */
final class AdditionalDutyApplies extends MeasureRule
{
    public const CODE = 'additional_duty_applies';

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
        $measures = $this->applicableMeasures($data, $context);

        // Without an origin this cannot be answered honestly. Unlike a
        // prohibition, an additional duty is meaningless unnarrowed — every
        // apparel product would report Russia's 35%.
        if ($measures === null || !$this->hasKnownOrigin($data)) {
            return null;
        }

        $additional = $measures->inSeries(MeasureTypeSeries::ADDITIONAL_DUTY);

        if ($additional->isEmpty()) {
            return null;
        }

        $first = $additional->first();

        if ($first === null) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'origin' => $this->originOf($data),
                'rate' => (string) $first->dutyExpression,
                'measure_type' => $first->type->description,
                'area' => $first->geographicalArea?->description,
                'measure_count' => $additional->count(),
            ],
        );
    }
}
