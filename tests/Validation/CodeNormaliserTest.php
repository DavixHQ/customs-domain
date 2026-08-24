<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Validation;

use Davix\Customs\Exception\NormalisationFailedException;
use Davix\Customs\Validation\CodeNormaliser;
use Davix\Customs\Validation\NormalisationFailure;
use Davix\Customs\Validation\Transformation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeNormaliserTest extends TestCase
{
    private CodeNormaliser $normaliser;

    protected function setUp(): void
    {
        $this->normaliser = new CodeNormaliser();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cleanInputProvider(): array
    {
        return [
            'six digit' => ['620140', '620140'],
            'eight digit' => ['62014010', '62014010'],
            'ten digit' => ['6201401019', '6201401019'],
            'heading' => ['6201', '6201'],
            'chapter with leading zero intact' => ['0101210000', '0101210000'],
        ];
    }

    #[DataProvider('cleanInputProvider')]
    public function testCleanInputPassesThroughUntouched(string $raw, string $expected): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertTrue($result->isSuccess());
        self::assertSame($expected, $result->code());
        self::assertFalse($result->wasModified(), 'Clean input should record no transformations');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function separatorProvider(): array
    {
        return [
            'dotted ten digit' => ['6201.40.10.19', '6201401019'],
            'dotted six digit' => ['6201.40', '620140'],
            'space grouped' => ['6201 40 10 19', '6201401019'],
            'hyphen grouped' => ['6201-40-10-19', '6201401019'],
            'underscore grouped' => ['6201_40_10_19', '6201401019'],
            'slash grouped' => ['6201/40/10/19', '6201401019'],
            'mixed separators' => ['6201.40 10-19', '6201401019'],
            'dotted with zero pair ending' => ['0101.21.00.00', '0101210000'],
        ];
    }

    #[DataProvider('separatorProvider')]
    public function testSeparatorsAreRemoved(string $raw, string $expected): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertTrue($result->isSuccess(), sprintf('Expected "%s" to normalise', $raw));
        self::assertSame($expected, $result->code());
        self::assertTrue($result->applied(Transformation::RemovedSeparators));
    }

    /**
     * The case that matters most: a dotted code whose final group is a zero
     * pair must not be mistaken for a float and truncated.
     */
    public function testDottedCodeEndingInZeroPairKeepsAllDigits(): void
    {
        $result = $this->normaliser->normalise('0101.21.00.00');

        self::assertSame('0101210000', $result->code());
        self::assertFalse(
            $result->applied(Transformation::DroppedDecimalZeroes),
            'A dotted code must not be treated as a float',
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function excelFloatProvider(): array
    {
        return [
            'single trailing zero' => ['6201401019.0', '6201401019'],
            'double trailing zero' => ['6201401019.00', '6201401019'],
            'eight digit float' => ['62014010.0', '62014010'],
            'six digit float' => ['620140.0', '620140'],
        ];
    }

    #[DataProvider('excelFloatProvider')]
    public function testExcelFloatTailIsDropped(string $raw, string $expected): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertSame($expected, $result->code());
        self::assertTrue($result->applied(Transformation::DroppedDecimalZeroes));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function strippedLeadingZeroProvider(): array
    {
        return [
            'nine digit becomes ten' => ['101210000', '0101210000'],
            'seven digit becomes eight' => ['1012100', '01012100'],
            'five digit becomes six' => ['10121', '010121'],
            'three digit becomes four' => ['101', '0101'],
            'dotted then padded' => ['101.21.00.00', '0101210000'],
        ];
    }

    #[DataProvider('strippedLeadingZeroProvider')]
    public function testStrippedLeadingZeroIsRestored(string $raw, string $expected): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertSame($expected, $result->code());
        self::assertTrue($result->applied(Transformation::PaddedLeadingZero));
    }

    public function testOverlongOddValueIsNotPadded(): void
    {
        // Eleven digits is malformed, not a stripped leading zero. Passing it
        // through unpadded lets the format rule reject it honestly.
        $result = $this->normaliser->normalise('62014010199');

        self::assertTrue($result->isSuccess());
        self::assertSame('62014010199', $result->code());
        self::assertFalse($result->applied(Transformation::PaddedLeadingZero));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scientificNotationProvider(): array
    {
        return [
            'uppercase E' => ['6.20140102E+09'],
            'lowercase e' => ['6.20140102e+09'],
            'no sign' => ['6.2014E9'],
            'comma decimal' => ['6,20140102E+09'],
        ];
    }

    #[DataProvider('scientificNotationProvider')]
    public function testScientificNotationIsRefusedRatherThanGuessed(string $raw): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertTrue($result->isFailure());
        self::assertSame(NormalisationFailure::ScientificNotation, $result->failure);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function multipleValueProvider(): array
    {
        return [
            'comma separated' => ['6201401019, 6202401019'],
            'semicolon separated' => ['6201401019; 6202401019'],
            'pipe separated' => ['6201401019|6202401019'],
            'newline separated' => ["6201401019\n6202401019"],
            'ampersand separated' => ['6201401019 & 6202401019'],
            'two six digit codes' => ['620140,620240'],
        ];
    }

    #[DataProvider('multipleValueProvider')]
    public function testMultipleCodesInOneCellAreRefused(string $raw): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertTrue($result->isFailure());
        self::assertSame(NormalisationFailure::MultipleValues, $result->failure);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonNumericProvider(): array
    {
        return [
            'prefixed' => ['HS6201401019'],
            'labelled' => ['Code: 6201401019'],
            'annotated' => ['6201401019 (jacket)'],
            'placeholder text' => ['TBC'],
            'not applicable' => ['n/a'],
        ];
    }

    #[DataProvider('nonNumericProvider')]
    public function testUnrecognisedCharactersAreRefused(string $raw): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertTrue($result->isFailure());
        self::assertSame(NormalisationFailure::NonNumeric, $result->failure);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function blankProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'spaces' => ['   '],
            'tab' => ["\t"],
            'non breaking space' => ["\xC2\xA0"],
            'zero width space' => ["\xE2\x80\x8B"],
        ];
    }

    #[DataProvider('blankProvider')]
    public function testBlankInputIsReportedAsBlankNotInvalid(?string $raw): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertTrue($result->isFailure());
        self::assertSame(
            NormalisationFailure::Blank,
            $result->failure,
            'Missing data is a different issue from malformed data and must not be conflated',
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invisibleWhitespaceProvider(): array
    {
        return [
            'leading and trailing spaces' => ['  6201401019  ', '6201401019'],
            'non breaking space' => ["\xC2\xA06201401019", '6201401019'],
            'byte order mark' => ["\xEF\xBB\xBF6201401019", '6201401019'],
            'zero width space' => ["6201401019\xE2\x80\x8B", '6201401019'],
        ];
    }

    #[DataProvider('invisibleWhitespaceProvider')]
    public function testInvisibleWhitespaceIsRemoved(string $raw, string $expected): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertTrue($result->isSuccess(), 'Invisible whitespace must not break normalisation');
        self::assertSame($expected, $result->code());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function quotedProvider(): array
    {
        return [
            'excel text apostrophe' => ["'6201401019", '6201401019'],
            'double quoted' => ['"6201401019"', '6201401019'],
            'smart quotes' => ['“6201401019”', '6201401019'],
        ];
    }

    #[DataProvider('quotedProvider')]
    public function testSpreadsheetQuotingIsRemoved(string $raw, string $expected): void
    {
        $result = $this->normaliser->normalise($raw);

        self::assertTrue($result->isSuccess());
        self::assertSame($expected, $result->code());
        self::assertTrue($result->applied(Transformation::RemovedQuoting));
    }

    public function testRawInputIsPreservedForMessaging(): void
    {
        $result = $this->normaliser->normalise('  6201.40.10.19 ');

        self::assertSame('  6201.40.10.19 ', $result->raw);
        self::assertSame('6201401019', $result->code());
    }

    public function testAccessingCodeOnFailureThrows(): void
    {
        $result = $this->normaliser->normalise('TBC');

        $this->expectException(NormalisationFailedException::class);
        $this->expectExceptionMessage('TBC');

        $result->code();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function displayFormatProvider(): array
    {
        return [
            'ten digit' => ['6201401019', '6201.40.10.19'],
            'eight digit' => ['62014010', '6201.40.10'],
            'six digit' => ['620140', '6201.40'],
            'heading left alone' => ['6201', '6201'],
            'chapter left alone' => ['62', '62'],
            'odd length left alone' => ['62014010199', '62014010199'],
            'leading zero preserved' => ['0101210000', '0101.21.00.00'],
        ];
    }

    #[DataProvider('displayFormatProvider')]
    public function testDisplayFormatting(string $code, string $expected): void
    {
        self::assertSame($expected, $this->normaliser->formatForDisplay($code));
    }

    public function testNormaliseThenFormatRoundTripsMerchantInput(): void
    {
        $result = $this->normaliser->normalise('6201 40 10 19');

        self::assertSame(
            '6201.40.10.19',
            $this->normaliser->formatForDisplay($result->code()),
        );
    }
}
