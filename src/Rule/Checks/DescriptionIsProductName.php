<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\RuleInterface;
use Davix\Customs\Rule\Severity;

/**
 * The customs description is a copy of the product name.
 *
 * A strong signal that nobody has actually written a customs description -
 * the field was populated by a bulk copy or an import default. Product names
 * are written to sell ("Summit Parka - Midnight") and customs descriptions are
 * written to identify ("Men's parka, outer shell 100% polyester, with hood").
 * The first tells a customs officer nothing.
 *
 * Runs after vague_description, so a product whose description is both a copy
 * of the name and generic reports once, as the more specific problem.
 */
final class DescriptionIsProductName implements RuleInterface
{
    public const CODE = 'description_is_product_name';

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
        return [VagueDescription::CODE];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $description = $data->customsDescription();

        if ($description === null || trim($description) === '') {
            return null;
        }

        if ($this->normalise($description) !== $this->normalise($data->name())) {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'description' => trim($description),
                'product_name' => $data->name(),
            ],
            remediation: RemediationHint::requiresInput('set_customs_description'),
        );
    }

    private function normalise(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $value) ?? $value;

        return strtolower(trim($collapsed));
    }
}
