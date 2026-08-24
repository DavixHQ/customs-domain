<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Rule\Checks;

use Davix\Customs\Product\ProductCustomsData;
use Davix\Customs\Rule\Checks\AmbiguousExpansion;
use Davix\Customs\Rule\Checks\DescriptionIsProductName;
use Davix\Customs\Rule\Checks\MissingComposition;
use Davix\Customs\Rule\Checks\NetWeightExceedsGross;
use Davix\Customs\Rule\Checks\OriginNotInTariffAreas;
use Davix\Customs\Rule\Checks\StaleVerification;
use Davix\Customs\Rule\Checks\UnknownCode;
use Davix\Customs\Rule\Checks\VagueDescription;
use Davix\Customs\Rule\Checks\WithdrawnCode;
use Davix\Customs\Rule\DefaultRuleSet;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\RuleSettings;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Rule\SkipReason;
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\HistoricRecord;
use Davix\Customs\Tariff\Resolution;
use Davix\Customs\Tests\Fixtures\ChapterSixtyTwoFixture as Chapter62;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfflineRulesTest extends TestCase
{
    private function product(
        ?string $code = '6201401019',
        ?string $origin = 'CN',
        ?string $description = 'Mens parka, outer shell 100% polyester',
        ?string $name = 'Summit Parka',
        ?float $netWeight = null,
        ?float $grossWeight = null,
        ?string $composition = '100% polyester',
        ?DateTimeImmutable $verifiedAt = null,
    ): ProductCustomsData {
        return ProductCustomsData::fromRawCode(
            identifier: '1',
            sku: 'ABC-001',
            name: $name ?? 'Summit Parka',
            rawHsCode: $code,
            countryOfOrigin: $origin,
            customsDescription: $description,
            netWeight: $netWeight,
            grossWeight: $grossWeight,
            composition: $composition,
            verifiedAt: $verifiedAt,
        );
    }

    private function resolver(): CommodityResolver
    {
        return new CommodityResolver(Chapter62::repository());
    }

    // ---------------------------------------------------------------- withdrawn

    public function testWithdrawnCodeFiresWhenTheBaselineFoundIt(): void
    {
        $context = EvaluationContext::at(
            resolution: Resolution::notInMirror('6201930000'),
            historic: HistoricRecord::found(
                'Anoraks, of man-made fibres',
                new DateTimeImmutable('2022-01-01'),
                ['6201401019'],
            ),
        );

        $issue = (new WithdrawnCode())->evaluate($this->product(code: '6201930000'), $context);

        self::assertNotNull($issue);
        self::assertSame(Severity::Attention, $issue->severity);
        self::assertSame('with_successor', $issue->variant);
        self::assertSame('Anoraks, of man-made fibres', $issue->contextValue('former_description'));
        self::assertSame('2022-01-01', $issue->contextValue('withdrawn_on'));
    }

    /**
     * One successor means the module already knows the answer, so the fix is a
     * single click across every affected product.
     */
    public function testASingleSuccessorMakesTheFixAutomatic(): void
    {
        $context = EvaluationContext::at(
            resolution: Resolution::notInMirror('6201930000'),
            historic: HistoricRecord::found('Anoraks', null, ['6201401019']),
        );

        $issue = (new WithdrawnCode())->evaluate($this->product(code: '6201930000'), $context);

        self::assertNotNull($issue);
        self::assertTrue($issue->isAutomaticallyFixable());
        self::assertNotNull($issue->remediation);
        self::assertSame('6201401019', $issue->remediation->payload['successor_code'] ?? null);
    }

    public function testSeveralSuccessorsRequireTheMerchantToChoose(): void
    {
        $context = EvaluationContext::at(
            resolution: Resolution::notInMirror('6201930000'),
            historic: HistoricRecord::found('Anoraks', null, ['6201401019', '6201401090']),
        );

        $issue = (new WithdrawnCode())->evaluate($this->product(code: '6201930000'), $context);

        self::assertNotNull($issue);
        self::assertSame('with_several_successors', $issue->variant);
        self::assertFalse($issue->isAutomaticallyFixable());
    }

    public function testNoSuccessorStillReportsTheWithdrawal(): void
    {
        $context = EvaluationContext::at(
            resolution: Resolution::notInMirror('6201930000'),
            historic: HistoricRecord::found('Anoraks'),
        );

        $issue = (new WithdrawnCode())->evaluate($this->product(code: '6201930000'), $context);

        self::assertSame('without_successor', $issue?->variant);
    }

    public function testWithdrawnCodeStaysSilentWithoutABaselineLookup(): void
    {
        $context = EvaluationContext::at(resolution: Resolution::notInMirror('6201930000'));

        self::assertNull((new WithdrawnCode())->evaluate($this->product(code: '6201930000'), $context));
    }

    public function testWithdrawnCodeStaysSilentWhenTheCodeResolved(): void
    {
        $resolution = $this->resolver()->resolve('6201301011');
        $context = EvaluationContext::at(resolution: $resolution);

        self::assertNull((new WithdrawnCode())->evaluate($this->product(), $context));
    }

    // ------------------------------------------------------------------ unknown

    public function testUnknownCodeFiresForACodeThatNeverExisted(): void
    {
        $context = EvaluationContext::at(
            resolution: Resolution::notInMirror('6299999999'),
            historic: HistoricRecord::absent(),
        );

        $issue = (new UnknownCode())->evaluate($this->product(code: '6299999999'), $context);

        self::assertNotNull($issue);
        self::assertSame(Severity::Blocked, $issue->severity);
        self::assertSame('confirmed', $issue->variant);
    }

    public function testUnknownCodeMarksItselfUnverifiedWithoutABaselineLookup(): void
    {
        $context = EvaluationContext::at(resolution: Resolution::notInMirror('6299999999'));

        $issue = (new UnknownCode())->evaluate($this->product(code: '6299999999'), $context);

        self::assertNotNull($issue);
        self::assertSame('unverified', $issue->variant);
        self::assertFalse($issue->contextValue('baseline_checked'));
    }

    /**
     * The guard that stops a failed chapter sync being reported as thousands of
     * bad merchant codes.
     */
    public function testUnknownCodeStaysSilentWhenTheChapterWasNotMirrored(): void
    {
        $resolution = $this->resolver()->resolve('8501101000');
        $context = EvaluationContext::at(resolution: $resolution);

        self::assertNull((new UnknownCode())->evaluate($this->product(code: '8501101000'), $context));
    }

    public function testUnknownCodeStaysSilentForNationalChapters(): void
    {
        $resolution = $this->resolver()->resolve('9901000000');
        $context = EvaluationContext::at(resolution: $resolution);

        self::assertNull((new UnknownCode())->evaluate($this->product(code: '9901000000'), $context));
    }

    /**
     * The dependency that makes withdrawn and unknown mutually exclusive.
     */
    public function testWithdrawnGatesUnknownSoBothCanNeverFire(): void
    {
        $pool = DefaultRuleSet::pool();

        $result = $pool->evaluate(
            $this->product(code: '6201930000'),
            EvaluationContext::at(
                resolution: Resolution::notInMirror('6201930000'),
                historic: HistoricRecord::found('Anoraks', null, ['6201401019']),
            ),
        );

        self::assertTrue($result->has(WithdrawnCode::CODE));
        self::assertFalse($result->has(UnknownCode::CODE));
        self::assertSame(SkipReason::PrerequisiteFailed, $result->skipReason(UnknownCode::CODE));
    }

    // ---------------------------------------------------------------- ambiguous

    public function testAmbiguousExpansionFires(): void
    {
        $resolution = $this->resolver()->resolve('620130');
        $context = EvaluationContext::at(resolution: $resolution);

        $issue = (new AmbiguousExpansion())->evaluate($this->product(code: '620130'), $context);

        self::assertNotNull($issue);
        self::assertSame(8, $issue->contextValue('candidate_count'));
        self::assertSame('Of cotton', $issue->contextValue('matched_description'));
        self::assertSame('unnarrowed', $issue->variant);
    }

    public function testAmbiguousExpansionReportsWeightNarrowing(): void
    {
        $resolution = $this->resolver()->resolve('620130', netWeightKg: 1.5);
        $context = EvaluationContext::at(resolution: $resolution);

        $issue = (new AmbiguousExpansion())->evaluate(
            $this->product(code: '620130', netWeight: 1.5),
            $context,
        );

        self::assertNotNull($issue);
        self::assertSame('narrowed', $issue->variant);
        self::assertTrue($issue->contextValue('narrowed_by_weight'));
        self::assertSame(4, $issue->contextValue('eliminated_by_weight'));
    }

    public function testAmbiguousExpansionCarriesCandidatesForThePicker(): void
    {
        $resolution = $this->resolver()->resolve('620130');
        $context = EvaluationContext::at(resolution: $resolution);

        $issue = (new AmbiguousExpansion())->evaluate($this->product(code: '620130'), $context);
        $codes = explode(',', (string) $issue?->contextValue('candidate_codes'));

        self::assertCount(8, $codes);
        self::assertContains('6201301011', $codes);
    }

    public function testAmbiguousExpansionIsNeverAutomaticallyFixable(): void
    {
        $resolution = $this->resolver()->resolve('620130');
        $issue = (new AmbiguousExpansion())->evaluate(
            $this->product(code: '620130'),
            EvaluationContext::at(resolution: $resolution),
        );

        self::assertNotNull($issue);
        self::assertTrue($issue->isFixable());
        self::assertFalse(
            $issue->isAutomaticallyFixable(),
            'Choosing a classification has legal consequences and must not be guessed',
        );
    }

    public function testAmbiguousExpansionStaysSilentWhenResolved(): void
    {
        $resolution = $this->resolver()->resolve('6201301011');

        self::assertNull((new AmbiguousExpansion())->evaluate(
            $this->product(),
            EvaluationContext::at(resolution: $resolution),
        ));
    }

    // -------------------------------------------------------------- description

    /**
     * @return array<string, array{string}>
     */
    public static function vagueDescriptionProvider(): array
    {
        return [
            'gift' => ['gift'],
            'parts' => ['Parts'],
            'sample with punctuation' => ['sample.'],
            'goods' => ['GOODS'],
            'clothing' => ['clothing'],
            'padded' => ['  merchandise  '],
        ];
    }

    #[DataProvider('vagueDescriptionProvider')]
    public function testVagueDescriptionFires(string $description): void
    {
        $issue = (new VagueDescription())->evaluate(
            $this->product(description: $description),
            EvaluationContext::at(),
        );

        self::assertNotNull($issue, sprintf('Expected "%s" to be flagged', $description));
        self::assertSame('vague', $issue->variant);
    }

    public function testMissingDescriptionUsesItsOwnVariant(): void
    {
        $issue = (new VagueDescription())->evaluate(
            $this->product(description: null),
            EvaluationContext::at(),
        );

        self::assertSame('missing', $issue?->variant);
    }

    /**
     * A generic word alongside real detail is a description doing its job.
     */
    public function testAGenericWordInsideARealDescriptionDoesNotFire(): void
    {
        foreach ([
            'Gift wrapping paper, 80gsm, printed',
            'Spare parts for bicycle, steel brake calipers',
            "Men's parka, outer shell 100% polyester",
        ] as $description) {
            self::assertNull(
                (new VagueDescription())->evaluate(
                    $this->product(description: $description),
                    EvaluationContext::at(),
                ),
                sprintf('"%s" should pass', $description),
            );
        }
    }

    public function testDescriptionIsProductNameFires(): void
    {
        $issue = (new DescriptionIsProductName())->evaluate(
            $this->product(description: 'Summit Parka', name: 'Summit Parka'),
            EvaluationContext::at(),
        );

        self::assertNotNull($issue);
    }

    public function testDescriptionMatchingIgnoresCaseAndWhitespace(): void
    {
        $issue = (new DescriptionIsProductName())->evaluate(
            $this->product(description: '  summit   PARKA ', name: 'Summit Parka'),
            EvaluationContext::at(),
        );

        self::assertNotNull($issue);
    }

    public function testDescriptionIsProductNamePassesWhenTheyDiffer(): void
    {
        self::assertNull((new DescriptionIsProductName())->evaluate(
            $this->product(description: "Men's parka, polyester", name: 'Summit Parka'),
            EvaluationContext::at(),
        ));
    }

    /**
     * A description that is both a copy of the name and generic reports once,
     * as the more specific problem.
     */
    public function testVagueDescriptionGatesTheProductNameCheck(): void
    {
        $result = DefaultRuleSet::pool()->evaluate(
            $this->product(description: 'Clothing', name: 'Clothing'),
            EvaluationContext::at(resolution: $this->resolver()->resolve('6201301011')),
        );

        self::assertTrue($result->has(VagueDescription::CODE));
        self::assertFalse($result->has(DescriptionIsProductName::CODE));
    }

    // ------------------------------------------------------------------- weight

    public function testNetWeightExceedingGrossFires(): void
    {
        $issue = (new NetWeightExceedsGross())->evaluate(
            $this->product(netWeight: 2.0, grossWeight: 1.5),
            EvaluationContext::at(),
        );

        self::assertNotNull($issue);
        self::assertSame('inconsistent', $issue->variant);
    }

    /**
     * The single most common unit error in customs data.
     */
    public function testAThousandfoldRatioIsCalledOutAsAUnitError(): void
    {
        $issue = (new NetWeightExceedsGross())->evaluate(
            $this->product(netWeight: 1200.0, grossWeight: 1.45),
            EvaluationContext::at(),
        );

        self::assertNotNull($issue);
        self::assertSame('probable_unit_error', $issue->variant);
        self::assertSame(1.2, $issue->contextValue('suggested_net_weight'));
    }

    public function testEqualWeightsPass(): void
    {
        self::assertNull((new NetWeightExceedsGross())->evaluate(
            $this->product(netWeight: 1.5, grossWeight: 1.5),
            EvaluationContext::at(),
        ));
    }

    public function testMissingWeightsPass(): void
    {
        foreach ([[null, 1.5], [1.5, null], [null, null], [0.0, 1.5]] as [$net, $gross]) {
            self::assertNull((new NetWeightExceedsGross())->evaluate(
                $this->product(netWeight: $net, grossWeight: $gross),
                EvaluationContext::at(),
            ));
        }
    }

    // -------------------------------------------------------------- composition

    public function testMissingCompositionFiresInTextileChapters(): void
    {
        $issue = (new MissingComposition())->evaluate(
            $this->product(code: '6201301011', composition: null),
            EvaluationContext::at(),
        );

        self::assertNotNull($issue);
        self::assertSame('62', $issue->contextValue('chapter'));
    }

    public function testMissingCompositionIgnoresOtherChapters(): void
    {
        self::assertNull((new MissingComposition())->evaluate(
            $this->product(code: '8501101000', composition: null),
            EvaluationContext::at(),
        ));
    }

    public function testMissingCompositionPassesWhenPopulated(): void
    {
        self::assertNull((new MissingComposition())->evaluate(
            $this->product(code: '6201301011', composition: '100% polyester'),
            EvaluationContext::at(),
        ));
    }

    // ------------------------------------------------------------- verification

    public function testStaleVerificationFires(): void
    {
        $issue = (new StaleVerification())->evaluate(
            $this->product(verifiedAt: new DateTimeImmutable('2024-01-01')),
            EvaluationContext::at('2026-08-22'),
        );

        self::assertNotNull($issue);
        self::assertSame(31, $issue->contextValue('months_since'));
        self::assertTrue($issue->isAutomaticallyFixable());
    }

    public function testRecentVerificationPasses(): void
    {
        self::assertNull((new StaleVerification())->evaluate(
            $this->product(verifiedAt: new DateTimeImmutable('2026-06-01')),
            EvaluationContext::at('2026-08-22'),
        ));
    }

    /**
     * On a first scan every product is unverified. Firing on those would bury
     * the issues that need attention under thousands that do not.
     */
    public function testNeverVerifiedIsNotTreatedAsStale(): void
    {
        self::assertNull((new StaleVerification())->evaluate(
            $this->product(verifiedAt: null),
            EvaluationContext::at('2026-08-22'),
        ));
    }

    public function testStalenessThresholdIsConfigurable(): void
    {
        $product = $this->product(verifiedAt: new DateTimeImmutable('2026-01-01'));

        self::assertNull((new StaleVerification())->evaluate(
            $product,
            EvaluationContext::at('2026-08-22', new RuleSettings(staleVerificationMonths: 12)),
        ));

        self::assertNotNull((new StaleVerification())->evaluate(
            $product,
            EvaluationContext::at('2026-08-22', new RuleSettings(staleVerificationMonths: 3)),
        ));
    }

    // ------------------------------------------------------------------- origin

    public function testFreeTextOriginIsRejected(): void
    {
        foreach (['China', 'Made in PRC', 'CHN', 'C'] as $origin) {
            $issue = (new OriginNotInTariffAreas())->evaluate(
                $this->product(origin: $origin),
                EvaluationContext::at(),
            );

            self::assertNotNull($issue, sprintf('"%s" should be rejected', $origin));
            self::assertSame('malformed', $issue->variant, sprintf('"%s" should be malformed', $origin));
            self::assertSame(Severity::Blocked, $issue->severity);
        }
    }

    public function testWellShapedOriginPassesWithoutARecognisedList(): void
    {
        self::assertNull((new OriginNotInTariffAreas())->evaluate(
            $this->product(origin: 'CN'),
            EvaluationContext::at(),
        ));
    }

    public function testOriginIsCheckedAgainstTheRecognisedListWhenSupplied(): void
    {
        $settings = new RuleSettings(recognisedOriginCodes: ['CN', 'GB', 'DE']);

        self::assertNull((new OriginNotInTariffAreas())->evaluate(
            $this->product(origin: 'CN'),
            EvaluationContext::at('now', $settings),
        ));

        $issue = (new OriginNotInTariffAreas())->evaluate(
            $this->product(origin: 'ZZ'),
            EvaluationContext::at('now', $settings),
        );

        self::assertSame('unrecognised', $issue?->variant);
    }

    public function testMissingOriginGatesTheAreaCheck(): void
    {
        $result = DefaultRuleSet::pool()->evaluate(
            $this->product(origin: null),
            EvaluationContext::at(resolution: $this->resolver()->resolve('6201301011')),
        );

        self::assertSame(
            SkipReason::PrerequisiteFailed,
            $result->skipReason(OriginNotInTariffAreas::CODE),
        );
    }

    // ---------------------------------------------------------------- whole set

    public function testTheDefaultRuleSetIsAValidPool(): void
    {
        $pool = DefaultRuleSet::pool();

        self::assertSame(12, $pool->count());
        self::assertCount(12, $pool->ordered());
    }

    public function testACleanProductProducesNoIssues(): void
    {
        $result = DefaultRuleSet::pool()->evaluate(
            $this->product(
                code: '6201301011',
                origin: 'CN',
                description: "Men's parka, outer shell 100% polyester, hooded",
                netWeight: 1.2,
                grossWeight: 1.45,
                composition: '100% polyester',
                verifiedAt: new DateTimeImmutable('2026-06-01'),
            ),
            EvaluationContext::at('2026-08-22', resolution: $this->resolver()->resolve('6201301011')),
        );

        self::assertFalse($result->hasIssues(), implode(', ', $result->issueCodes()));
    }

    /**
     * One unreadable code must not cascade into a pile of derived noise.
     */
    public function testAnUnreadableCodeProducesOneCodeIssueNotFive(): void
    {
        $result = DefaultRuleSet::pool()->evaluate(
            $this->product(code: 'TBC', composition: null),
            EvaluationContext::at(),
        );

        $codeIssues = array_filter(
            $result->issueCodes(),
            static fn (string $code): bool => in_array($code, [
                'missing_hs_code',
                'invalid_code_format',
                'withdrawn_code',
                'unknown_code',
                'ambiguous_expansion',
                'missing_composition',
            ], true),
        );

        self::assertCount(1, $codeIssues);
        self::assertSame(['invalid_code_format'], array_values($codeIssues));
    }
}
