<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * What kind of measure this is.
 *
 * The series is the useful part. Reading it is far more reliable than
 * inferring intent from conditions: series A means prohibition regardless of
 * how the conditions are worded, and series B covers every control and licence
 * from firearms to cat and dog fur.
 */
final class MeasureType
{
    public const THIRD_COUNTRY_DUTY = '103';
    public const SUPPLEMENTARY_UNIT = '109';
    public const TARIFF_PREFERENCE = '142';
    public const PREFERENTIAL_QUOTA = '143';
    public const IMPORT_PROHIBITION = '277';
    public const VALUE_ADDED_TAX = '305';
    public const ADDITIONAL_DUTIES = '695';

    public function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly ?string $seriesId = null,
        public readonly ?string $seriesDescription = null,
    ) {
    }

    public function isInSeries(string $series): bool
    {
        return $this->seriesId === $series;
    }

    public function isProhibition(): bool
    {
        return $this->isInSeries(MeasureTypeSeries::PROHIBITION);
    }

    /**
     * Controls and licences: import and export restrictions requiring a
     * document, an authority's permission, or both.
     */
    public function isControl(): bool
    {
        return $this->isInSeries(MeasureTypeSeries::CONTROL);
    }

    public function isDuty(): bool
    {
        return $this->isInSeries(MeasureTypeSeries::DUTY);
    }

    public function isAdditionalDuty(): bool
    {
        return $this->isInSeries(MeasureTypeSeries::ADDITIONAL_DUTY);
    }

    public function isVat(): bool
    {
        return $this->isInSeries(MeasureTypeSeries::VAT);
    }

    public function isSupplementaryUnit(): bool
    {
        return $this->id === self::SUPPLEMENTARY_UNIT
            || $this->isInSeries(MeasureTypeSeries::SUPPLEMENTARY_UNIT);
    }

    public function isPreference(): bool
    {
        return $this->id === self::TARIFF_PREFERENCE
            || $this->id === self::PREFERENTIAL_QUOTA;
    }
}
