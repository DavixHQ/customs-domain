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
 * The country of origin does not correspond to anything the tariff recognises.
 *
 * Duty depends on origin, and origin is matched against the tariff's own
 * geographical areas and their memberships. A value with no matching area
 * silently fails that match: no preferential rate is found, no anti-dumping
 * measure is detected, and the product looks clean when it has simply not been
 * checked. That silence is what makes this Blocked rather than Attention.
 *
 * Two checks, and the second only when the host can support it. Shape is
 * always verified — an origin must be a two-letter ISO country code, which
 * catches free-text entries like "China" or "Made in PRC". Membership is
 * verified only when the host supplies the recognised set from its own mirror;
 * with an empty list the rule checks shape alone rather than rejecting every
 * valid country against nothing.
 */
final class OriginNotInTariffAreas implements RuleInterface
{
    public const CODE = 'origin_not_in_tariff_areas';

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
        return [MissingOrigin::CODE];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        $origin = trim((string) $data->countryOfOrigin());

        if ($origin === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]{2}$/', $origin) !== 1) {
            return $this->issue($origin, 'malformed');
        }

        $settings = $context->settings;

        if (!$settings->canCheckOriginAgainstTariffAreas()) {
            return null;
        }

        if ($settings->recognisesOrigin($origin)) {
            return null;
        }

        return $this->issue($origin, 'unrecognised');
    }

    private function issue(string $origin, string $variant): Issue
    {
        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'origin' => $origin,
            ],
            variant: $variant,
            remediation: RemediationHint::requiresInput('set_country_of_origin'),
        );
    }
}
