<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Validation;

use Davix\Customs\Exception\InvalidCodeException;
use Davix\Customs\Validation\CodeFormat;
use Davix\Customs\Validation\CodeLevel;
use Davix\Customs\Validation\FormatFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeFormatTest extends TestCase
{
    private CodeFormat $format;

    protected function setUp(): void
    {
        $this->format = new CodeFormat();
    }

    /**
     * @return array<string, array{string, CodeLevel}>
     */
    public static function validProductCodeProvider(): array
    {
        return [
            'subheading' => ['620140', CodeLevel::Subheading],
            'combined nomenclature' => ['62014010', CodeLevel::Combined],
            'commodity' => ['6201401019', CodeLevel::Commodity],
            'chapter one with leading zero' => ['0101210000', CodeLevel::Commodity],
            'chapter ninety nine national' => ['9901000000', CodeLevel::Commodity],
        ];
    }

    #[DataProvider('validProductCodeProvider')]
    public function testWellFormedProductCodesAreAccepted(string $code, CodeLevel $expected): void
    {
        $result = $this->format->validate($code);

        self::assertTrue($result->isValid(), sprintf('Expected "%s" to be well formed', $code));
        self::assertSame($expected, $result->level());
    }

    /**
     * The decision that keeps the format rule and the expansion rule apart.
     *
     * A six-digit subheading is what most merchants hold, it is correct on an
     * export declaration, and HMRC recognises it. Failing it as malformed
     * would tell merchants their supplier's data is invalid when the real
     * problem is that it needs expanding.
     */
    public function testSixDigitSubheadingIsValidButNeedsExpansion(): void
    {
        $result = $this->format->validate('620140');

        self::assertTrue($result->isValid());
        self::assertTrue($result->needsExpansion());
        self::assertFalse($result->level()->isDeclarable());
    }

    public function testTenDigitCommodityIsValidAndNeedsNoExpansion(): void
    {
        $result = $this->format->validate('6201401019');

        self::assertTrue($result->isValid());
        self::assertFalse($result->needsExpansion());
        self::assertTrue($result->level()->isDeclarable());
    }

    /**
     * @return array<string, array{string, FormatFailure}>
     */
    public static function invalidProductCodeProvider(): array
    {
        return [
            'blank' => ['', FormatFailure::Blank],
            'letters' => ['62014A1019', FormatFailure::NotNumeric],
            'chapter too coarse' => ['62', FormatFailure::TooShort],
            'heading too coarse' => ['6201', FormatFailure::TooShort],
            'seven digits' => ['6201401', FormatFailure::OddLength],
            'nine digits' => ['620140101', FormatFailure::OddLength],
            'eleven digits' => ['62014010199', FormatFailure::TooLong],
            'twelve digits' => ['620140101999', FormatFailure::TooLong],
            'chapter zero' => ['001010000', FormatFailure::OddLength],
            'chapter zero even length' => ['0010100000', FormatFailure::UnknownChapter],
            'chapter seventy seven' => ['7701000000', FormatFailure::UnknownChapter],
        ];
    }

    #[DataProvider('invalidProductCodeProvider')]
    public function testMalformedCodesAreRejectedWithTheRightReason(
        string $code,
        FormatFailure $expected,
    ): void {
        $result = $this->format->validate($code);

        self::assertTrue($result->isInvalid(), sprintf('Expected "%s" to be rejected', $code));
        self::assertSame($expected, $result->failure);
    }

    public function testReservedChapterIsReportedAheadOfLength(): void
    {
        // A four-digit code in chapter 77 is both too short and in a chapter
        // that does not exist. The chapter is the more useful thing to say.
        $result = $this->format->validateForLookup('7701');

        self::assertSame(FormatFailure::UnknownChapter, $result->failure);
    }

    /**
     * @return array<string, array{string, CodeLevel}>
     */
    public static function lookupLevelProvider(): array
    {
        return [
            'chapter' => ['62', CodeLevel::Chapter],
            'heading' => ['6201', CodeLevel::Heading],
            'subheading' => ['620140', CodeLevel::Subheading],
            'combined' => ['62014010', CodeLevel::Combined],
            'commodity' => ['6201401019', CodeLevel::Commodity],
        ];
    }

    #[DataProvider('lookupLevelProvider')]
    public function testLookupAcceptsEveryHierarchyLevel(string $code, CodeLevel $expected): void
    {
        $result = $this->format->validateForLookup($code);

        self::assertTrue($result->isValid());
        self::assertSame($expected, $result->level());
    }

    public function testLookupStillRejectsMalformedCodes(): void
    {
        self::assertTrue($this->format->validateForLookup('620')->isInvalid());
        self::assertTrue($this->format->validateForLookup('abc')->isInvalid());
    }

    /**
     * Format is about shape, not existence. Only the nomenclature mirror can
     * say whether a code is real.
     */
    public function testNonexistentButWellShapedCodeIsAccepted(): void
    {
        self::assertTrue($this->format->validate('9999999999')->isValid());
    }

    public function testIsValidShorthand(): void
    {
        self::assertTrue($this->format->isValid('6201401019'));
        self::assertFalse($this->format->isValid('6201'));
    }

    /**
     * @return array<string, array{string, ?CodeLevel}>
     */
    public static function levelOfProvider(): array
    {
        return [
            'two digits' => ['62', CodeLevel::Chapter],
            'ten digits' => ['6201401019', CodeLevel::Commodity],
            'odd length' => ['6201401', null],
            'too long' => ['62014010199', null],
            'not numeric' => ['62A1', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('levelOfProvider')]
    public function testLevelOf(string $code, ?CodeLevel $expected): void
    {
        self::assertSame($expected, $this->format->levelOf($code));
    }

    public function testIsDeclarableLength(): void
    {
        self::assertTrue($this->format->isDeclarableLength('6201401019'));
        self::assertFalse($this->format->isDeclarableLength('62014010'));
        self::assertFalse($this->format->isDeclarableLength('620140'));
        self::assertFalse($this->format->isDeclarableLength('nonsense'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function nationalChapterProvider(): array
    {
        return [
            'chapter 98' => ['9801000000', true],
            'chapter 99' => ['9901000000', true],
            'chapter 62' => ['6201401019', false],
            'chapter 01' => ['0101210000', false],
            'not numeric' => ['abcd', false],
        ];
    }

    #[DataProvider('nationalChapterProvider')]
    public function testNationalChaptersAreIdentified(string $code, bool $expected): void
    {
        self::assertSame($expected, $this->format->isOutsideStandardNomenclature($code));
    }

    public function testHierarchyTruncation(): void
    {
        self::assertSame('62', $this->format->chapterOf('6201401019'));
        self::assertSame('6201', $this->format->headingOf('6201401019'));
        self::assertSame('620140', $this->format->subheadingOf('6201401019'));
    }

    public function testTruncationPreservesLeadingZeroes(): void
    {
        self::assertSame('01', $this->format->chapterOf('0101210000'));
        self::assertSame('0101', $this->format->headingOf('0101210000'));
    }

    public function testTruncatingBeyondAvailableDigitsReturnsNull(): void
    {
        // Inventing digits would fabricate a classification.
        self::assertNull($this->format->truncateTo('6201', CodeLevel::Commodity));
        self::assertNull($this->format->subheadingOf('6201'));
    }

    public function testTruncatingToOwnLevelIsIdentity(): void
    {
        self::assertSame('6201401019', $this->format->truncateTo('6201401019', CodeLevel::Commodity));
    }

    public function testTruncatingNonNumericReturnsNull(): void
    {
        self::assertNull($this->format->chapterOf('not a code'));
    }

    public function testPadding(): void
    {
        self::assertSame('6201400000', $this->format->padTo('620140', CodeLevel::Commodity));
        self::assertSame('62014000', $this->format->padTo('620140', CodeLevel::Combined));
        self::assertSame('6201000000', $this->format->padTo('6201', CodeLevel::Commodity));
    }

    public function testPaddingToShorterLevelReturnsNull(): void
    {
        self::assertNull($this->format->padTo('6201401019', CodeLevel::Subheading));
    }

    public function testPaddingToOwnLevelIsIdentity(): void
    {
        self::assertSame('6201401019', $this->format->padTo('6201401019', CodeLevel::Commodity));
    }

    public function testLevelComparison(): void
    {
        self::assertTrue(CodeLevel::Commodity->isMoreSpecificThan(CodeLevel::Subheading));
        self::assertFalse(CodeLevel::Subheading->isMoreSpecificThan(CodeLevel::Commodity));
        self::assertFalse(CodeLevel::Commodity->isMoreSpecificThan(CodeLevel::Commodity));
    }

    public function testAccessingLevelOnInvalidResultThrows(): void
    {
        $result = $this->format->validate('6201');

        $this->expectException(InvalidCodeException::class);
        $this->expectExceptionMessage('6201');

        $result->level();
    }

    /**
     * The two validation classes compose: whatever the normaliser produces
     * should be safe to hand straight to the format check.
     */
    public function testNormaliserOutputFlowsIntoFormatCheck(): void
    {
        $normaliser = new \Davix\Customs\Validation\CodeNormaliser();
        $normalised = $normaliser->normalise('6201.40.10.19');

        self::assertTrue($normalised->isSuccess());

        $result = $this->format->validate($normalised->code());

        self::assertTrue($result->isValid());
        self::assertSame(CodeLevel::Commodity, $result->level());
    }
}
