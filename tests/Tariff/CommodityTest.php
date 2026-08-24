<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Tariff;

use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tests\Fixtures\ChapterSixtyTwoFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CommodityTest extends TestCase
{
    private function line(
        int $sid = 1,
        string $code = '6201401019',
        string $suffix = Commodity::SUFFIX_DECLARABLE,
        bool $declarable = true,
        ?int $parentSid = null,
        ?DateTimeImmutable $start = null,
        ?DateTimeImmutable $end = null,
        ?string $supplementaryUnit = null,
    ): Commodity {
        return new Commodity(
            sid: $sid,
            code: $code,
            productlineSuffix: $suffix,
            description: 'Test line',
            declarable: $declarable,
            parentSid: $parentSid,
            validityStart: $start,
            validityEnd: $end,
            supplementaryUnit: $supplementaryUnit,
        );
    }

    public function testDeclarability(): void
    {
        self::assertTrue($this->line(declarable: true)->isDeclarable());
        self::assertFalse($this->line(declarable: false)->isDeclarable());
    }

    /**
     * Declarability comes from the explicit flag, not from the suffix. The two
     * can disagree at the edges of the tree, and HMRC supplies the flag.
     */
    public function testDeclarabilityIsNotInferredFromSuffix(): void
    {
        $line = $this->line(suffix: Commodity::SUFFIX_DECLARABLE, declarable: false);

        self::assertFalse($line->isDeclarable());
        self::assertFalse($line->isIntermediate());
    }

    public function testIntermediateLinesAreIdentifiedBySuffix(): void
    {
        self::assertTrue($this->line(suffix: Commodity::SUFFIX_INTERMEDIATE)->isIntermediate());
        self::assertFalse($this->line(suffix: Commodity::SUFFIX_DECLARABLE)->isIntermediate());
    }

    public function testRootDetection(): void
    {
        self::assertTrue($this->line(parentSid: null)->isRoot());
        self::assertFalse($this->line(parentSid: 999)->isRoot());
    }

    public function testSupplementaryUnitRequirement(): void
    {
        self::assertTrue($this->line(supplementaryUnit: 'NAR')->requiresSupplementaryUnit());
        self::assertFalse($this->line(supplementaryUnit: null)->requiresSupplementaryUnit());
        self::assertFalse($this->line(supplementaryUnit: '')->requiresSupplementaryUnit());
    }

    public function testValidityWithBothDates(): void
    {
        $line = $this->line(
            start: new DateTimeImmutable('2022-01-01'),
            end: new DateTimeImmutable('2024-12-31'),
        );

        self::assertFalse($line->wasInForceOn(new DateTimeImmutable('2021-06-01')));
        self::assertTrue($line->wasInForceOn(new DateTimeImmutable('2022-01-01')));
        self::assertTrue($line->wasInForceOn(new DateTimeImmutable('2023-06-01')));
        self::assertTrue($line->wasInForceOn(new DateTimeImmutable('2024-12-31')));
        self::assertFalse($line->wasInForceOn(new DateTimeImmutable('2025-06-01')));
    }

    /**
     * Chapter pulls filtered by as_of return null end dates throughout, so a
     * null end must mean "still in force" rather than "unknown".
     */
    public function testNullEndDateMeansStillInForce(): void
    {
        $line = $this->line(start: new DateTimeImmutable('2022-01-01'), end: null);

        self::assertTrue($line->wasInForceOn(new DateTimeImmutable('2099-01-01')));
    }

    public function testNullStartDateMeansAlwaysInForce(): void
    {
        $line = $this->line(start: null, end: null);

        self::assertTrue($line->wasInForceOn(new DateTimeImmutable('1990-01-01')));
    }

    public function testExpiryDetection(): void
    {
        $line = $this->line(end: new DateTimeImmutable('2026-12-31'));

        self::assertTrue($line->expiresAfter(new DateTimeImmutable('2026-08-01')));
        self::assertFalse($line->expiresAfter(new DateTimeImmutable('2027-01-01')));
    }

    public function testLineWithNoEndDateNeverExpires(): void
    {
        self::assertFalse($this->line(end: null)->expiresAfter(new DateTimeImmutable('2026-08-01')));
    }

    public function testChildRelationship(): void
    {
        $parent = $this->line(sid: 100);
        $child = $this->line(sid: 200, parentSid: 100);
        $stranger = $this->line(sid: 300, parentSid: 999);

        self::assertTrue($child->isChildOf($parent));
        self::assertFalse($stranger->isChildOf($parent));
        self::assertFalse($parent->isChildOf($child));
    }

    /**
     * Identity is the SID, never the code. Two lines sharing a code are not
     * the same line — that is the whole reason the SID is the natural key.
     */
    public function testIdentityIsBySidNotCode(): void
    {
        $intermediate = $this->line(sid: 106844, code: '6201200011');
        $declarable = $this->line(sid: 106845, code: '6201200011');

        self::assertFalse($intermediate->isSameAs($declarable));
        self::assertTrue($intermediate->isSameAs($this->line(sid: 106844, code: 'different')));
    }

    public function testFixtureModelsTheRealDuplicateCodePair(): void
    {
        $lines = ChapterSixtyTwoFixture::commodities();

        $sharing = array_values(array_filter(
            $lines,
            static fn (Commodity $c): bool => $c->code === '6201200011',
        ));

        self::assertCount(2, $sharing, 'Chapter 62 has two lines carrying 6201200011');
        self::assertNotSame($sharing[0]->sid, $sharing[1]->sid);
        self::assertNotSame($sharing[0]->productlineSuffix, $sharing[1]->productlineSuffix);
    }
}
