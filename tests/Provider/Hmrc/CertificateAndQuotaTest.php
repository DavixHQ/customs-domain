<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Provider\Hmrc;

use Davix\Customs\Product\ProductCustomsData;
use Davix\Customs\Provider\Hmrc\CertificateMapper;
use Davix\Customs\Provider\Hmrc\CommodityMapper;
use Davix\Customs\Provider\Hmrc\QuotaMapper;
use Davix\Customs\Rule\Checks\LicenceRequired;
use Davix\Customs\Rule\Checks\QuotaExhausted;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\RuleSettings;
use Davix\Customs\Tariff\CertificateIndex;
use Davix\Customs\Tariff\QuotaDefinition;
use Davix\Customs\Tariff\QuotaSet;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Replays the recorded certificates listing and quota search.
 */
final class CertificateAndQuotaTest extends TestCase
{
    private function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../../Fixtures/Api/' . $name);

        self::assertIsString($body, sprintf('Fixture %s is unreadable', $name));

        return $body;
    }

    private function certificates(): CertificateIndex
    {
        return (new CertificateMapper())->mapJson($this->fixture('certificates.json'));
    }

    private function quotas(): QuotaSet
    {
        return (new QuotaMapper())->mapJson($this->fixture('quota-by-commodity.json'));
    }

    // ---------------------------------------------------------- certificates

    public function testTheWholeListingIndexes(): void
    {
        self::assertSame(596, $this->certificates()->count());
    }

    /**
     * @return array<string, array{non-empty-string, non-empty-string}>
     */
    public static function documentCodeProvider(): array
    {
        return [
            'firearms exemption' => ['9020', 'This product is exempt as it is not a firearm.'],
            'firearms licence' => ['9023', 'DBT Firearms Import License'],
            'cat and dog fur' => ['Y922', 'Other than cats and dogs fur'],
            'seal products' => ['Y032', 'Goods other than seal products'],
        ];
    }

    /**
     * @param non-empty-string $expected
     */
    #[DataProvider('documentCodeProvider')]
    public function testDocumentCodesResolveToDescriptions(string $code, string $expected): void
    {
        self::assertStringStartsWith($expected, (string) $this->certificates()->describe($code));
    }

    /**
     * Every code the measure rules emit must resolve, or the licence rule
     * falls back to reporting a bare number.
     */
    public function testEveryCodeOurRulesEmitIsPresent(): void
    {
        $index = $this->certificates();

        foreach (['9020', '9023', '9026', '9044', 'Y922', 'Y032', 'C679', 'C680', 'C683', 'C990', 'U071'] as $code) {
            self::assertTrue($index->has($code), sprintf('%s is missing from the index', $code));
        }
    }

    /**
     * Descriptions carry anchor tags linking to legislation. Passing those to
     * a host would mean either rendering markup nobody vetted or escaping it
     * into visible tag soup.
     */
    public function testMarkupIsStripped(): void
    {
        foreach ($this->certificates()->describeAll(['Y032', 'C679', '9023']) as $code => $description) {
            self::assertStringNotContainsString('<', $description, sprintf('%s still carries markup', $code));
        }
    }

    public function testUnknownCodesResolveToNull(): void
    {
        self::assertNull($this->certificates()->describe('ZZZZ'));
        self::assertFalse($this->certificates()->has('ZZZZ'));
    }

    public function testLookupIsCaseInsensitive(): void
    {
        self::assertSame(
            $this->certificates()->describe('Y922'),
            $this->certificates()->describe('y922'),
        );
    }

    /**
     * The wording heuristic and the structural condition class should agree.
     * They are derived independently - one from the description text, one from
     * measure_condition_class - so disagreement would mean one of them is
     * reading the data wrongly.
     */
    public function testTheExemptionHeuristicAgreesWithTheStructuralSignal(): void
    {
        $index = $this->certificates();

        foreach (['9020', 'Y922', 'Y032'] as $code) {
            self::assertTrue($index->find($code)?->readsAsExemption(), $code);
        }

        foreach (['9023', 'C679'] as $code) {
            self::assertFalse($index->find($code)?->readsAsExemption(), $code);
        }
    }

    public function testTheLicenceRuleReportsDescriptionsRatherThanCodes(): void
    {
        $detail = (new CommodityMapper())->mapFromJson($this->fixture('commodity-9301100000.json'));

        $issue = (new LicenceRequired())->evaluate(
            ProductCustomsData::fromRawCode('1', 'SKU', 'Rifle', '9301100000', countryOfOrigin: 'CN'),
            new EvaluationContext(
                new DateTimeImmutable('2026-08-22'),
                new RuleSettings(),
                null,
                null,
                $detail,
                $this->certificates(),
            ),
        );

        self::assertNotNull($issue);
        self::assertStringContainsString(
            'DBT Firearms Import License',
            (string) $issue->contextValue('documents'),
        );
    }

    // ---------------------------------------------------------------- quotas

    public function testQuotaDefinitionIsMapped(): void
    {
        $quota = $this->quotas()->first();

        self::assertNotNull($quota);
        self::assertSame(27299, $quota->sid);
        self::assertSame('057031', $quota->orderNumber);
        self::assertSame('Open', $quota->status);
        self::assertSame('Number of items (p/st)', $quota->measurementUnit);
    }

    /**
     * Volumes arrive as strings. A string balance compared loosely against
     * zero would report working quotas as exhausted.
     */
    public function testStringVolumesAreParsedToNumbers(): void
    {
        $quota = $this->quotas()->first();

        self::assertNotNull($quota);
        self::assertSame(1580.0, $quota->initialVolume);
        self::assertSame(1580.0, $quota->balance);
        self::assertSame(1.0, $quota->remainingFraction());
    }

    /**
     * Origins hang off quota_order_number_origin rather than the definition.
     * A quota open only to Costa Rica is not an opportunity for Vietnamese
     * stock.
     */
    public function testOriginsAreGatheredThroughTheOrderNumberOrigin(): void
    {
        $quota = $this->quotas()->first();

        self::assertNotNull($quota);
        self::assertSame(['CR'], $quota->originCodes);
        self::assertTrue($quota->appliesToOrigin('CR'));
        self::assertFalse($quota->appliesToOrigin('VN'));
    }

    public function testOrderNumberLookupIgnoresLeadingZeroes(): void
    {
        self::assertNotNull($this->quotas()->forOrderNumber('57031'));
        self::assertNotNull($this->quotas()->forOrderNumber('057031'));
        self::assertNull($this->quotas()->forOrderNumber('999999'));
    }

    /**
     * A null balance means unknown and a zero balance means exhausted.
     * Conflating them reports working quotas as run out.
     */
    public function testAnUnknownBalanceIsNotTreatedAsExhausted(): void
    {
        $unknown = new QuotaDefinition(sid: 1, orderNumber: '1', status: 'Open', balance: null);

        self::assertFalse($unknown->isExhausted());
        self::assertNull($unknown->remainingFraction());
    }

    // ------------------------------------------------------------ quota rule

    /**
     * @return array<string, array{float, ?string}>
     */
    public static function balanceProvider(): array
    {
        return [
            'used up' => [0.0, 'used_up'],
            'running low' => [100.0, 'running_low'],
            'healthy' => [1400.0, null],
        ];
    }

    #[DataProvider('balanceProvider')]
    public function testTheQuotaRuleFiresOnBalance(float $balance, ?string $expected): void
    {
        $issue = (new QuotaExhausted())->evaluate(
            ProductCustomsData::fromRawCode('1', 'SKU', 'Parka', '6201401019', countryOfOrigin: 'CR'),
            $this->quotaContext($balance),
        );

        self::assertSame($expected, $issue?->variant);
    }

    /**
     * The number that makes the issue a decision rather than a fact needing
     * research: what the merchant pays instead.
     */
    public function testTheStandardRateIsReported(): void
    {
        $issue = (new QuotaExhausted())->evaluate(
            ProductCustomsData::fromRawCode('1', 'SKU', 'Parka', '6201401019', countryOfOrigin: 'CR'),
            $this->quotaContext(0.0),
        );

        self::assertSame('12.00 %', $issue?->contextValue('standard_rate'));
    }

    public function testTheQuotaRuleStaysSilentForOtherOrigins(): void
    {
        self::assertNull((new QuotaExhausted())->evaluate(
            ProductCustomsData::fromRawCode('1', 'SKU', 'Parka', '6201401019', countryOfOrigin: 'VN'),
            $this->quotaContext(0.0),
        ));
    }

    public function testTheQuotaRuleStaysSilentWithoutAnOriginOrQuotas(): void
    {
        $rule = new QuotaExhausted();
        $product = ProductCustomsData::fromRawCode('1', 'SKU', 'Parka', '6201401019', countryOfOrigin: null);

        self::assertNull($rule->evaluate($product, $this->quotaContext(0.0)));

        self::assertNull($rule->evaluate(
            ProductCustomsData::fromRawCode('1', 'SKU', 'Parka', '6201401019', countryOfOrigin: 'CR'),
            EvaluationContext::at('2026-06-15'),
        ));
    }

    private function quotaContext(float $balance): EvaluationContext
    {
        $detail = (new CommodityMapper())->mapFromJson($this->fixture('commodity-6201401019.json'));

        $quotas = new QuotaSet([
            new QuotaDefinition(
                sid: 27299,
                orderNumber: '057031',
                status: $balance > 0.0 ? 'Open' : 'Exhausted',
                initialVolume: 1580.0,
                balance: $balance,
                measurementUnit: 'Number of items (p/st)',
                validityStart: new DateTimeImmutable('2026-01-01'),
                validityEnd: new DateTimeImmutable('2026-12-31'),
                originCodes: ['CR'],
            ),
        ]);

        return new EvaluationContext(
            new DateTimeImmutable('2026-06-15'),
            new RuleSettings(),
            null,
            null,
            $detail,
            null,
            $quotas,
        );
    }
}