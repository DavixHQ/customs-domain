<?php

declare(strict_types=1);

namespace Davix\Customs\Product;

use Davix\Customs\Tariff\MeasuredProperty;
use Davix\Customs\Validation\CodeNormaliser;
use Davix\Customs\Validation\NormalisationResult;
use DateTimeImmutable;

/**
 * A plain immutable carrier for one product's customs data.
 *
 * The host application may implement ProductCustomsDataInterface directly over
 * its own objects, Magento will, to avoid materialising every attribute for
 * every product in a batch, but this covers tests, CLI tooling and any
 * consumer that already has the values to hand.
 */
final class ProductCustomsData implements ProductCustomsDataInterface
{
    public function __construct(
        private readonly string $identifier,
        private readonly string $sku,
        private readonly string $name,
        private readonly NormalisationResult $hsCode,
        private readonly ?string $countryOfOrigin = null,
        private readonly ?string $customsDescription = null,
        private readonly ?float $netWeight = null,
        private readonly ?float $grossWeight = null,
        private readonly ?float $supplementaryQuantity = null,
        private readonly ?string $composition = null,
        private readonly ?string $intendedUse = null,
        private readonly ?string $manufacturer = null,
        private readonly ?DateTimeImmutable $verifiedAt = null,
        /** @var array<string, float> */
        private readonly array $measuredProperties = [],
    ) {
    }

    /**
     * Build from a raw, unnormalised commodity code.
     *
     * Convenience for callers holding merchant input rather than an already
     * normalised result. Pass a shared normaliser if constructing many.
     */
    /**
     * @param array<string, float> $measuredProperties
     */
    public static function fromRawCode(
        string $identifier,
        string $sku,
        string $name,
        ?string $rawHsCode,
        ?CodeNormaliser $normaliser = null,
        ?string $countryOfOrigin = null,
        ?string $customsDescription = null,
        ?float $netWeight = null,
        ?float $grossWeight = null,
        ?float $supplementaryQuantity = null,
        ?string $composition = null,
        ?string $intendedUse = null,
        ?string $manufacturer = null,
        ?DateTimeImmutable $verifiedAt = null,
        array $measuredProperties = [],
    ): self {
        $normaliser ??= new CodeNormaliser();

        return new self(
            $identifier,
            $sku,
            $name,
            $normaliser->normalise($rawHsCode),
            $countryOfOrigin,
            $customsDescription,
            $netWeight,
            $grossWeight,
            $supplementaryQuantity,
            $composition,
            $intendedUse,
            $manufacturer,
            $verifiedAt,
            $measuredProperties,
        );
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function countryOfOrigin(): ?string
    {
        return $this->countryOfOrigin;
    }

    public function hsCode(): NormalisationResult
    {
        return $this->hsCode;
    }

    public function customsDescription(): ?string
    {
        return $this->customsDescription;
    }

    public function netWeight(): ?float
    {
        return $this->netWeight;
    }

    public function grossWeight(): ?float
    {
        return $this->grossWeight;
    }

    public function supplementaryQuantity(): ?float
    {
        return $this->supplementaryQuantity;
    }

    public function composition(): ?string
    {
        return $this->composition;
    }

    public function intendedUse(): ?string
    {
        return $this->intendedUse;
    }

    public function manufacturer(): ?string
    {
        return $this->manufacturer;
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    /**
     * Net weight is folded in automatically, since it is already a first-class
     * field and a host should not have to supply it twice.
     *
     * @return array<string, float>
     */
    public function measuredProperties(): array
    {
        $properties = $this->measuredProperties;

        if ($this->netWeight !== null && $this->netWeight > 0.0) {
            $properties[MeasuredProperty::NET_WEIGHT] ??= $this->netWeight;
        }

        return $properties;
    }
}