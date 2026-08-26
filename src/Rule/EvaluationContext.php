<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

use Davix\Customs\Tariff\CertificateIndex;
use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\CommodityDetail;
use Davix\Customs\Tariff\MeasureSet;
use Davix\Customs\Tariff\QuotaSet;
use Davix\Customs\Tariff\HistoricRecord;
use Davix\Customs\Tariff\Resolution;
use Davix\Customs\Tariff\ResolutionOutcome;
use DateTimeImmutable;

/**
 * Everything a rule needs beyond the product's own data.
 *
 * Carries the whole Resolution rather than picking pieces out of it. A rule
 * seeing only a null commodity and an empty candidate list cannot tell whether
 * the code is wrong or whether the chapter sync failed, and those must not be
 * reported the same way, one is the merchant's problem and the other is
 * entirely the module's. Holding the outcome keeps that distinction alive all
 * the way to the rule that acts on it.
 *
 * Exists so the rule interface stops changing. Measure data, quota status and
 * duty calculator flags all arrive later; each becomes a property here rather
 * than another parameter on evaluate(), which would break every rule already
 * written.
 */
final class EvaluationContext
{
    /**
     * @param Resolution|null $resolution Outcome of resolving the product's code
     *        against the mirror, or null when resolution was not attempted.
     * @param HistoricRecord|null $historic Result of the baseline lookup that
     *        separates a withdrawn code from one that never existed. Null when
     *        the lookup was not performed, which is treated as "cannot prove
     *        it was ever real" rather than as proof it was not.
     * @param CommodityDetail|null $detail Measures fetched for the resolved
     *        commodity. Null on an offline scan, where the mirror answers
     *        everything and no HTTP happens per product. Measure rules stay
     *        silent rather than guessing when it is absent.
     * @param CertificateIndex|null $certificates Document codes resolved to
     *        descriptions. Fetched once per scan rather than per product.
     *        Without it a control reports "9023" instead of "DBT Firearms
     *        Import License", which tells a merchant something is required but
     *        nothing about what.
     * @param QuotaSet|null $quotas Quota balances for the resolved commodity.
     *        A measure states that a preferential rate exists under an order
     *        number; only the balance says whether it is still available.
     */
    public function __construct(
        public readonly DateTimeImmutable $evaluatedAt,
        public readonly RuleSettings $settings,
        public readonly ?Resolution $resolution = null,
        public readonly ?HistoricRecord $historic = null,
        public readonly ?CommodityDetail $detail = null,
        public readonly ?CertificateIndex $certificates = null,
        public readonly ?QuotaSet $quotas = null,
    ) {
    }

    /**
     * Convenience constructor for tests and simple callers.
     */
    public static function at(
        string $date = 'now',
        ?RuleSettings $settings = null,
        ?Resolution $resolution = null,
        ?HistoricRecord $historic = null,
        ?CommodityDetail $detail = null,
        ?CertificateIndex $certificates = null,
        ?QuotaSet $quotas = null,
    ): self {
        return new self(
            new DateTimeImmutable($date),
            $settings ?? new RuleSettings(),
            $resolution,
            $historic,
            $detail,
            $certificates,
            $quotas,
        );
    }

    public function hasResolution(): bool
    {
        return $this->resolution !== null;
    }

    public function outcome(): ?ResolutionOutcome
    {
        return $this->resolution?->outcome;
    }

    public function outcomeIs(ResolutionOutcome $outcome): bool
    {
        return $this->resolution !== null && $this->resolution->outcome === $outcome;
    }

    public function isConclusive(): bool
    {
        return $this->resolution !== null && $this->resolution->isConclusive();
    }

    public function resolvedCommodity(): ?Commodity
    {
        return $this->resolution?->commodity;
    }

    public function hasResolvedCommodity(): bool
    {
        return $this->resolution?->commodity !== null;
    }

    public function matchedLine(): ?Commodity
    {
        return $this->resolution?->matchedLine;
    }

    /**
     * @return list<Commodity>
     */
    public function candidates(): array
    {
        return $this->resolution->candidates ?? [];
    }

    public function candidateCount(): int
    {
        return count($this->candidates());
    }

    public function isAmbiguous(): bool
    {
        return $this->resolution !== null && $this->resolution->isAmbiguous();
    }

    public function withResolution(?Resolution $resolution): self
    {
        return new self($this->evaluatedAt, $this->settings, $resolution, $this->historic, $this->detail, $this->certificates, $this->quotas);
    }

    public function withHistoric(?HistoricRecord $historic): self
    {
        return new self($this->evaluatedAt, $this->settings, $this->resolution, $historic, $this->detail, $this->certificates, $this->quotas);
    }

    public function withDetail(?CommodityDetail $detail): self
    {
        return new self($this->evaluatedAt, $this->settings, $this->resolution, $this->historic, $detail, $this->certificates, $this->quotas);
    }

    public function withSettings(RuleSettings $settings): self
    {
        return new self($this->evaluatedAt, $settings, $this->resolution, $this->historic, $this->detail, $this->certificates, $this->quotas);
    }

    public function withCertificates(?CertificateIndex $certificates): self
    {
        return new self(
            $this->evaluatedAt,
            $this->settings,
            $this->resolution,
            $this->historic,
            $this->detail,
            $certificates,
            $this->quotas,
        );
    }

    public function withQuotas(?QuotaSet $quotas): self
    {
        return new self(
            $this->evaluatedAt,
            $this->settings,
            $this->resolution,
            $this->historic,
            $this->detail,
            $this->certificates,
            $quotas,
        );
    }

    /**
     * Describe a document code, falling back to the code when the index is
     * absent or does not hold it.
     */
    public function describeDocument(string $code): string
    {
        return $this->certificates?->describe($code) ?? $code;
    }

    public function hasMeasures(): bool
    {
        return $this->detail !== null;
    }

    /**
     * Measures narrowed to the direction the merchant trades in.
     */
    public function measuresForDirection(): ?MeasureSet
    {
        return $this->detail?->measures->forDirection($this->settings->direction);
    }
}
