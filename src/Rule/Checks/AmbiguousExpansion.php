<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\RuleInterface;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\Commodity;

/**
 * The code is real but describes a group rather than a specific commodity.
 *
 * A six-digit subheading is what most merchants hold and what most suppliers
 * send. It is correct on an export declaration and HMRC recognises it, it is
 * simply not specific enough to declare against on import, and more than one
 * ten-digit line sits beneath it.
 *
 * Not automatically fixable, and it should not be: choosing between
 * classifications is a decision with legal consequences and the module has no
 * business guessing. What it can do is make the decision cheap. Presenting
 * three candidates with their official descriptions and duty rates turns a
 * research task into a five-second choice, which is why the candidate list
 * travels in the issue context rather than being looked up again by the UI.
 *
 * Where net weight has already narrowed the field, that is reported too, it
 * is the most persuasive argument for populating net weight that the module
 * can make.
 */
final class AmbiguousExpansion implements RuleInterface
{
    public const CODE = 'ambiguous_expansion';

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
        return [UnknownCode::CODE];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $resolution = $context->resolution;

        if ($resolution === null || !$resolution->isAmbiguous()) {
            return null;
        }

        $candidateCodes = array_map(
            static fn (Commodity $candidate): string => $candidate->code,
            $resolution->candidates,
        );

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'code' => $data->hsCode()->code,
                'matched_description' => $resolution->matchedLine?->description,
                'candidate_count' => $resolution->candidateCount(),
                'candidate_codes' => implode(',', $candidateCodes),
                'narrowed_by_measurement' => $resolution->narrowedByMeasurement,
                'eliminated_by_measurement' => $resolution->candidatesEliminated(),
            ],
            variant: $resolution->narrowedByMeasurement ? 'narrowed' : 'unnarrowed',
            remediation: RemediationHint::requiresInput('choose_commodity_code', [
                'candidate_codes' => implode(',', $candidateCodes),
            ]),
        );
    }
}
