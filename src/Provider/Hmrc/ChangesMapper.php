<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

use Davix\Customs\Tariff\ChangeRecord;
use DateTimeImmutable;

/**
 * Builds change records from the daily changes feed.
 *
 * The feed is retained for a limited window, so a merchant whose store has
 * been offline longer than that gets an empty response rather than an error.
 * That is a materially different situation from "nothing changed", and a sync
 * that conflates them will quietly report all-clear while the tariff moves
 * underneath it. This mapper only reports what it read; distinguishing the two
 * is the caller's job, and the reason the client tracks its last successful
 * poll separately.
 */
final class ChangesMapper
{
    /**
     * @return list<ChangeRecord>
     */
    public function map(JsonApiDocument $document): array
    {
        $records = [];

        foreach ($document->allOfType('change') as $resource) {
            $record = $this->mapResource($resource);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<ChangeRecord>
     */
    public function mapArray(array $payload): array
    {
        $records = [];
        $data = $payload['data'] ?? [];

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            /** @var array<string, mixed> $resource */
            $record = $this->mapResource($resource);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @return list<ChangeRecord>
     */
    public function mapJson(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->mapArray($decoded);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function mapResource(array $resource): ?ChangeRecord
    {
        $attributes = JsonApiDocument::attributesOf($resource);

        $sid = $attributes['goods_nomenclature_sid'] ?? null;
        $code = $attributes['goods_nomenclature_item_id'] ?? null;

        if (!is_int($sid) || !is_string($code)) {
            return null;
        }

        $changedOn = null;
        $date = $attributes['change_date'] ?? null;

        if (is_string($date) && trim($date) !== '') {
            try {
                $changedOn = new DateTimeImmutable($date);
            } catch (\Exception) {
                $changedOn = null;
            }
        }

        $suffix = $attributes['productline_suffix'] ?? $attributes['producline_suffix'] ?? '';

        return new ChangeRecord(
            goodsNomenclatureSid: $sid,
            code: $code,
            changeType: is_string($attributes['change_type'] ?? null)
                ? $attributes['change_type']
                : '',
            changedOn: $changedOn,
            productlineSuffix: is_string($suffix) ? $suffix : '',
            endLine: (bool) ($attributes['end_line'] ?? false),
        );
    }
}
