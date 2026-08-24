<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\DutyCalculatorFlags;

/**
 * These goods may qualify for a zero VAT rate.
 *
 * Not a hypothetical: the very first commodity examined during development — a
 * men's cotton parka — carries a VATZ option, because children's clothing is
 * zero-rated in the UK. A merchant charging 20% where zero applies is losing
 * margin on every sale and has been for as long as the product has existed.
 *
 * Phrased as "may qualify" throughout, and left as an opportunity rather than
 * a problem, because the tariff says the option exists for the commodity and
 * not that it applies to this particular product. Whether a specific garment
 * is a child's size is a question about the goods that no tariff data can
 * answer. Presenting it as a finding rather than a fact is the difference
 * between a useful prompt and a VAT liability.
 *
 * Independent of measures fetched per direction — the flag is precomputed on
 * the commodity — but grouped here because it needs the same commodity detail.
 */
final class VatZeroRatingAvailable extends MeasureRule
{
    public const CODE = 'vat_zero_rating_available';

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
        $detail = $context->detail;

        if ($detail === null || !$detail->flags->hasZeroVatOption()) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'code' => $detail->code(),
                'zero_rate_option' => DutyCalculatorFlags::VAT_ZERO_RATE,
                'options' => implode(',', $detail->flags->vatOptionCodes()),
                'standard_option' => $detail->flags->vatOptions['VAT'] ?? null,
            ],
        );
    }
}
