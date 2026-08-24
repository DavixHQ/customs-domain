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

/**
 * No fibre composition recorded for goods in a chapter where it decides the
 * classification.
 *
 * Throughout the textile chapters - 50 to 63 - the subheading turns on what
 * the goods are made of before it turns on anything else. A jacket of wool and
 * a jacket of man-made fibres sit in different branches with different duty
 * rates, and without composition neither the module nor the merchant can pick
 * between them.
 *
 * Scoped to those chapters deliberately. Composition is useful everywhere and
 * essential in very few places, and firing on an entire catalogue of
 * electronics would train merchants to ignore the rule in the chapters where
 * it actually matters.
 */
final class MissingComposition implements RuleInterface
{
    public const CODE = 'missing_composition';

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
        $normalised = $data->hsCode();

        if ($normalised->isFailure()) {
            return null;
        }

        $chapter = $this->format->chapterOf($normalised->code());

        if ($chapter === null || !$context->settings->requiresCompositionFor($chapter)) {
            return null;
        }

        $composition = $data->composition();

        if ($composition !== null && trim($composition) !== '') {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'chapter' => $chapter,
                'code' => $normalised->code(),
            ],
            remediation: RemediationHint::requiresInput('set_composition'),
        );
    }
}
