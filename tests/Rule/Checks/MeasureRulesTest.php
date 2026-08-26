<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Rule\Checks;

use Davix\Customs\Product\ProductCustomsData;
use Davix\Customs\Provider\Hmrc\ChapterCsvParser;
use Davix\Customs\Provider\Hmrc\CommodityMapper;
use Davix\Customs\Rule\Checks\AdditionalDutyApplies;
use Davix\Customs\Rule\Checks\CodeExpiringSoon;
use Davix\Customs\Rule\Checks\LicenceRequired;
use Davix\Customs\Rule\Checks\MissingSupplementaryUnits;
use Davix\Customs\Rule\Checks\PreferenceAvailable;
use Davix\Customs\Rule\Checks\ProhibitedGoods;
use Davix\Customs\Rule\Checks\VatZeroRatingAvailable;
use Davix\Customs\Rule\DefaultRuleSet;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\RuleSettings;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\CommodityDetail;
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\InMemoryCommodityRepository;
use Davix\Customs\Tariff\TradeDirection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Exercised against recorded live payloads rather than constructed measures,
 * because every correction in this rule set came from real data contradicting
 * a reasonable-looking assumption.
 */
final class MeasureRulesTest extends TestCase
{
    private function detail(string $fixture): CommodityDetail
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/Api/' . $fixture);

        self::assertIsString($json);

        return (new CommodityMapper())->mapFromJson($json);
    }

    private function parka(): CommodityDetail
    {
        return $this->detail('commodity-6201401019.json');
    }

    private function artillery(): CommodityDetail
    {
        return $this->detail('commodity-9301100000.json');
    }

    private function product(?string $origin = 'VN', ?float $supplementary = null): ProductCustomsData
    {
        return ProductCustomsData::fromRawCode(
            identifier: '1',
            sku: 'SKU-1',
            name: 'Summit Parka',
            rawHsCode: '6201301011',
            countryOfOrigin: $origin,
            customsDescription: "Men's parka, outer shell 100% cotton",
            netWeight: 1.2,
            grossWeight: 1.45,
            supplementaryQuantity: $supplementary,
            composition: '100% cotton',
        );
    }

    private function context(
        CommodityDetail $detail,
        TradeDirection $direction = TradeDirection::Import,
        string $date = '2026-08-22',
    ): EvaluationContext {
        return new EvaluationContext(
            new DateTimeImmutable($date),
            new RuleSettings(direction: $direction),
            null,
            null,
            $detail,
        );
    }

    // Prohibitions

    /**
     * Nearly every control measure carries a negative condition reading "not
     * allowed after control" the branch taken when the required document is
     * absent, not the measure's normal outcome. Chapter 62 garments carry
     * several. Treating them as prohibitions marks an entire apparel catalogue
     * unshippable.
     */
    public function testAGarmentIsNotReportedAsProhibited(): void
    {
        $issue = (new ProhibitedGoods())->evaluate(
            $this->product(origin: 'VN'),
            $this->context($this->parka()),
        );

        self::assertNull($issue);
    }

    /**
     * The genuine article: measure type 277, series A, unconditional, and
     * scoped to North Korea alone.
     */
    public function testAnUnconditionalProhibitionIsReported(): void
    {
        $rule = new ProhibitedGoods();
        $context = $this->context($this->artillery());

        self::assertNull(
            $rule->evaluate($this->product(origin: 'CN'), $context),
            'China is not covered by the North Korea prohibition',
        );

        $issue = $rule->evaluate($this->product(origin: 'KP'), $context);

        self::assertNotNull($issue);
        self::assertSame(Severity::Blocked, $issue->severity);
        self::assertSame('KP', $issue->contextValue('origin'));
    }

    /**
     * A prohibition is never suppressed for want of an origin. Silence about
     * something that stops a shipment is the worse failure.
     */
    public function testProhibitionsAreReportedUnnarrowedWhenOriginIsUnknown(): void
    {
        $issue = (new ProhibitedGoods())->evaluate(
            $this->product(origin: null),
            $this->context($this->artillery()),
        );

        self::assertNotNull($issue);
        self::assertSame('origin_unknown', $issue->variant);
    }

    // --------------------------------------------------------------- controls

    /**
     * Chapter 62 garments carry controls on cat and dog fur and on seal
     * products, both answered by declaring the goods are not those things.
     * That is a formality, not a blocker.
     */
    public function testAControlAnsweredByDeclarationIsOnlyAttention(): void
    {
        $issue = (new LicenceRequired())->evaluate(
            $this->product(origin: 'VN'),
            $this->context($this->parka()),
        );

        self::assertNotNull($issue);
        self::assertSame(Severity::Attention, $issue->severity);
        self::assertSame('declaration_only', $issue->variant);
        self::assertStringContainsString('Y922', (string) $issue->contextValue('document_codes'));
    }

    public function testAControlWithNoDeclarationRouteBlocks(): void
    {
        $issue = (new LicenceRequired())->evaluate(
            $this->product(origin: 'CN'),
            $this->context($this->artillery()),
        );

        self::assertNotNull($issue);
        self::assertSame(Severity::Blocked, $issue->severity);
        self::assertStringContainsString(
            'Firearms',
            (string) $issue->contextValue('control'),
        );
    }

    /**
     * Import and export carry different controls entirely.
     */
    public function testControlsDifferByDirection(): void
    {
        $rule = new LicenceRequired();
        $detail = $this->artillery();

        $import = $rule->evaluate($this->product(origin: 'CN'), $this->context($detail));
        $export = $rule->evaluate(
            $this->product(origin: 'CN'),
            $this->context($detail, TradeDirection::Export),
        );

        self::assertNotNull($import);
        self::assertNotNull($export);
        self::assertNotSame(
            $import->contextValue('control'),
            $export->contextValue('control'),
        );
    }

    /**
     * The precomputed trade_defence flag is set at commodity level for goods
     * where such a measure exists for *some* origin. A cotton parka carries
     * it because Russia and Belarus attract 35%. Firing on the flag would
     * report that surcharge against Vietnamese stock.
     */
    public function testAdditionalDutyIsOriginSpecific(): void
    {
        $rule = new AdditionalDutyApplies();
        $detail = $this->parka();

        self::assertTrue($detail->flags->tradeDefence, 'The flag is set for this commodity');

        self::assertNull(
            $rule->evaluate($this->product(origin: 'VN'), $this->context($detail)),
            'Vietnam attracts no additional duty despite the flag',
        );

        $issue = $rule->evaluate($this->product(origin: 'RU'), $this->context($detail));

        self::assertNotNull($issue);
        self::assertSame('35.00 %', $issue->contextValue('rate'));
        self::assertSame('Russia', $issue->contextValue('area'));
    }

    public function testAdditionalDutyStaysSilentWithoutAnOrigin(): void
    {
        self::assertNull((new AdditionalDutyApplies())->evaluate(
            $this->product(origin: null),
            $this->context($this->parka()),
        ));
    }

    public function testPreferenceIsReportedWithTheSaving(): void
    {
        $issue = (new PreferenceAvailable())->evaluate(
            $this->product(origin: 'VN'),
            $this->context($this->parka()),
        );

        self::assertNotNull($issue);
        self::assertSame(Severity::Opportunity, $issue->severity);
        self::assertSame('12.00 %', $issue->contextValue('standard_rate'));
        self::assertSame('0.00 %', $issue->contextValue('preferential_rate'));
        self::assertSame(12.0, $issue->contextValue('saving_percentage_points'));
    }

    public function testNoPreferenceWhereNoneApplies(): void
    {
        self::assertNull((new PreferenceAvailable())->evaluate(
            $this->product(origin: 'RU'),
            $this->context($this->parka()),
        ));
    }

    /**
     * Real money on the first commodity examined: children's clothing is
     * zero-rated, and a merchant charging 20% has been losing margin on every
     * sale.
     */
    public function testZeroVatOptionIsSurfaced(): void
    {
        $issue = (new VatZeroRatingAvailable())->evaluate(
            $this->product(),
            $this->context($this->parka()),
        );

        self::assertNotNull($issue);
        self::assertSame(Severity::Opportunity, $issue->severity);
        self::assertSame('VATZ', $issue->contextValue('zero_rate_option'));

        self::assertNull((new VatZeroRatingAvailable())->evaluate(
            $this->product(),
            $this->context($this->artillery()),
        ));
    }

    public function testMissingSupplementaryQuantityIsReported(): void
    {
        $rule = new MissingSupplementaryUnits();
        $context = $this->context($this->parka());

        $issue = $rule->evaluate($this->product(supplementary: null), $context);

        self::assertNotNull($issue);
        self::assertSame('p/st', $issue->contextValue('unit'));

        self::assertNull($rule->evaluate($this->product(supplementary: 1.0), $context));
    }

    public function testExpiringCodesAreOnlyReportedInsideTheWindow(): void
    {
        $rule = new CodeExpiringSoon();
        $detail = $this->parka();

        // The recorded payload carries no end date, so nothing is expiring.
        self::assertNull($rule->evaluate($this->product(), $this->context($detail)));
    }

    /**
     * On an offline scan no measures are fetched, and every measure rule stays
     * silent rather than guessing.
     */
    public function testMeasureRulesAreSilentWithoutMeasures(): void
    {
        $context = EvaluationContext::at('2026-08-22');
        $product = $this->product();

        foreach (DefaultRuleSet::measureDependent() as $rule) {
            self::assertNull(
                $rule->evaluate($product, $context),
                sprintf('%s should stay silent with no measures', $rule->code()),
            );
        }
    }

    public function testTheFullPoolIsAValidConfiguration(): void
    {
        $pool = DefaultRuleSet::fullPool();

        self::assertSame(22, $pool->count());
        self::assertCount(22, $pool->ordered());
    }

    /**
     * Measure rules run only once a single declarable commodity is known.
     * Measures on an unexpanded subheading belong to several classifications
     * at once and none of them can be reported honestly.
     */
    public function testMeasureRulesDependOnExpansionHavingPassed(): void
    {
        $chapter = file_get_contents(__DIR__ . '/../../Fixtures/Api/chapter-62.csv');
        self::assertIsString($chapter);

        $repository = new InMemoryCommodityRepository((new ChapterCsvParser())->parse($chapter));
        $resolution = (new CommodityResolver($repository))->resolve('620130');

        self::assertTrue($resolution->isAmbiguous());

        $result = DefaultRuleSet::fullPool()->evaluate(
            $this->product(),
            new EvaluationContext(
                new DateTimeImmutable('2026-08-22'),
                new RuleSettings(),
                $resolution,
                null,
                $this->parka(),
            ),
        );

        self::assertTrue($result->has('ambiguous_expansion'));
        self::assertTrue($result->wasSkipped(ProhibitedGoods::CODE));
        self::assertTrue($result->wasSkipped(PreferenceAvailable::CODE));
    }
}
