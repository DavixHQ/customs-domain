<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\Measure;
use Davix\Customs\Tariff\MeasureCondition;

/**
 * The goods cannot legally move at all.
 *
 * The most serious thing this module can tell a merchant, and the one where
 * being wrong is least forgivable in either direction. Missing a real
 * prohibition means a seizure; inventing one means a merchant delists a
 * product they were entitled to sell.
 *
 * Detected two ways, because they do not always agree. A measure type in
 * series A is a prohibition outright. A control measure prohibits through a
 * negative condition when its document is not presented, and those carry
 * action codes 05, 06 and 09 - matching on 09 alone, as is sometimes
 * documented, misses two thirds of them.
 *
 * Never suppressed for want of an origin. If the origin field is empty the
 * measures are reported unnarrowed and the message says so, because staying
 * silent about something that stops a shipment is the worse failure.
 */
final class ProhibitedGoods extends MeasureRule
{
    public const CODE = 'prohibited_goods';

    public function code(): string
    {
        return self::CODE;
    }

    public function severity(): Severity
    {
        return Severity::Blocked;
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $measures = $this->applicableMeasures($data, $context);

        if ($measures === null) {
            return null;
        }

        $prohibitions = $measures->prohibitions();

        if ($prohibitions->isEmpty()) {
            return null;
        }

        $first = $prohibitions->first();

        if ($first === null) {
            return null;
        }

        $originKnown = $this->hasKnownOrigin($data);

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'direction' => $context->settings->direction->value,
                'origin' => $this->originOf($data),
                'measure_count' => $prohibitions->count(),
                'measure_type' => $first->type->description,
                'area' => $first->geographicalArea?->description,
                'reason' => $this->reasonFor($first),
            ],
            variant: $originKnown ? 'confirmed' : 'origin_unknown',
        );
    }

    /**
     * The most specific explanation available, falling back to the measure
     * type's own description - which always exists, so this never returns null.
     */
    private function reasonFor(Measure $measure): string
    {
        foreach ($measure->prohibitingConditions() as $condition) {
            $summary = $condition->summary();

            if ($summary !== null) {
                return $summary;
            }
        }

        return $measure->type->description;
    }
}