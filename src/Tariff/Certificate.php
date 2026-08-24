<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A document a merchant may present to satisfy a control.
 *
 * Turns 9023 into "DBT Firearms Import License". Without this the licence rule
 * reports a list of opaque codes, which tells a merchant that something is
 * required but nothing about what, or whether it applies to them.
 *
 * The distinction the codes themselves refuse to make is visible here in the
 * descriptions. 9020 reads "This product is exempt as it is not a firearm" and
 * 9023 reads "DBT Firearms Import License" - a statement and a licence,
 * numerically adjacent and structurally identical. Only the text separates
 * them, which is why the module shows the text and lets the merchant decide.
 */
final class Certificate
{
    public function __construct(
        public readonly string $code,
        public readonly string $description,
        public readonly ?string $typeCode = null,
        public readonly ?string $typeDescription = null,
        public readonly ?string $guidance = null,
    ) {
    }

    /**
     * Whether the description reads as an exemption the merchant declares
     * rather than a document they obtain.
     *
     * A heuristic over wording, offered for display ordering and never used to
     * decide whether a control applies. Getting it wrong costs a merchant a
     * moment's reading; using it to suppress a requirement would cost them a
     * shipment.
     */
    public function readsAsExemption(): bool
    {
        $text = strtolower($this->description);

        foreach (['exempt', 'other than', 'not required', 'waiver', 'do not require'] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
