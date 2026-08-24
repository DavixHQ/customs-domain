<?php

declare(strict_types=1);

namespace Davix\Customs\Validation;

/**
 * Decides whether a code is well formed, and at which level of the tariff
 * hierarchy it sits.
 *
 * This runs behind CodeNormaliser, and the division between them is
 * deliberate. The normaliser answers "can I read a single commodity code out
 * of this?" — it repairs spreadsheet damage and refuses to guess. This class
 * answers "is the code I read a shape a commodity code can actually be?"
 *
 * Equally deliberate is what it does *not* judge. A well-formed six-digit
 * subheading is valid here even though it cannot be used on a UK import
 * declaration. That is a classification problem, handled by the ambiguous
 * expansion rule, which can offer the merchant the candidate ten-digit codes
 * beneath it. Failing it as a format error would tell a merchant that the code
 * their supplier gave them and HMRC recognises is invalid, which is both wrong
 * and a fast way to lose their confidence in everything else the module says.
 *
 * Nor does it check that a code exists. Only the nomenclature mirror can
 * answer that, and it is the unknown and withdrawn rules that act on it.
 * `9999999999` is well formed and does not exist.
 */
final class CodeFormat
{
    /**
     * Chapters that do not exist. 00 is not a chapter; 77 is reserved for
     * possible future international use and currently holds no goods.
     *
     * @var list<string>
     */
    private const RESERVED_CHAPTERS = ['00', '77'];

    /**
     * Chapters reserved for national use. These are real and appear in the UK
     * tariff for special procedures, but they sit outside the standard 01–97
     * nomenclature that the chapter sync pulls.
     *
     * @var list<string>
     */
    private const NATIONAL_CHAPTERS = ['98', '99'];

    /**
     * Levels acceptable as a product's customs classification.
     *
     * @var list<CodeLevel>
     */
    private const PRODUCT_LEVELS = [
        CodeLevel::Subheading,
        CodeLevel::Combined,
        CodeLevel::Commodity,
    ];

    /**
     * Levels acceptable when searching or browsing the tariff, where a
     * merchant legitimately starts from a chapter or heading.
     *
     * @var list<CodeLevel>
     */
    private const LOOKUP_LEVELS = [
        CodeLevel::Chapter,
        CodeLevel::Heading,
        CodeLevel::Subheading,
        CodeLevel::Combined,
        CodeLevel::Commodity,
    ];

    /**
     * Validate a code held against a product.
     *
     * Accepts six, eight and ten digits. A chapter or heading is well formed
     * in the abstract but too coarse to classify goods, so it fails as
     * TooShort rather than being silently accepted.
     */
    public function validate(string $code): CodeFormatResult
    {
        return $this->check($code, self::PRODUCT_LEVELS);
    }

    /**
     * Validate a code entered into the lookup tool, where starting from a
     * chapter or heading is a normal way to browse.
     */
    public function validateForLookup(string $code): CodeFormatResult
    {
        return $this->check($code, self::LOOKUP_LEVELS);
    }

    public function isValid(string $code): bool
    {
        return $this->validate($code)->isValid();
    }

    /**
     * The level a code sits at, or null if it is not a recognised length.
     */
    public function levelOf(string $code): ?CodeLevel
    {
        if (!$this->isNumeric($code)) {
            return null;
        }

        return CodeLevel::fromLength(strlen($code));
    }

    /**
     * Whether a code is long enough to be declared on a UK import.
     */
    public function isDeclarableLength(string $code): bool
    {
        return $this->levelOf($code)?->isDeclarable() ?? false;
    }

    /**
     * Whether a code sits in a chapter the standard nomenclature sync does
     * not cover.
     *
     * Chapters 98 and 99 are real but national. Treating a code in one of
     * them as simply unknown would be misleading, since the mirror was never
     * going to contain it.
     */
    public function isOutsideStandardNomenclature(string $code): bool
    {
        $chapter = $this->chapterOf($code);

        return $chapter !== null && in_array($chapter, self::NATIONAL_CHAPTERS, true);
    }

    public function chapterOf(string $code): ?string
    {
        return $this->truncateTo($code, CodeLevel::Chapter);
    }

    public function headingOf(string $code): ?string
    {
        return $this->truncateTo($code, CodeLevel::Heading);
    }

    public function subheadingOf(string $code): ?string
    {
        return $this->truncateTo($code, CodeLevel::Subheading);
    }

    /**
     * Truncate a code to a coarser level of the hierarchy.
     *
     * Returns null when the code is shorter than the requested level, since
     * inventing digits would fabricate a classification. Used by the historic
     * baseline lookup, which refetches a withdrawn code's heading to work out
     * what it used to mean.
     */
    public function truncateTo(string $code, CodeLevel $level): ?string
    {
        if (!$this->isNumeric($code)) {
            return null;
        }

        if (strlen($code) < $level->digitLength()) {
            return null;
        }

        return substr($code, 0, $level->digitLength());
    }

    /**
     * Pad a code out to a longer level with zeroes.
     *
     * The tariff itself does this: subheading 620140 is the same goods as
     * commodity 6201400000 when nothing further subdivides it. Returns null
     * if the code is already longer than the target.
     */
    public function padTo(string $code, CodeLevel $level): ?string
    {
        if (!$this->isNumeric($code)) {
            return null;
        }

        if (strlen($code) > $level->digitLength()) {
            return null;
        }

        return str_pad($code, $level->digitLength(), '0', STR_PAD_RIGHT);
    }

    /**
     * @param list<CodeLevel> $accepted
     */
    private function check(string $code, array $accepted): CodeFormatResult
    {
        if ($code === '') {
            return CodeFormatResult::invalid($code, FormatFailure::Blank);
        }

        if (!$this->isNumeric($code)) {
            return CodeFormatResult::invalid($code, FormatFailure::NotNumeric);
        }

        $length = strlen($code);

        if ($length > CodeLevel::Commodity->digitLength()) {
            return CodeFormatResult::invalid($code, FormatFailure::TooLong);
        }

        if ($length % 2 === 1) {
            return CodeFormatResult::invalid($code, FormatFailure::OddLength);
        }

        // Checked before level acceptance: a four-digit code in a chapter that
        // does not exist has a more specific problem than being too short.
        $chapter = substr($code, 0, 2);

        if (in_array($chapter, self::RESERVED_CHAPTERS, true)) {
            return CodeFormatResult::invalid($code, FormatFailure::UnknownChapter);
        }

        $level = CodeLevel::fromLength($length);

        if ($level === null) {
            return CodeFormatResult::invalid($code, FormatFailure::TooShort);
        }

        if (!in_array($level, $accepted, true)) {
            return CodeFormatResult::invalid($code, FormatFailure::TooShort);
        }

        return CodeFormatResult::valid($code, $level);
    }

    private function isNumeric(string $code): bool
    {
        return $code !== '' && preg_match('/^\d+$/', $code) === 1;
    }
}
