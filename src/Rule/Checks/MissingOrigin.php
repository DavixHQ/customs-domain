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
 * No country of origin set.
 *
 * Independent of the commodity code entirely, which is why it has no
 * prerequisites: a product can have a perfect ten-digit classification and
 * still be undeclarable for want of an origin. It also decides which duty rate
 * applies, so without it no preference or anti-dumping conclusion is
 * trustworthy.
 *
 * Attention rather than blocked, because merchants routinely hold this in a
 * supplier record rather than the catalogue, and calling it blocked would
 * flood the dashboard on first scan for a problem that is usually a bulk
 * import away from fixed.
 */
final class MissingOrigin implements RuleInterface
{
    public const CODE = 'missing_origin';

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
        $origin = $data->countryOfOrigin();

        if ($origin !== null && trim($origin) !== '') {
            return null;
        }

        return new Issue(
            ruleCode: self::CODE,
            severity: $this->severity(),
            context: [
                'sku' => $data->sku(),
            ],
            remediation: RemediationHint::requiresInput('set_country_of_origin'),
        );
    }
}
