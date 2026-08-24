<?php

declare(strict_types=1);

namespace Davix\Customs\Tests\Rule\Checks;

use Davix\Customs\Product\ProductCustomsData;
use Davix\Customs\Rule\Checks\InvalidCodeFormat;
use Davix\Customs\Rule\Checks\MissingHsCode;
use Davix\Customs\Rule\Checks\MissingOrigin;
use Davix\Customs\Rule\EvaluationContext;
use Davix\Customs\Rule\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChecksTest extends TestCase
{
    private function product(?string $code = '6201401019', ?string $origin = 'CN'): ProductCustomsData
    {
        return ProductCustomsData::fromRawCode(
            identifier: '1',
            sku: 'ABC-001',
            name: 'Parka',
            rawHsCode: $code,
            countryOfOrigin: $origin,
        );
    }

    public function testMissingHsCodeFiresOnBlank(): void
    {
        $rule = new MissingHsCode();
        $issue = $rule->evaluate($this->product(code: null), EvaluationContext::at());

        self::assertNotNull($issue);
        self::assertSame(MissingHsCode::CODE, $issue->ruleCode);
        self::assertSame(Severity::Blocked, $issue->severity);
        self::assertSame('ABC-001', $issue->contextValue('sku'));
    }

    public function testMissingHsCodeFiresOnWhitespaceOnly(): void
    {
        $rule = new MissingHsCode();

        self::assertNotNull($rule->evaluate($this->product(code: '   '), EvaluationContext::at()));
    }

    public function testMissingHsCodePassesWhenACodeIsPresent(): void
    {
        $rule = new MissingHsCode();

        self::assertNull($rule->evaluate($this->product(), EvaluationContext::at()));
    }

    /**
     * Missing and malformed are different problems. A code that is present but
     * unreadable is not this rule's business.
     */
    public function testMissingHsCodeDoesNotFireOnAnUnreadableCode(): void
    {
        $rule = new MissingHsCode();

        self::assertNull($rule->evaluate($this->product(code: 'TBC'), EvaluationContext::at()));
    }

    public function testMissingHsCodeSuggestsAManualFix(): void
    {
        $rule = new MissingHsCode();
        $issue = $rule->evaluate($this->product(code: null), EvaluationContext::at());

        self::assertNotNull($issue);
        self::assertTrue($issue->isFixable());
        self::assertFalse($issue->isAutomaticallyFixable());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedCodeProvider(): array
    {
        return [
            'letters' => ['TBC', 'non_numeric'],
            'annotated' => ['6201401019 (jacket)', 'non_numeric'],
            'scientific notation' => ['6.20140102E+09', 'scientific_notation'],
            'two codes' => ['6201401019, 6202401019', 'multiple_values'],
            'too long' => ['620140101999', 'too_long'],
            'eleven digits reads as too long, not odd' => ['62014010199', 'too_long'],
            'reserved chapter' => ['7701000000', 'unknown_chapter'],
        ];
    }

    #[DataProvider('malformedCodeProvider')]
    public function testInvalidCodeFormatFires(string $code, string $expectedVariant): void
    {
        $rule = new InvalidCodeFormat();
        $issue = $rule->evaluate($this->product(code: $code), EvaluationContext::at());

        self::assertNotNull($issue, sprintf('Expected "%s" to fail', $code));
        self::assertSame(Severity::Blocked, $issue->severity);
        self::assertSame($expectedVariant, $issue->variant);
        self::assertSame($code, $issue->contextValue('raw'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wellFormedCodeProvider(): array
    {
        return [
            'ten digit' => ['6201401019'],
            'eight digit' => ['62014010'],
            'six digit' => ['620140'],
            'dotted' => ['6201.40.10.19'],
            'spaced' => ['6201 40 10 19'],
            'excel float' => ['6201401019.0'],
            'stripped leading zero' => ['101210000'],
        ];
    }

    #[DataProvider('wellFormedCodeProvider')]
    public function testInvalidCodeFormatPassesWellFormedCodes(string $code): void
    {
        $rule = new InvalidCodeFormat();

        self::assertNull(
            $rule->evaluate($this->product(code: $code), EvaluationContext::at()),
            sprintf('Expected "%s" to pass after normalisation', $code),
        );
    }

    /**
     * The decision that keeps this rule apart from the expansion rule. A
     * six-digit subheading is what most merchants hold and HMRC recognises it.
     */
    public function testSixDigitSubheadingIsNotAFormatFailure(): void
    {
        $rule = new InvalidCodeFormat();

        self::assertNull($rule->evaluate($this->product(code: '620140'), EvaluationContext::at()));
    }

    public function testInvalidCodeFormatDeclaresItsPrerequisite(): void
    {
        self::assertSame([MissingHsCode::CODE], (new InvalidCodeFormat())->prerequisites());
    }

    /**
     * Odd-length codes never reach this rule as odd. The normaliser treats an
     * odd length up to nine digits as a stripped leading zero and pads it, and
     * anything above ten digits is reported as too long. The odd-length
     * failure survives only for the lookup tool, which validates raw input.
     */
    public function testOddLengthIsResolvedBeforeReachingTheRule(): void
    {
        $rule = new InvalidCodeFormat();

        // Padded to eight digits and accepted.
        self::assertNull($rule->evaluate($this->product(code: '6201401'), EvaluationContext::at()));

        // Too long to pad, so reported as length rather than parity.
        $issue = $rule->evaluate($this->product(code: '62014010199'), EvaluationContext::at());
        self::assertSame('too_long', $issue?->variant);
    }

    /**
     * Defensive: the prerequisite normally catches blanks, but a merchant can
     * disable it, and a disabled prerequisite is treated as passing.
     */
    public function testInvalidCodeFormatIgnoresBlanksEvenWithoutItsPrerequisite(): void
    {
        $rule = new InvalidCodeFormat();

        self::assertNull($rule->evaluate($this->product(code: null), EvaluationContext::at()));
        self::assertNull($rule->evaluate($this->product(code: ''), EvaluationContext::at()));
    }

    public function testMissingOriginFires(): void
    {
        $rule = new MissingOrigin();
        $issue = $rule->evaluate($this->product(origin: null), EvaluationContext::at());

        self::assertNotNull($issue);
        self::assertSame(Severity::Attention, $issue->severity);
    }

    public function testMissingOriginFiresOnWhitespace(): void
    {
        $rule = new MissingOrigin();

        self::assertNotNull($rule->evaluate($this->product(origin: '  '), EvaluationContext::at()));
    }

    public function testMissingOriginPasses(): void
    {
        $rule = new MissingOrigin();

        self::assertNull($rule->evaluate($this->product(origin: 'CN'), EvaluationContext::at()));
    }

    /**
     * Origin is independent of classification: a product can have a perfect
     * ten-digit code and still be missing its origin.
     */
    public function testMissingOriginHasNoPrerequisites(): void
    {
        self::assertSame([], (new MissingOrigin())->prerequisites());
    }
}