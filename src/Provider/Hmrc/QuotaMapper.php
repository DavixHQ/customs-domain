<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

use Davix\Customs\Tariff\QuotaDefinition;
use Davix\Customs\Tariff\QuotaSet;
use DateTimeImmutable;

/**
 * Builds quota definitions from the tariff's quota search.
 *
 * Two details the payload forces.
 *
 * Volumes arrive as strings - "1580.0" rather than 1580.0 - so they are parsed
 * once here rather than at every comparison. A string balance compared against
 * zero with a loose operator would treat "0.0" as falsy and an empty string as
 * zero, which is the sort of thing that silently reports every quota as
 * exhausted.
 *
 * Origins hang off quota_order_number_origin rather than the definition, so
 * the geographical areas have to be gathered through that relationship. A
 * quota that applies only to Costa Rica is not an opportunity for a merchant
 * importing from Vietnam.
 */
final class QuotaMapper
{
    public function map(JsonApiDocument $document): QuotaSet
    {
        $quotas = [];

        foreach ($document->allOfType('definition') as $resource) {
            $quota = $this->mapResource($document, $resource);

            if ($quota !== null) {
                $quotas[] = $quota;
            }
        }

        return new QuotaSet($quotas);
    }

    /**
     * The search returns definitions in `data`, not `included`.
     *
     * @param array<string, mixed> $payload
     */
    public function mapArray(array $payload): QuotaSet
    {
        $document = new JsonApiDocument($payload);
        $data = $payload['data'] ?? [];

        if (!is_array($data)) {
            return new QuotaSet();
        }

        $quotas = [];

        foreach ($data as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            /** @var array<string, mixed> $resource */
            $quota = $this->mapResource($document, $resource);

            if ($quota !== null) {
                $quotas[] = $quota;
            }
        }

        return new QuotaSet($quotas);
    }

    public function mapJson(string $json): QuotaSet
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->mapArray($decoded);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function mapResource(JsonApiDocument $document, array $resource): ?QuotaDefinition
    {
        $attributes = JsonApiDocument::attributesOf($resource);

        $sid = $attributes['quota_definition_sid'] ?? null;
        $orderNumber = $this->stringOf($attributes['quota_order_number_id'] ?? null);

        if (!is_int($sid) || $orderNumber === null) {
            return null;
        }

        return new QuotaDefinition(
            sid: $sid,
            orderNumber: $orderNumber,
            status: $this->stringOf($attributes['status'] ?? null) ?? '',
            initialVolume: $this->floatOf($attributes['initial_volume'] ?? null),
            balance: $this->floatOf($attributes['balance'] ?? null),
            measurementUnit: $this->stringOf($attributes['measurement_unit'] ?? null),
            monetaryUnit: $this->stringOf($attributes['monetary_unit'] ?? null),
            validityStart: $this->dateOf($attributes['validity_start_date'] ?? null),
            validityEnd: $this->dateOf($attributes['validity_end_date'] ?? null),
            lastAllocation: $this->dateOf($attributes['last_allocation_date'] ?? null),
            suspensionStart: $this->dateOf($attributes['suspension_period_start_date'] ?? null),
            suspensionEnd: $this->dateOf($attributes['suspension_period_end_date'] ?? null),
            blockingStart: $this->dateOf($attributes['blocking_period_start_date'] ?? null),
            blockingEnd: $this->dateOf($attributes['blocking_period_end_date'] ?? null),
            originCodes: $this->originsOf($document, $resource),
            description: $this->stringOf($attributes['description'] ?? null),
        );
    }

    /**
     * Country codes the quota is open to.
     *
     * Reached through quota_order_number_origin rather than sitting on the
     * definition, and gathered from both the origin resources and the order
     * number, since the payload populates whichever it has.
     *
     * @param array<string, mixed> $resource
     * @return list<string>
     */
    private function originsOf(JsonApiDocument $document, array $resource): array
    {
        $codes = [];

        foreach ($document->resolveMany($resource, 'quota_order_number_origins') as $origin) {
            foreach ($document->referenceIds($origin, 'geographical_area') as $code) {
                $codes[$code] = true;
            }

            $area = $document->resolveOne($origin, 'geographical_area');

            if ($area !== null) {
                $id = $this->stringOf($area['id'] ?? null);

                if ($id !== null) {
                    $codes[$id] = true;
                }
            }
        }

        $orderNumber = $document->resolveOne($resource, 'order_number');

        if ($orderNumber !== null) {
            foreach ($document->referenceIds($orderNumber, 'geographical_areas') as $code) {
                $codes[$code] = true;
            }
        }

        return array_values(array_map('strval', array_keys($codes)));
    }

    private function stringOf(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * Volumes arrive as strings. Anything not numeric becomes null rather than
     * zero, because a null balance means "unknown" and a zero balance means
     * "exhausted" - conflating them reports working quotas as run out.
     */
    private function floatOf(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
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
