<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * Every certificate the tariff defines, looked up by code.
 *
 * The whole set arrives in one unpaginated response and runs to a few hundred
 * entries, so it is fetched once and held rather than resolved per document.
 * A scan reporting controls across a catalogue would otherwise make one call
 * per code per product.
 */
final class CertificateIndex
{
    /** @var array<string, Certificate> */
    private array $byCode = [];

    /**
     * @param iterable<Certificate> $certificates
     */
    public function __construct(iterable $certificates = [])
    {
        foreach ($certificates as $certificate) {
            $this->byCode[strtoupper($certificate->code)] = $certificate;
        }
    }

    public function find(string $code): ?Certificate
    {
        return $this->byCode[strtoupper(trim($code))] ?? null;
    }

    public function describe(string $code): ?string
    {
        return $this->find($code)?->description;
    }

    /**
     * Descriptions for a set of codes, keyed by code, skipping any the index
     * does not hold.
     *
     * @param list<string> $codes
     * @return array<string, string>
     */
    public function describeAll(array $codes): array
    {
        $described = [];

        foreach ($codes as $code) {
            $description = $this->describe($code);

            if ($description !== null) {
                $described[$code] = $description;
            }
        }

        return $described;
    }

    public function has(string $code): bool
    {
        return $this->find($code) !== null;
    }

    public function count(): int
    {
        return count($this->byCode);
    }

    public function isEmpty(): bool
    {
        return $this->byCode === [];
    }
}
