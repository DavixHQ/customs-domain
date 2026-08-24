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
 * The customs description is missing or too generic to be usable.
 *
 * Carriers and customs authorities reject declarations reading "gift",
 * "parts", "sample" or "goods" as a matter of routine, because none of them
 * lets anyone verify the classification. This is one of the most common
 * practical causes of a shipment being held, and one of the cheapest to catch.
 *
 * Covers the missing case as well as the vague one, distinguished by variant.
 * They are the same problem from the carrier's point of view — nothing usable
 * in the description field — and splitting them into two rules would put half
 * a merchant's remediation in one dashboard row and half in another.
 *
 * Matching is on whole words against the configured term list, so "gift" fires
 * but "gift wrapping paper, 80gsm" does not. A description that merely
 * contains a generic word alongside real detail is doing its job.
 */
final class VagueDescription implements RuleInterface
{
    public const CODE = 'vague_description';

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
        return [];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $description = trim((string) $data->customsDescription());

        if ($description === '') {
            return $this->issue('missing', '', null);
        }

        $matched = $this->matchedTerm($description, $context->settings->vagueDescriptionTerms);

        if ($matched === null) {
            return null;
        }

        return $this->issue('vague', $description, $matched);
    }

    /**
     * @param list<string> $terms
     */
    private function matchedTerm(string $description, array $terms): ?string
    {
        $normalised = strtolower(trim(preg_replace('/\s+/', ' ', $description) ?? $description));
        $stripped = trim($normalised, " \t\n\r\0\x0B.,;:!-");

        foreach ($terms as $term) {
            if ($stripped === strtolower($term)) {
                return $term;
            }
        }

        return null;
    }

    private function issue(string $variant, string $description, ?string $term): Issue
    {
        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'description' => $description,
                'matched_term' => $term,
            ],
            variant: $variant,
            remediation: RemediationHint::requiresInput('set_customs_description'),
        );
    }
}
