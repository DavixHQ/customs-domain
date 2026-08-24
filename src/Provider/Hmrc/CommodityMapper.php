<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\CommodityDetail;
use Davix\Customs\Tariff\DutyCalculatorFlags;
use Davix\Customs\Tariff\DutyExpression;
use Davix\Customs\Tariff\GeographicalArea;
use Davix\Customs\Tariff\Measure;
use Davix\Customs\Tariff\MeasureComponent;
use Davix\Customs\Tariff\MeasureCondition;
use Davix\Customs\Tariff\MeasureConditionClass;
use Davix\Customs\Tariff\MeasureSet;
use Davix\Customs\Tariff\MeasureType;
use DateTimeImmutable;

/**
 * Builds domain objects from a tariff commodity response.
 *
 * Written against recorded live responses rather than documentation, because
 * the documentation and the payload disagree in ways that matter. The most
 * expensive of these is the productline suffix, which the API spells
 * `producline_suffix` — missing the 'd'. Both spellings are accepted here; a
 * mapper reading only the documented name silently nulls the field, and a null
 * suffix collapses the two lines that share a commodity code into one.
 */
final class CommodityMapper
{
    /** Both spellings occur. Documented one first, observed one second. */
    private const SUFFIX_KEYS = ['productline_suffix', 'producline_suffix'];

    public function map(JsonApiDocument $document): CommodityDetail
    {
        return new CommodityDetail(
            commodity: $this->mapPrimaryCommodity($document),
            measures: $this->mapMeasures($document),
            flags: $this->mapFlags($document),
            ancestors: $this->mapAncestors($document),
            footnotes: $this->mapFootnotes($document),
            basicThirdCountryDuty: $this->tradeSummary($document, 'basic_third_country_duty'),
            preferentialTariffDuty: $this->tradeSummary($document, 'preferential_tariff_duty'),
            preferentialQuotaDuty: $this->tradeSummary($document, 'preferential_quota_duty'),
        );
    }

    public function mapFromJson(string $json): CommodityDetail
    {
        return $this->map(JsonApiDocument::fromJson($json));
    }

    private function mapPrimaryCommodity(JsonApiDocument $document): Commodity
    {
        $data = $document->data();
        $attributes = $document->attributes();

        return $this->mapCommodityAttributes($attributes, $this->intOf($data['id'] ?? null));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function mapCommodityAttributes(array $attributes, ?int $fallbackSid = null): Commodity
    {
        $sid = $this->intOf($attributes['goods_nomenclature_sid'] ?? null) ?? $fallbackSid ?? 0;

        return new Commodity(
            sid: $sid,
            code: $this->stringOf($attributes['goods_nomenclature_item_id'] ?? null) ?? '',
            productlineSuffix: $this->suffixOf($attributes),
            description: $this->stringOf($attributes['description'] ?? null) ?? '',
            declarable: (bool) ($attributes['declarable'] ?? false),
            parentSid: null,
            numberIndents: $this->intOf($attributes['number_indents'] ?? null) ?? 0,
            validityStart: $this->dateOf($attributes['validity_start_date'] ?? null),
            validityEnd: $this->dateOf($attributes['validity_end_date'] ?? null),
            supplementaryUnit: null,
            href: $this->stringOf($attributes['href'] ?? null),
        );
    }

    /**
     * @return list<Commodity>
     */
    private function mapAncestors(JsonApiDocument $document): array
    {
        $ancestors = [];

        foreach ($document->relatedMany('ancestors') as $resource) {
            $ancestors[] = $this->mapCommodityAttributes(
                JsonApiDocument::attributesOf($resource),
                $this->intOf($resource['id'] ?? null),
            );
        }

        return $ancestors;
    }

    private function mapMeasures(JsonApiDocument $document): MeasureSet
    {
        $measures = [];

        foreach ($document->allOfType('measure') as $resource) {
            $measure = $this->mapMeasure($document, $resource);

            if ($measure !== null) {
                $measures[] = $measure;
            }
        }

        return new MeasureSet($measures);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function mapMeasure(JsonApiDocument $document, array $resource): ?Measure
    {
        $attributes = JsonApiDocument::attributesOf($resource);
        $type = $this->mapMeasureType($document->resolveOne($resource, 'measure_type'));

        if ($type === null) {
            return null;
        }

        $id = $this->intOf($attributes['id'] ?? null) ?? $this->intOf($resource['id'] ?? null) ?? 0;

        return new Measure(
            id: $id,
            type: $type,
            import: (bool) ($attributes['import'] ?? false),
            export: (bool) ($attributes['export'] ?? false),
            geographicalArea: $this->mapArea($document, $document->resolveOne($resource, 'geographical_area')),
            excludedAreaCodes: $document->referenceIds($resource, 'excluded_countries'),
            dutyExpression: $this->mapDutyExpression($document->resolveOne($resource, 'duty_expression')),
            components: $this->mapComponents($document, $resource),
            conditions: $this->mapConditions($document, $resource),
            effectiveFrom: $this->dateOf($attributes['effective_start_date'] ?? null),
            effectiveTo: $this->dateOf($attributes['effective_end_date'] ?? null),
            vat: (bool) ($attributes['vat'] ?? false),
            excise: (bool) ($attributes['excise'] ?? false),
            meursing: (bool) ($attributes['meursing'] ?? false),
            orderNumber: $this->orderNumberOf($document, $resource),
            preferenceCode: $this->codeOf($document->resolveOne($resource, 'preference_code'), 'code'),
            additionalCode: $this->codeOf($document->resolveOne($resource, 'additional_code'), 'code'),
            footnoteCodes: $document->referenceIds($resource, 'footnotes'),
        );
    }

    /**
     * @param array<string, mixed>|null $resource
     */
    private function mapMeasureType(?array $resource): ?MeasureType
    {
        if ($resource === null) {
            return null;
        }

        $attributes = JsonApiDocument::attributesOf($resource);

        return new MeasureType(
            id: $this->stringOf($attributes['id'] ?? null) ?? $this->stringOf($resource['id'] ?? null) ?? '',
            description: $this->stringOf($attributes['description'] ?? null) ?? '',
            seriesId: $this->stringOf($attributes['measure_type_series_id'] ?? null),
            seriesDescription: $this->stringOf($attributes['measure_type_series_description'] ?? null),
        );
    }

    /**
     * @param array<string, mixed>|null $resource
     */
    private function mapArea(JsonApiDocument $document, ?array $resource): ?GeographicalArea
    {
        if ($resource === null) {
            return null;
        }

        $attributes = JsonApiDocument::attributesOf($resource);

        return new GeographicalArea(
            id: $this->stringOf($attributes['geographical_area_id'] ?? null)
                ?? $this->stringOf($resource['id'] ?? null) ?? '',
            description: $this->stringOf($attributes['description'] ?? null) ?? '',
            memberCodes: $document->referenceIds($resource, 'children_geographical_areas'),
            sid: $this->intOf($attributes['geographical_area_sid'] ?? null),
        );
    }

    /**
     * @param array<string, mixed>|null $resource
     */
    private function mapDutyExpression(?array $resource): ?DutyExpression
    {
        if ($resource === null) {
            return null;
        }

        $attributes = JsonApiDocument::attributesOf($resource);

        return new DutyExpression(
            base: $this->stringOf($attributes['base'] ?? null) ?? '',
            verbose: $this->stringOf($attributes['verbose_duty'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $resource
     * @return list<MeasureComponent>
     */
    private function mapComponents(JsonApiDocument $document, array $resource): array
    {
        $components = [];

        foreach ($document->resolveMany($resource, 'measure_components') as $found) {
            $attributes = JsonApiDocument::attributesOf($found);

            $components[] = new MeasureComponent(
                dutyExpressionId: $this->stringOf($attributes['duty_expression_id'] ?? null) ?? '',
                dutyAmount: $this->floatOf($attributes['duty_amount'] ?? null),
                monetaryUnitCode: $this->stringOf($attributes['monetary_unit_code'] ?? null),
                measurementUnitCode: $this->stringOf($attributes['measurement_unit_code'] ?? null),
                description: $this->stringOf($attributes['duty_expression_description'] ?? null),
                abbreviation: $this->stringOf($attributes['duty_expression_abbreviation'] ?? null),
            );
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $resource
     * @return list<MeasureCondition>
     */
    private function mapConditions(JsonApiDocument $document, array $resource): array
    {
        $conditions = [];

        foreach ($document->resolveMany($resource, 'measure_conditions') as $found) {
            $attributes = JsonApiDocument::attributesOf($found);

            $conditions[] = new MeasureCondition(
                class: MeasureConditionClass::fromValue(
                    $this->stringOf($attributes['measure_condition_class'] ?? null),
                ),
                conditionCode: $this->stringOf($attributes['condition_code'] ?? null),
                condition: $this->stringOf($attributes['condition'] ?? null),
                action: $this->stringOf($attributes['action'] ?? null),
                actionCode: $this->stringOf($attributes['action_code'] ?? null),
                documentCode: $this->stringOf($attributes['document_code'] ?? null),
                requirement: $this->stringOf($attributes['requirement'] ?? null),
                certificateDescription: $this->stringOf($attributes['certificate_description'] ?? null),
                guidance: $this->stringOf($attributes['guidance_cds'] ?? null),
            );
        }

        return $conditions;
    }

    private function mapFlags(JsonApiDocument $document): DutyCalculatorFlags
    {
        $meta = $document->meta('duty_calculator');

        /** @var array<string, string> $vat */
        $vat = is_array($meta['applicable_vat_options'] ?? null)
            ? $meta['applicable_vat_options']
            : [];
        /** @var array<string, mixed> $additional */
        $additional = is_array($meta['applicable_additional_codes'] ?? null)
            ? $meta['applicable_additional_codes']
            : [];

        return new DutyCalculatorFlags(
            tradeDefence: (bool) ($meta['trade_defence'] ?? false),
            zeroMfnDuty: (bool) ($meta['zero_mfn_duty'] ?? false),
            meursingCode: (bool) ($meta['meursing_code'] ?? false),
            entryPriceSystem: (bool) ($meta['entry_price_system'] ?? false),
            vatOptions: $vat,
            additionalCodes: $additional,
            source: $this->stringOf($meta['source'] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    private function mapFootnotes(JsonApiDocument $document): array
    {
        $footnotes = [];

        foreach ($document->allOfType('footnote') as $resource) {
            $attributes = JsonApiDocument::attributesOf($resource);

            $code = $this->stringOf($attributes['code'] ?? null)
                ?? $this->stringOf($resource['id'] ?? null);

            if ($code !== null) {
                $footnotes[$code] = $this->stringOf($attributes['description'] ?? null) ?? '';
            }
        }

        return $footnotes;
    }

    /**
     * A pre-formatted duty string from the trade summary.
     *
     * These arrive wrapped in markup — `<span>12.00</span> %` — because the
     * tariff's own site highlights the numeral. Markup from an upstream service
     * has no business in a domain object, and a host that renders it unescaped
     * would be injecting someone else's HTML into its admin. Stripped here, at
     * the boundary, rather than left for every consumer to remember.
     */
    private function tradeSummary(JsonApiDocument $document, string $key): ?string
    {
        $summary = $document->related('import_trade_summary');

        if ($summary === null) {
            return null;
        }

        $value = $this->stringOf(JsonApiDocument::attributesOf($summary)[$key] ?? null);

        if ($value === null) {
            return null;
        }

        $stripped = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $stripped === '' ? null : $stripped;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function orderNumberOf(JsonApiDocument $document, array $resource): ?string
    {
        $order = $document->resolveOne($resource, 'order_number');

        if ($order === null) {
            return null;
        }

        $number = $this->stringOf(JsonApiDocument::attributesOf($order)['number'] ?? null);

        return $number ?? $this->stringOf($order['id'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $resource
     */
    private function codeOf(?array $resource, string $key): ?string
    {
        return $this->stringOf(JsonApiDocument::attributesOf($resource)[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function suffixOf(array $attributes): string
    {
        foreach (self::SUFFIX_KEYS as $key) {
            $value = $this->stringOf($attributes[$key] ?? null);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function stringOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private function intOf(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function floatOf(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private function dateOf(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}