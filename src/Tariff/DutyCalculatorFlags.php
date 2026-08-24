<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * Facts HMRC precomputes about a commodity, from `data.meta.duty_calculator`.
 *
 * Worth reading rather than deriving. Establishing whether a trade defence
 * measure applies by walking the measures array is work HMRC has already done,
 * and their answer is authoritative where ours would be an inference.
 */
final class DutyCalculatorFlags
{
    /** VAT option code meaning a zero rate is available. */
    public const VAT_ZERO_RATE = 'VATZ';

    /**
     * @param array<string, string> $vatOptions Option code to description.
     * @param array<string, mixed> $additionalCodes
     */
    public function __construct(
        public readonly bool $tradeDefence = false,
        public readonly bool $zeroMfnDuty = false,
        public readonly bool $meursingCode = false,
        public readonly bool $entryPriceSystem = false,
        public readonly array $vatOptions = [],
        public readonly array $additionalCodes = [],
        public readonly ?string $source = null,
    ) {
    }

    /**
     * Whether a zero VAT rate is available for these goods.
     *
     * Real and worth surfacing: children's clothing in the apparel chapters
     * carries it, and a merchant charging 20% where zero applies is losing
     * money on every sale.
     */
    public function hasZeroVatOption(): bool
    {
        return array_key_exists(self::VAT_ZERO_RATE, $this->vatOptions);
    }

    public function requiresAdditionalCode(): bool
    {
        return $this->additionalCodes !== [];
    }

    /**
     * Option codes as strings.
     *
     * Cast rather than returned straight from array_keys, because PHP turns a
     * numeric-string key into an integer and the declared type would then be
     * false. Observed codes are lettered - VATZ, VAT - so this is defensive,
     * but the same assumption about document codes was wrong and cost a
     * debugging session.
     *
     * @return list<string>
     */
    public function vatOptionCodes(): array
    {
        return array_map(
            static fn (int|string $code): string => (string) $code,
            array_keys($this->vatOptions),
        );
    }
}