<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Provider\Hmrc;

use Davix\Customs\Provider\Hmrc\CommodityMapper;
use Davix\Customs\Provider\Hmrc\JsonApiDocument;
use Davix\Customs\Tariff\CommodityDetail;
use Davix\Customs\Tariff\Measure;
use Davix\Customs\Tariff\MeasureCondition;
use Davix\Customs\Tariff\MeasureType;
use Davix\Customs\Tariff\TradeDirection;
use PHPUnit\Framework\TestCase;

/**
 * Replays recorded responses from the live tariff. Nothing here contacts the
 * network; see tests/Fixtures/Api/README.md for provenance.
 */
final class CommodityMapperTest extends TestCase
{
    private CommodityMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new CommodityMapper();
    }

    private function fixture(string $name): CommodityDetail
    {
        $path = __DIR__ . '/../../Fixtures/Api/' . $name . '.json';
        $json = file_get_contents($path);

        self::assertIsString($json, sprintf('Fixture %s is unreadable', $name));

        return $this->mapper->mapFromJson($json);
    }

    private function parka(): CommodityDetail
    {
        return $this->fixture('commodity-6201401019');
    }

    private function artillery(): CommodityDetail
    {
        return $this->fixture('commodity-9301100000');
    }

    // ------------------------------------------------------------- commodity

    public function testTheCommodityItselfIsMapped(): void
    {
        $detail = $this->parka();

        self::assertSame('6201401019', $detail->code());
        self::assertSame(106862, $detail->commodity->sid);
        self::assertTrue($detail->isDeclarable());
        self::assertSame('Other', $detail->commodity->description);
    }

    /**
     * The API spells it `producline_suffix`, missing the 'd'. A mapper reading
     * only the documented spelling nulls the field, and a null suffix collapses
     * the two lines that share a commodity code into one.
     */
    public function testTheMisspelledProductlineSuffixIsRead(): void
    {
        self::assertSame('80', $this->parka()->commodity->productlineSuffix);
        self::assertSame('80', $this->artillery()->commodity->productlineSuffix);
    }

    /**
     * The ancestor chain is where weight conditions live. The commodity itself
     * is called "Other"; the 1 kg split is two levels above it.
     */
    public function testAncestorsCarryTheConditionsTheCommodityDoesNot(): void
    {
        $detail = $this->parka();

        self::assertCount(3, $detail->ancestors);

        $codes = array_map(static fn ($a): string => $a->code, $detail->ancestors);
        self::assertSame(['6201400000', '6201401000', '6201401011'], $codes);

        $chain = implode(' ', $detail->descriptionChain());
        self::assertStringContainsString('weight', $chain);
        self::assertStringNotContainsString('weight', $detail->commodity->description);
    }

    public function testImmediateParentIsTheNearestAncestor(): void
    {
        self::assertSame('6201401011', $this->parka()->immediateParent()?->code);
        self::assertNull($this->artillery()->immediateParent());
    }

    // -------------------------------------------------------------- direction

    /**
     * A single response mixes both directions. The parka carries export
     * controls on cat and dog fur alongside its import duty, and reporting
     * those to a merchant importing stock is noise.
     */
    public function testImportAndExportMeasuresArriveTogetherAndSeparateCleanly(): void
    {
        $detail = $this->parka();

        self::assertSame(74, $detail->measures->count());
        self::assertSame(70, $detail->importMeasures()->count());
        self::assertSame(5, $detail->exportMeasures()->count());
    }

    /**
     * Only unconditional prohibitions count. Nearly every control measure
     * carries a negative condition reading "not allowed after control" - that
     * is the branch taken when the required document is absent, not the
     * measure's normal outcome. Counting those would report a firearms-grade
     * prohibition on every garment in chapter 62.
     *
     * What survives is the genuine article: measure type 277, series A, on
     * North Korea alone.
     */
    public function testOnlyUnconditionalProhibitionsCount(): void
    {
        $detail = $this->artillery();

        self::assertSame(1, $detail->importMeasures()->prohibitions()->count());
        self::assertSame(0, $detail->exportMeasures()->prohibitions()->count());

        $prohibition = $detail->importMeasures()->prohibitions()->first();

        self::assertNotNull($prohibition);
        self::assertTrue($prohibition->type->isProhibition());
        self::assertSame('KP', $prohibition->geographicalArea?->id);
        self::assertFalse($prohibition->appliesToOrigin('CN'));
    }

    // ------------------------------------------------------------- conditions

    /**
     * Matching on action code 09 alone, as is sometimes documented, misses the
     * import-only and export-only prohibitions.
     */
    public function testProhibitionActionCodesBeyondNine(): void
    {
        $codes = [];

        foreach ($this->artillery()->measures->all() as $measure) {
            foreach ($measure->prohibitingConditions() as $condition) {
                $codes[(string) $condition->actionCode] = true;
            }
        }

        ksort($codes);

        self::assertSame(['05', '06', '09'], array_keys($codes));
    }

    public function testProhibitionDirectionIsDerivedFromTheActionCode(): void
    {
        $importOnly = new MeasureCondition(
            class: \Davix\Customs\Tariff\MeasureConditionClass::Negative,
            action: 'Import is not allowed',
            actionCode: '06',
        );
        $exportOnly = new MeasureCondition(
            class: \Davix\Customs\Tariff\MeasureConditionClass::Negative,
            action: 'Export is not allowed',
            actionCode: '05',
        );
        $both = new MeasureCondition(
            class: \Davix\Customs\Tariff\MeasureConditionClass::Negative,
            action: 'Import/export not allowed after control',
            actionCode: '09',
        );

        self::assertSame(TradeDirection::Import, $importOnly->prohibitionDirection());
        self::assertSame(TradeDirection::Export, $exportOnly->prohibitionDirection());
        self::assertNull($both->prohibitionDirection());
    }

    /**
     * Documentary options are scoped to control measures, not to every
     * commodity carrying a document code. Duty measures list certificates too
     * - as conditions of a cheaper rate rather than requirements - and
     * counting those would report a licence on nearly every product.
     */
    public function testDocumentaryOptionsComeOnlyFromControls(): void
    {
        self::assertNotEmpty($this->artillery()->measures->documentaryOptions());

        foreach ($this->artillery()->measures->requiringDocumentation()->all() as $control) {
            self::assertTrue($control->type->isControl());
        }
    }

    /**
     * The numeric prefix says nothing about whether a document must be
     * obtained. The firearms control lists 9020 "This product is exempt as it
     * is not a firearm" alongside 9023 "DBT Firearms Import License" -
     * structurally identical, one a formality and one a licence. An earlier
     * version of this mapper used the prefix to tell them apart and was wrong.
     */
    public function testDocumentCodesAreReturnedAsStrings(): void
    {
        $codes = $this->artillery()->importMeasures()->documentCodes();

        foreach ($codes as $code) {
            self::assertIsString(
                $code,
                'PHP casts numeric-string array keys to integers; documentCodes must not',
            );
        }
    }

    public function testNumericCodesCoverBothFormalitiesAndRealLicences(): void
    {
        $codes = $this->artillery()
            ->importMeasures()
            ->ofType('351')
            ->documentCodes();

        self::assertContains('9020', $codes);
        self::assertContains('9023', $codes);
    }

    /**
     * Chapter 62 garments carry controls on cat and dog fur and on seal
     * products, both satisfied by declaring the goods are not those things.
     * A firearms licence has no such route.
     */
    public function testControlsAreDistinguishedByWhetherADeclarationSatisfiesThem(): void
    {
        $parkaControls = $this->parka()->importMeasures()->forOrigin('VN')->requiringDocumentation();

        self::assertFalse($parkaControls->isEmpty());

        foreach ($parkaControls->all() as $control) {
            self::assertTrue(
                $control->hasDeclarationRoute(),
                sprintf('%s should be answerable by declaration', $control->type->description),
            );
        }

        $firearms = $this->artillery()->importMeasures()->ofType('351')->first();

        self::assertNotNull($firearms);
        self::assertFalse($firearms->hasDeclarationRoute());
    }

    // ------------------------------------------------------------------ areas

    public function testGeographicalAreaMembershipIsMapped(): void
    {
        $duty = $this->parka()->importMeasures()->ofType(MeasureType::THIRD_COUNTRY_DUTY)->first();

        self::assertInstanceOf(Measure::class, $duty);
        self::assertNotNull($duty->geographicalArea);
        self::assertTrue($duty->geographicalArea->isErgaOmnes());
        self::assertSame(261, $duty->geographicalArea->memberCount());
        self::assertTrue($duty->appliesToOrigin('CN'));
    }

    public function testExclusionsAreSubtractedAfterMembership(): void
    {
        $withExclusions = null;

        foreach ($this->parka()->measures->all() as $measure) {
            if ($measure->excludedAreaCodes !== []) {
                $withExclusions = $measure;
                break;
            }
        }

        self::assertInstanceOf(Measure::class, $withExclusions);

        $excluded = $withExclusions->excludedAreaCodes[0];

        self::assertFalse(
            $withExclusions->appliesToOrigin($excluded),
            'An excluded origin must not inherit the measure from its group',
        );
    }

    // ------------------------------------------------------------------ rates

    public function testDutyExpressionIsMapped(): void
    {
        $duty = $this->parka()->importMeasures()->ofType(MeasureType::THIRD_COUNTRY_DUTY)->first();

        self::assertInstanceOf(Measure::class, $duty);
        self::assertSame('12.00 %', (string) $duty->dutyExpression);
        self::assertTrue($duty->hasDuty());
    }

    /**
     * The trade summary arrives wrapped in markup because the tariff's own
     * site highlights the numeral. Passing that through would put someone
     * else's HTML into a host's admin.
     */
    public function testTradeSummaryMarkupIsStripped(): void
    {
        $detail = $this->parka();

        self::assertSame('12.00 %', $detail->basicThirdCountryDuty);
        self::assertSame('0.00 %', $detail->preferentialQuotaDuty);
        self::assertStringNotContainsString('<', (string) $detail->basicThirdCountryDuty);
    }

    // ------------------------------------------------------------------ flags

    /**
     * Worth reading rather than deriving - HMRC has already done this work and
     * their answer is authoritative where ours would be an inference.
     */
    public function testDutyCalculatorFlagsAreRead(): void
    {
        $parka = $this->parka()->flags;

        self::assertTrue($parka->tradeDefence);
        self::assertFalse($parka->zeroMfnDuty);
        self::assertFalse($parka->meursingCode);
        self::assertFalse($parka->entryPriceSystem);

        $artillery = $this->artillery()->flags;

        self::assertFalse($artillery->tradeDefence);
        self::assertTrue($artillery->zeroMfnDuty);
    }

    /**
     * Real money on a real product: the zero rate option is present on the very
     * first commodity looked at.
     */
    public function testZeroVatOptionIsDetected(): void
    {
        self::assertTrue($this->parka()->flags->hasZeroVatOption());
        self::assertSame(['VATZ', 'VAT'], $this->parka()->flags->vatOptionCodes());

        self::assertFalse($this->artillery()->flags->hasZeroVatOption());
    }

    // ------------------------------------------------------------- collection

    public function testMeasureSetFiltersChain(): void
    {
        $detail = $this->parka();

        $chained = $detail->measures
            ->forDirection(TradeDirection::Import)
            ->forOrigin('CN')
            ->ofType(MeasureType::THIRD_COUNTRY_DUTY);

        self::assertSame(1, $chained->count());
    }

    public function testQuotasAndPreferencesAreIdentified(): void
    {
        $detail = $this->parka();

        self::assertSame(1, $detail->measures->quotas()->count());
        self::assertGreaterThan(0, $detail->measures->preferences()->count());
    }

    public function testSupplementaryUnitMeasureIsFound(): void
    {
        self::assertTrue($this->parka()->requiresSupplementaryUnit());
    }

    public function testFootnotesAreCollected(): void
    {
        self::assertCount(16, $this->parka()->footnotes);
        self::assertCount(23, $this->artillery()->footnotes);
    }

    // --------------------------------------------------------------- document

    public function testResourcesAreIndexedByTypeAndIdNotIdAlone(): void
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/Api/commodity-6201401019.json');
        self::assertIsString($json);

        $document = JsonApiDocument::fromJson($json);

        // '103' is both a measure type and, in other payloads, an area id.
        // Resolution must be by the pair.
        $measureType = $document->find('measure_type', '103');

        self::assertIsArray($measureType);

        $attributes = JsonApiDocument::attributesOf($measureType);

        self::assertSame('Third country duty', $attributes['description'] ?? null);
    }

    public function testMissingRelationshipsDegradeRatherThanThrow(): void
    {
        $document = new JsonApiDocument(['data' => ['id' => '1', 'attributes' => []]]);

        self::assertNull($document->related('nothing'));
        self::assertSame([], $document->relatedMany('nothing'));
        self::assertSame([], $document->meta('duty_calculator'));
        self::assertNull($document->find('measure', '1'));
    }

    public function testAnEmptyDocumentMapsWithoutThrowing(): void
    {
        $detail = $this->mapper->map(new JsonApiDocument([]));

        self::assertSame('', $detail->code());
        self::assertTrue($detail->measures->isEmpty());
        self::assertFalse($detail->flags->tradeDefence);
    }
}