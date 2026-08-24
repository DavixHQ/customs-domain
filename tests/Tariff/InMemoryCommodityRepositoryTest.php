<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Tariff;

use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\InMemoryCommodityRepository;
use Davix\Customs\Tests\Fixtures\ChapterSixtyTwoFixture;
use PHPUnit\Framework\TestCase;

final class InMemoryCommodityRepositoryTest extends TestCase
{
    private InMemoryCommodityRepository $repository;

    protected function setUp(): void
    {
        $this->repository = ChapterSixtyTwoFixture::repository();
    }

    public function testFindBySid(): void
    {
        $line = $this->repository->findBySid(ChapterSixtyTwoFixture::COTTON_LIGHT_PARKA_SID);

        self::assertInstanceOf(Commodity::class, $line);
        self::assertSame('6201301011', $line->code);
    }

    public function testFindBySidReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->repository->findBySid(999999));
    }

    /**
     * The behaviour the whole schema design exists to support. Keying storage
     * on the commodity code would return one of these and silently lose the
     * other.
     */
    public function testFindByCodeReturnsEveryLineSharingThatCode(): void
    {
        $lines = $this->repository->findByCode('6201200011');

        self::assertCount(2, $lines);

        $sids = array_map(static fn (Commodity $c): int => $c->sid, $lines);
        self::assertContains(ChapterSixtyTwoFixture::PONCHO_GROUPING_SID, $sids);
        self::assertContains(ChapterSixtyTwoFixture::PONCHO_SID, $sids);
    }

    public function testFindByCodeReturnsEmptyListWhenAbsent(): void
    {
        self::assertSame([], $this->repository->findByCode('9999999999'));
    }

    public function testFindDeclarablePicksTheDeclarableLineFromADuplicatePair(): void
    {
        $line = $this->repository->findDeclarable('6201200011');

        self::assertInstanceOf(Commodity::class, $line);
        self::assertSame(ChapterSixtyTwoFixture::PONCHO_SID, $line->sid);
        self::assertTrue($line->isDeclarable());
    }

    /**
     * Absent and present-but-not-declarable both return null. Callers needing
     * to tell them apart use findByCode, which is why that method exists.
     */
    public function testFindDeclarableReturnsNullForAnIntermediateOnlyCode(): void
    {
        $repository = new InMemoryCommodityRepository([
            new Commodity(
                sid: 1,
                code: '620140',
                productlineSuffix: Commodity::SUFFIX_INTERMEDIATE,
                description: 'Grouping only',
                declarable: false,
            ),
        ]);

        self::assertNull($repository->findDeclarable('620140'));
        self::assertCount(1, $repository->findByCode('620140'));
    }

    public function testFindDeclarableReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->repository->findDeclarable('9999999999'));
    }

    public function testChildrenOf(): void
    {
        $children = $this->repository->childrenOf(ChapterSixtyTwoFixture::COTTON_SUBHEADING_SID);

        // The two weight-split grouping lines. Everything declarable sits
        // two levels deeper.
        self::assertCount(2, $children);
    }

    public function testChildrenOfLeafIsEmpty(): void
    {
        self::assertSame([], $this->repository->childrenOf(ChapterSixtyTwoFixture::COTTON_LIGHT_PARKA_SID));
    }

    public function testDeclarableDescendantsOfSubheading(): void
    {
        $candidates = $this->repository->declarableDescendantsOf(
            ChapterSixtyTwoFixture::COTTON_SUBHEADING_SID,
        );

        self::assertCount(ChapterSixtyTwoFixture::COTTON_DECLARABLE_COUNT, $candidates);

        foreach ($candidates as $candidate) {
            self::assertTrue($candidate->isDeclarable());
        }
    }

    /**
     * Descent must cross intermediate lines rather than stopping at them. The
     * wool branch is heading → intermediate → declarable, and the declarable
     * line has to be reachable from the heading.
     */
    public function testDeclarableDescendantsDescendThroughIntermediateLines(): void
    {
        $candidates = $this->repository->declarableDescendantsOf(
            ChapterSixtyTwoFixture::HEADING_SID,
        );

        $sids = array_map(static fn (Commodity $c): int => $c->sid, $candidates);

        self::assertContains(ChapterSixtyTwoFixture::PONCHO_SID, $sids);
        self::assertNotContains(ChapterSixtyTwoFixture::PONCHO_GROUPING_SID, $sids);
        self::assertCount(
            ChapterSixtyTwoFixture::HEADING_DECLARABLE_COUNT,
            $candidates,
            'Three wool lines plus eight cotton lines',
        );
    }

    public function testDeclarableDescendantsOfLeafIsEmpty(): void
    {
        self::assertSame(
            [],
            $this->repository->declarableDescendantsOf(ChapterSixtyTwoFixture::COTTON_LIGHT_PARKA_SID),
        );
    }

    public function testHasChapter(): void
    {
        self::assertTrue($this->repository->hasChapter('62'));
        self::assertFalse($this->repository->hasChapter('01'));
    }

    public function testEmptyRepository(): void
    {
        $repository = new InMemoryCommodityRepository();

        self::assertSame(0, $repository->count());
        self::assertNull($repository->findBySid(1));
        self::assertSame([], $repository->findByCode('6201401019'));
        self::assertFalse($repository->hasChapter('62'));
    }

    public function testAddingLinesIncrementally(): void
    {
        $repository = new InMemoryCommodityRepository();
        $repository->add(ChapterSixtyTwoFixture::withdrawnLine());

        self::assertSame(1, $repository->count());
        self::assertCount(1, $repository->findByCode('6201930000'));
    }

    /**
     * A corrupt mirror can contain a parent reference that loops back on
     * itself — a partial or interrupted sync is exactly the situation that
     * produces one. Without a visited set this walk recurses until memory is
     * exhausted, taking the whole scan with it.
     */
    public function testACyclicParentReferenceDoesNotRecurseForever(): void
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

        self::assertCount(2, $repository->declarableDescendantsOf(1));
    }

    public function testASelfParentingLineDoesNotRecurseForever(): void
    {
        $repository = new InMemoryCommodityRepository([
            new Commodity(sid: 1, code: '6201300000', productlineSuffix: '10',
                description: 'Of cotton', declarable: false, parentSid: 1),
            new Commodity(sid: 2, code: '6201301011', productlineSuffix: '80',
                description: 'Parkas', declarable: true, parentSid: 1),
        ]);

        self::assertCount(1, $repository->declarableDescendantsOf(1));
    }
}
