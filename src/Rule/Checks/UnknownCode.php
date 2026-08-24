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
 * A well-formed code that does not exist and never did.
 *
 * Runs only after withdrawn_code has passed, so by the time this fires the
 * baseline lookup has already ruled out the code having been real at some
 * point. What is left is a transcription error, a code from another
 * jurisdiction, or an invention.
 *
 * Silent when the resolution was inconclusive. An unmirrored chapter proves
 * nothing about the code, and reporting a failed sync as thousands of bad
 * commodity codes would destroy a merchant's confidence in everything else the
 * module says.
 */
final class UnknownCode implements RuleInterface
{
    public const CODE = 'unknown_code';

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
        return [WithdrawnCode::CODE];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        if (!$context->outcomeIs(ResolutionOutcome::NotInMirror)) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'code' => $data->hsCode()->code,
                'raw' => $data->hsCode()->raw,
                'baseline_checked' => $context->historic !== null,
            ],
            variant: $context->historic !== null ? 'confirmed' : 'unverified',
            remediation: RemediationHint::requiresInput('set_hs_code'),
        );
    }
}
