<?php

declare(strict_types=1);

namespace Davix\Customs\Rule\Checks;

use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Issue;
use Davix\Customs\Rule\RemediationHint;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\MeasureCondition;

/**
 * These goods are controlled, and something must be presented for them to move.
 *
 * Scoped to control measures, series B rather than to any commodity
 * carrying a document code, because document codes are everywhere and most of
 * them hang off duty measures offering a cheaper rate. A parka carries fifteen
 * and artillery seventeen; firing on all of them would flag nearly every
 * product in a catalogue, and a merchant who dismisses this rule once will
 * dismiss it when it matters.
 *
 * Every option is reported rather than a single "the licence you need",
 * because the payload does not support that claim. The firearms control offers
 * 9020 "This product is exempt as it is not a firearm", 9026 "manufactured
 * before 1 January 1900", 9044 "private import", and 9023 "DBT Firearms Import
 * License" - a formality, two exemptions and an actual licence, structurally
 * identical and any one of which satisfies the measure. Only the merchant
 * knows which describes their goods, so the module names the control and lists
 * what would satisfy it.
 */
final class LicenceRequired extends MeasureRule
{
    public const CODE = 'licence_required';

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

        $controls = $measures->requiringDocumentation();

        if ($controls->isEmpty()) {
            return null;
        }

        $codes = $measures->documentCodes();

        $declarationOnly = true;

        foreach ($controls->all() as $control) {
            if (!$control->hasDeclarationRoute()) {
                $declarationOnly = false;
                break;
            }
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $declarationOnly ? Severity::Attention : $this->severity(),
            context: [
                'direction' => $context->settings->direction->value,
                'origin' => $this->originOf($data),
                'control' => $controls->first()?->type->description,
                'control_count' => $controls->count(),
                'document_codes' => implode(',', $codes),
                'document_count' => count($codes),
                'documents' => $this->describeDocuments($codes, $context),
            ],
            variant: match (true) {
                $declarationOnly => 'declaration_only',
                !$this->hasKnownOrigin($data) => 'origin_unknown',
                default => 'confirmed',
            },
            remediation: RemediationHint::requiresInput('record_licence'),
        );
    }

    /**
     * @param list<string> $codes
     */
    private function describeDocuments(array $codes, EvaluationContext $context): string
    {
        $described = [];

        foreach ($codes as $code) {
            $description = $context->certificates?->describe($code);

            $described[] = $description === null
                ? $code
                : sprintf('%s: %s', $code, $description);
        }

        return implode(' | ', $described);
    }
}
