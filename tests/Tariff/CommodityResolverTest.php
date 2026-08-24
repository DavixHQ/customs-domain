<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Tariff;

use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\InMemoryCommodityRepository;
use Davix\Customs\Tariff\ResolutionOutcome;
use Davix\Customs\Tests\Fixtures\ChapterSixtyTwoFixture as Chapter62;
use PHPUnit\Framework\TestCase;

final class CommodityResolverTest extends TestCase
{
    private CommodityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CommodityResolver(Chapter62::repository());
    }

    /**
     * @param list<Commodity> $commodities
     * @return list<int>
     */
    private function sids(array $commodities): array
    {
        return array_map(static fn (Commodity $c): int => $c->sid, $commodities);
    }

    public function testADeclarableCodeResolvesDirectly(): void
    {
        $resolution = $this->resolver->resolve('6201301019');

        self::assertTrue($resolution->isResolved());
        self::assertNotNull($resolution->commodity);
        self::assertSame(Chapter62::COTTON_LIGHT_OTHER_SID, $resolution->commodity->sid);
    }

    /**
     * The duplicate-code case. Both lines carry 6201200011; the declarable one
     * is the answer and the grouping line must not be returned.
     */
    public function testTheDeclarableLineWinsOverAnIntermediateSharingItsCode(): void
    {
        $resolution = $this->resolver->resolve('6201200011');

        self::assertTrue($resolution->isResolved());
        self::assertNotNull($resolution->commodity);
        self::assertSame(Chapter62::PONCHO_SID, $resolution->commodity->sid);
        self::assertTrue($resolution->commodity->isDeclarable());
        self::assertSame('Hand-made ponchos', $resolution->commodity->description);
    }

    /**
     * What most real catalogues supply: a six-digit subheading. The mirror
     * stores every line at full length, so resolution pads before giving up.
     */
    public function testASixDigitSubheadingIsResolvedByPadding(): void
    {
        $resolution = $this->resolver->resolve('620130');

        self::assertTrue($resolution->isAmbiguous());
        self::assertNotNull($resolution->matchedLine);
        self::assertSame(Chapter62::COTTON_SUBHEADING_SID, $resolution->matchedLine->sid);
        self::assertSame('Of cotton', $resolution->matchedLine->description);
    }

    public function testASubheadingExpandsToEveryDeclarableDescendant(): void
    {
        $resolution = $this->resolver->resolve('6201300000');

        self::assertTrue($resolution->isAmbiguous());
        self::assertSame(Chapter62::COTTON_DECLARABLE_COUNT, $resolution->candidateCount());

        foreach ($resolution->candidates as $candidate) {
            self::assertTrue($candidate->isDeclarable());
        }
    }

    /**
     * Expansion crosses grouping lines rather than stopping at them. The real
     * tree puts two grouping levels between a subheading and anything
     * declarable.
     */
    public function testExpansionDescendsThroughGroupingLines(): void
    {
        $sids = $this->sids($this->resolver->resolve('6201300000')->candidates);

        self::assertContains(Chapter62::COTTON_LIGHT_PARKA_SID, $sids);
        self::assertContains(Chapter62::COTTON_HEAVY_BATIK_SID, $sids);
        self::assertNotContains(Chapter62::COTTON_LIGHT_COATS_SID, $sids);
        self::assertNotContains(Chapter62::COTTON_LIGHT_SID, $sids);
    }

    // --------------------------------------------------------------- narrowing

    /**
     * The behaviour a made-up fixture got wrong.
     *
     * Weight conditions sit on grouping lines two levels above anything
     * declarable - the candidates are "Parkas", "Other" and "Hand-printed by
     * the batik method", none of which mention weight. Reading only the
     * candidate's own description finds no condition at all and narrows
     * nothing.
     */
    public function testWeightNarrowsUsingAncestorConditionsNotCandidateDescriptions(): void
    {
        $unweighted = $this->resolver->resolve('6201300000');
        $weighted = $this->resolver->resolve('6201300000', netWeightKg: 0.5);

        self::assertSame(8, $unweighted->candidateCount());
        self::assertSame(4, $weighted->candidateCount());
        self::assertTrue($weighted->narrowedByWeight);

        foreach ($weighted->candidates as $candidate) {
            self::assertStringNotContainsStringIgnoringCase(
                'weight',
                $candidate->description,
                'No declarable line carries weight text; the condition is inherited',
            );
        }
    }

    public function testALightGarmentSelectsTheLightBranch(): void
    {
        $sids = $this->sids($this->resolver->resolve('6201300000', netWeightKg: 0.5)->candidates);

        self::assertContains(Chapter62::COTTON_LIGHT_PARKA_SID, $sids);
        self::assertContains(Chapter62::COTTON_LIGHT_BATIK_SID, $sids);
        self::assertNotContains(Chapter62::COTTON_HEAVY_PARKA_SID, $sids);
        self::assertNotContains(Chapter62::COTTON_HEAVY_BATIK_SID, $sids);
    }

    public function testAHeavyGarmentSelectsTheHeavyBranch(): void
    {
        $sids = $this->sids($this->resolver->resolve('6201300000', netWeightKg: 1.5)->candidates);

        self::assertContains(Chapter62::COTTON_HEAVY_PARKA_SID, $sids);
        self::assertNotContains(Chapter62::COTTON_LIGHT_PARKA_SID, $sids);
    }

    /**
     * "Not exceeding 1 kg" is inclusive and "exceeding 1 kg" is exclusive, so a
     * garment of exactly 1 kg belongs to the lighter branch and to only one.
     */
    public function testTheThresholdItselfFallsToTheLightBranch(): void
    {
        $sids = $this->sids($this->resolver->resolve('6201300000', netWeightKg: 1.0)->candidates);

        self::assertContains(Chapter62::COTTON_LIGHT_PARKA_SID, $sids);
        self::assertNotContains(Chapter62::COTTON_HEAVY_PARKA_SID, $sids);
    }

    /**
     * Weight halves the field rather than resolving it. Four real classifications
     * remain on either side of the split, and only the merchant can choose
     * between a parka, a batik print and two kinds of other.
     */
    public function testNarrowingReducesWithoutResolving(): void
    {
        $resolution = $this->resolver->resolve('6201300000', netWeightKg: 0.5);

        self::assertTrue($resolution->isAmbiguous());
        self::assertSame(8, $resolution->candidatesBeforeNarrowing);
        self::assertSame(4, $resolution->candidateCount());
        self::assertSame(4, $resolution->candidatesEliminated());
    }

    /**
     * A condition on the matched line itself is shared by every candidate and
     * cannot discriminate between them, so it is not applied.
     */
    public function testAConditionOnTheMatchedLineDoesNotNarrow(): void
    {
        $resolution = $this->resolver->resolve('6201301000', netWeightKg: 0.5);

        self::assertTrue($resolution->isAmbiguous());
        self::assertSame(4, $resolution->candidateCount());
        self::assertFalse($resolution->narrowedByWeight);
    }

    public function testNarrowingCanResolveWhenTheBranchHasASingleLeaf(): void
    {
        $repository = new InMemoryCommodityRepository([
            new Commodity(sid: 1, code: '6201300000', productlineSuffix: '80',
                description: 'Of cotton', declarable: false),
            new Commodity(sid: 2, code: '6201301000', productlineSuffix: '80',
                description: 'Of a weight, per garment, not exceeding 1 kg',
                declarable: false, parentSid: 1),
            new Commodity(sid: 3, code: '6201301011', productlineSuffix: '80',
                description: 'Parkas', declarable: true, parentSid: 2),
            new Commodity(sid: 4, code: '6201309000', productlineSuffix: '80',
                description: 'Of a weight, per garment, exceeding 1 kg',
                declarable: false, parentSid: 1),
            new Commodity(sid: 5, code: '6201309011', productlineSuffix: '80',
                description: 'Parkas', declarable: true, parentSid: 4),
        ]);

        $resolution = (new CommodityResolver($repository))->resolve('6201300000', netWeightKg: 1.5);

        self::assertTrue($resolution->isResolved());
        self::assertSame(5, $resolution->commodity?->sid);
        self::assertTrue($resolution->narrowedByWeight);
    }

    /**
     * If weight contradicts every candidate the narrowing is abandoned rather
     * than presenting an empty list. That almost always means grams were
     * entered as kilograms.
     */
    public function testImpossibleWeightAbandonsNarrowing(): void
    {
        $repository = new InMemoryCommodityRepository([
            new Commodity(sid: 1, code: '6201300000', productlineSuffix: '80',
                description: 'Of cotton', declarable: false),
            new Commodity(sid: 2, code: '6201301000', productlineSuffix: '80',
                description: 'Of a weight, per garment, exceeding 5 kg',
                declarable: false, parentSid: 1),
            new Commodity(sid: 3, code: '6201301011', productlineSuffix: '80',
                description: 'Parkas', declarable: true, parentSid: 2),
            new Commodity(sid: 4, code: '6201301019', productlineSuffix: '80',
                description: 'Other', declarable: true, parentSid: 2),
        ]);

        $resolution = (new CommodityResolver($repository))->resolve('6201300000', netWeightKg: 0.5);

        self::assertTrue($resolution->isAmbiguous());
        self::assertSame(2, $resolution->candidateCount());
        self::assertFalse($resolution->narrowedByWeight);
    }

    public function testZeroOrNullWeightDoesNotNarrow(): void
    {
        foreach ([null, 0.0, -1.0] as $weight) {
            $resolution = $this->resolver->resolve('6201300000', netWeightKg: $weight);

            self::assertSame(8, $resolution->candidateCount());
            self::assertFalse($resolution->narrowedByWeight);
        }
    }

    /**
     * A candidate with no condition anywhere above it is never eliminated.
     * Discarding the correct classification is worse than one extra option.
     */
    public function testCandidatesWithNoConditionAboveThemSurvive(): void
    {
        $sids = $this->sids($this->resolver->resolve('6201000000', netWeightKg: 1.5)->candidates);

        self::assertContains(Chapter62::PONCHO_SID, $sids, 'The wool branch has no weight split');
        self::assertContains(Chapter62::WOOL_OTHER_TOP_SID, $sids);
    }

    // ---------------------------------------------------------------- outcomes

    public function testUnknownCodeIsReportedAsNotInMirror(): void
    {
        $resolution = $this->resolver->resolve('6299999999');

        self::assertSame(ResolutionOutcome::NotInMirror, $resolution->outcome);
        self::assertTrue($resolution->isConclusive());
    }

    /**
     * A chapter the sync has not pulled proves nothing about the code. Saying
     * otherwise reports a module failure as thousands of merchant errors.
     */
    public function testAnUnmirroredChapterIsInconclusive(): void
    {
        $resolution = $this->resolver->resolve('8501101000');

        self::assertSame(ResolutionOutcome::ChapterNotMirrored, $resolution->outcome);
        self::assertFalse($resolution->isConclusive());
    }

    public function testNationalChaptersAreReportedSeparately(): void
    {
        $resolution = $this->resolver->resolve('9901000000');

        self::assertSame(ResolutionOutcome::OutsideStandardNomenclature, $resolution->outcome);
        self::assertFalse($resolution->isConclusive());
    }

    public function testALineWithNothingDeclarableBeneathIsADeadEnd(): void
    {
        $repository = new InMemoryCommodityRepository([
            new Commodity(sid: 1, code: '6201300000', productlineSuffix: '10',
                description: 'Of cotton', declarable: false),
        ]);

        $resolution = (new CommodityResolver($repository))->resolve('6201300000');

        self::assertSame(ResolutionOutcome::DeadEnd, $resolution->outcome);
        self::assertSame(1, $resolution->matchedLine?->sid);
    }

    public function testASingleDescendantResolvesWithoutAskingTheMerchant(): void
    {
        $repository = new InMemoryCommodityRepository([
            new Commodity(sid: 1, code: '6201300000', productlineSuffix: '10',
                description: 'Of cotton', declarable: false),
            new Commodity(sid: 2, code: '6201301011', productlineSuffix: '80',
                description: 'Parkas', declarable: true, parentSid: 1),
        ]);

        self::assertTrue((new CommodityResolver($repository))->resolve('6201300000')->isResolved());
    }

    public function testMalformedInputDoesNotThrow(): void
    {
        self::assertSame(
            ResolutionOutcome::NotInMirror,
            $this->resolver->resolve('')->outcome,
        );
    }

    /**
     * Guards against a malformed mirror whose parent chain never terminates.
     */
    public function testACyclicParentChainTerminates(): void
    {
        $repository = new InMemoryCommodityRepository([
            new Commodity(sid: 1, code: '6201300000', productlineSuffix: '10',
                description: 'Of cotton', declarable: false, parentSid: 2),
            new Commodity(sid: 2, code: '6201301000', productlineSuffix: '10',
                description: 'Grouping', declarable: false, parentSid: 1),
            new Commodity(sid: 3, code: '6201301011', productlineSuffix: '80',
                description: 'Parkas', declarable: true, parentSid: 1),
            new Commodity(sid: 4, code: '6201301019', productlineSuffix: '80',
                description: 'Other', declarable: true, parentSid: 1),
        ]);

        $resolution = (new CommodityResolver($repository))->resolve('6201300000', netWeightKg: 1.5);

        self::assertSame(2, $resolution->candidateCount());
    }
}
