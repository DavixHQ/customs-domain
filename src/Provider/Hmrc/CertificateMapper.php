<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

use Davix\Customs\Tariff\Certificate;
use Davix\Customs\Tariff\CertificateIndex;

/**
 * Builds the certificate index from the tariff's certificates listing.
 *
 * The resource id is the type code and certificate code run together - type 9
 * plus code 023 gives 9023 - which is exactly the form document codes take on
 * measure conditions, so no reassembly is needed.
 *
 * Descriptions carry HTML. Several contain anchor tags linking to legislation,
 * and one merchant-facing string with a raw `<a href>` in it would be either
 * rendered as markup nobody vetted or escaped into visible tag soup. Stripped
 * here at the boundary, as with the trade summary.
 */
final class CertificateMapper
{
    public function map(JsonApiDocument $document): CertificateIndex
    {
        $certificates = [];

        foreach ($document->allOfType('certificate') as $resource) {
            $certificate = $this->mapResource($resource);

            if ($certificate !== null) {
                $certificates[] = $certificate;
            }
        }

        return new CertificateIndex($certificates);
    }

    /**
     * The listing returns certificates in `data` rather than `included`.
     *
     * @param array<string, mixed> $payload
     */
    public function mapArray(array $payload): CertificateIndex
    {
        $data = $payload['data'] ?? [];

        if (!is_array($data)) {
            return new CertificateIndex();
        }

        $certificates = [];

        foreach ($data as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            /** @var array<string, mixed> $resource */
            $certificate = $this->mapResource($resource);

            if ($certificate !== null) {
                $certificates[] = $certificate;
            }
        }

        return new CertificateIndex($certificates);
    }

    public function mapJson(string $json): CertificateIndex
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->mapArray($decoded);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function mapResource(array $resource): ?Certificate
    {
        $attributes = JsonApiDocument::attributesOf($resource);

        $code = $this->stringOf($resource['id'] ?? null)
            ?? $this->assembleCode($attributes);

        if ($code === null) {
            return null;
        }

        $description = $this->plainText($this->stringOf($attributes['description'] ?? null) ?? '');

        if ($description === '') {
            return null;
        }

        return new Certificate(
            code: $code,
            description: $description,
            typeCode: $this->stringOf($attributes['certificate_type_code'] ?? null),
            typeDescription: $this->stringOf($attributes['certificate_type_description'] ?? null),
            guidance: $this->plainTextOrNull($this->stringOf($attributes['guidance_cds'] ?? null)),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function assembleCode(array $attributes): ?string
    {
        $type = $this->stringOf($attributes['certificate_type_code'] ?? null);
        $code = $this->stringOf($attributes['certificate_code'] ?? null);

        return $type !== null && $code !== null ? $type . $code : null;
    }

    private function plainText(string $value): string
    {
        $withoutTags = strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $value));
        $decoded = html_entity_decode($withoutTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $decoded));
    }

    private function plainTextOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $plain = $this->plainText($value);

        return $plain === '' ? null : $plain;
    }

    private function stringOf(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return is_int($value) ? (string) $value : null;
    }
}
