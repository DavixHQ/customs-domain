<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Support;

use Davix\Customs\Exception\TariffUnavailableException;
use Davix\Customs\Provider\Hmrc\CertificateMapper;
use Davix\Customs\Provider\Hmrc\CommodityMapper;
use Davix\Customs\Provider\Hmrc\QuotaMapper;
use Davix\Customs\Provider\TariffProviderInterface;
use Davix\Customs\Tariff\CertificateIndex;
use Davix\Customs\Tariff\CommodityDetail;
use Davix\Customs\Tariff\HistoricRecord;
use Davix\Customs\Tariff\Jurisdiction;
use Davix\Customs\Tariff\QuotaSet;
use DateTimeImmutable;

/**
 * A provider that serves recorded responses and counts every call.
 *
 * The counting is the point. Deduplication is invisible in the output of a
 * scan - the results are identical either way - and only shows up as the
 * difference between two minutes and forty. A test that does not count calls
 * cannot tell whether the optimisation is working or has silently regressed.
 */
final class RecordingProvider implements TariffProviderInterface
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @var list<string> */
    public array $commodityCodesRequested = [];

    public function __construct(
        private readonly string $fixtureDirectory,
        private readonly ?TariffUnavailableException $failWith = null,
    ) {
    }

    public function commodity(string $code, ?DateTimeImmutable $asOf = null): CommodityDetail
    {
        $this->record('commodity');
        $this->commodityCodesRequested[] = $code;
        $this->failIfConfigured();

        return (new CommodityMapper())->mapFromJson($this->fixture('commodity-6201401019.json'));
    }

    public function chapter(string $chapter, ?DateTimeImmutable $asOf = null): iterable
    {
        $this->record('chapter');

        return [];
    }

    public function changes(DateTimeImmutable $date): array
    {
        $this->record('changes');

        return [];
    }

    public function certificates(?DateTimeImmutable $asOf = null): CertificateIndex
    {
        $this->record('certificates');
        $this->failIfConfigured();

        return (new CertificateMapper())->mapJson($this->fixture('certificates.json'));
    }

    public function quotas(string $code, ?DateTimeImmutable $asOf = null): QuotaSet
    {
        $this->record('quotas');
        $this->failIfConfigured();

        return (new QuotaMapper())->mapJson($this->fixture('quota-by-commodity.json'));
    }

    public function historicRecord(string $code, DateTimeImmutable $baseline): HistoricRecord
    {
        $this->record('historic');
        $this->failIfConfigured();

        return HistoricRecord::found('Anoraks, of man-made fibres', new DateTimeImmutable('2021-12-31'));
    }

    public function jurisdiction(): Jurisdiction
    {
        return Jurisdiction::Uk;
    }

    public function isAvailable(): bool
    {
        return $this->failWith === null;
    }

    public function callsTo(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    public function totalCalls(): int
    {
        return array_sum($this->calls);
    }

    private function record(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }

    private function failIfConfigured(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }

    private function fixture(string $name): string
    {
        $body = file_get_contents($this->fixtureDirectory . '/' . $name);

        if ($body === false) {
            throw new \RuntimeException(sprintf('Fixture %s is unreadable', $name));
        }

        return $body;
    }
}
