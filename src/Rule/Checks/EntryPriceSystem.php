<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\Severity;

/**
 * Duty on these goods depends on the price they enter at.
 *
 * The entry price system covers fruit and vegetables, where duty rises as the
 * import price falls below a threshold. Two consignments of the same commodity
 * from the same origin can attract different duty purely on what was paid for
 * them, which breaks the assumption every landed-cost calculation quietly
 * makes.
 *
 * Awareness only. The module knows the system applies; it cannot know what a
 * merchant paid.
 */
final class EntryPriceSystem extends MeasureRule
{
    public const CODE = 'entry_price_system';

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

        if ($detail === null || !$detail->flags->entryPriceSystem) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'code' => $detail->code(),
            ],
        );
    }
}
