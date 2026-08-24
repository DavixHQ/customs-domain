<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\RuleInterface;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Validation\CodeFormat;
use Davix\Customs\Validation\NormalisationFailure;

/**
 * A code was supplied but is not a shape a commodity code can be.
 *
 * Runs after normalisation, so dotted, spaced and Excel-mangled codes have
 * already been repaired and do not reach here. What remains is genuinely
 * wrong: letters, scientific notation that destroyed digits, two codes in one
 * cell, or a digit count no level of the nomenclature uses.
 *
 * A six-digit subheading does *not* fail here. It is a valid code that simply
 * is not specific enough to declare against, which the expansion rule handles
 * by offering the merchant the candidates beneath it. Failing it would tell
 * merchants that the code their supplier gave them and HMRC recognises is
 * invalid.
 *
 * The variant carries the specific reason, so the message can say what is
 * actually wrong rather than "invalid code".
 */
final class InvalidCodeFormat implements RuleInterface
{
    public const CODE = 'invalid_code_format';

    public function __construct(
        private readonly CodeFormat $format = new CodeFormat(),
    ) {
    }

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
        return [MissingHsCode::CODE];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $normalised = $data->hsCode();

        // Guarded rather than assumed: the prerequisite normally catches this,
        // but a merchant can disable that rule, and a disabled prerequisite is
        // treated as passing.
        if ($normalised->failure === NormalisationFailure::Blank) {
            return null;
        }

        if ($normalised->isFailure()) {
            return $this->issue(
                variant: $normalised->failure->value ?? 'unreadable',
                raw: $normalised->raw,
            );
        }

        $result = $this->format->validate($normalised->code());

        if ($result->isValid()) {
            return null;
        }

        return $this->issue(
            variant: $result->failure->value ?? 'invalid',
            raw: $normalised->raw,
            normalised: $normalised->code(),
        );
    }

    private function issue(string $variant, string $raw, ?string $normalised = null): Issue
    {
        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'raw' => $raw,
                'normalised' => $normalised,
                'reason' => $variant,
            ],
            variant: $variant,
            remediation: RemediationHint::requiresInput('set_hs_code'),
        );
    }
}