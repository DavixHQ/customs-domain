<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\RuleInterface;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\ResolutionOutcome;

/**
 * The code was real once and has since been withdrawn.
 *
 * Heading 6201 was reorganised on 1 January 2022 in the HS2022 restructure, so
 * a supplier spreadsheet listing 6201.93 for a jacket refers to a code that has
 * not existed for four years. That is a very different conversation from a
 * typo, and the merchant deserves to be told which one they have.
 *
 * Deliberately Attention rather than Blocked. The merchant did nothing wrong —
 * their data was correct when they recorded it and the world moved. Where the
 * withdrawn line has exactly one successor the module already knows the answer,
 * so the fix is a single click across every affected product.
 *
 * Gates unknown_code: if this fires, that one is skipped, so a withdrawn code
 * can never also be reported as never having existed.
 */
final class WithdrawnCode implements RuleInterface
{
    public const CODE = 'withdrawn_code';

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
        return [InvalidCodeFormat::CODE];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        if (!$context->outcomeIs(ResolutionOutcome::NotInMirror)) {
            return null;
        }

        $historic = $context->historic;

        // No baseline lookup means we cannot claim the code was ever real.
        // unknown_code will take it instead.
        if ($historic === null || !$historic->existed) {
            return null;
        }

        $successor = $historic->soleSuccessor();

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'code' => $data->hsCode()->code,
                'former_description' => $historic->description,
                'withdrawn_on' => $historic->withdrawnOn?->format('Y-m-d'),
                'successor_code' => $successor,
                'successor_count' => $historic->successorCount(),
            ],
            variant: $this->variantFor($historic->successorCount()),
            remediation: $successor !== null
                ? RemediationHint::automatic('replace_with_successor', ['successor_code' => $successor])
                : RemediationHint::requiresInput('set_hs_code'),
        );
    }

    private function variantFor(int $successorCount): string
    {
        return match (true) {
            $successorCount === 1 => 'with_successor',
            $successorCount > 1 => 'with_several_successors',
            default => 'without_successor',
        };
    }
}
