<?php

declare(strict_types=1);

namespace Davix\Customs\Validation;

/**
 * Turns whatever a merchant actually typed or imported into a canonical
 * digits-only commodity code.
 *
 * This runs in front of every rule. Merchant commodity data almost always
 * originates in a supplier spreadsheet, and spreadsheets mangle codes in a
 * small number of predictable ways: dotted grouping, space grouping, leading
 * zeroes stripped by numeric formatting, a trailing ".0" from float coercion,
 * and - worst of all - scientific notation on ten-digit values.
 *
 * Without this step, a format rule fires on most of a real catalogue on the
 * first scan and the merchant concludes the module is broken.
 *
 * The normaliser repairs what can be repaired unambiguously and refuses to
 * guess at anything else. Scientific notation and multi-value cells are
 * reported rather than salvaged, because a plausible-looking wrong commodity
 * code is more damaging than a visible error.
 *
 * It does not judge length. A clean seven-digit result is a successful
 * normalisation and a failed validation; see CodeFormat.
 */
final class CodeNormaliser
{
    /**
     * Byte sequences for whitespace that trim() does not remove: UTF-8
     * non-breaking space, zero-width space, and byte-order mark. All three
     * arrive routinely from spreadsheet and web-page copy-paste.
     */
    private const INVISIBLE_WHITESPACE = ["\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"];

    /** Characters that group digits within a single code. */
    private const SEPARATORS = ['.', ' ', '-', '_', "\t", ',', ';', '|', '/'];

    /** Characters a spreadsheet adds around a value it is treating as text. */
    private const QUOTING = ['"', "'", '`', '‘', '’', '“', '”'];

    /**
     * Characters that, when they separate two substantial digit groups,
     * indicate the cell holds more than one code.
     */
    private const MULTI_VALUE_DELIMITERS = '/[,;|\/&\r\n]+/';

    public function normalise(?string $raw): NormalisationResult
    {
        $original = $raw ?? '';

        if ($raw === null) {
            return NormalisationResult::failure($original, NormalisationFailure::Blank);
        }

        /** @var list<Transformation> $transformations */
        $transformations = [];

        $value = str_replace(self::INVISIBLE_WHITESPACE, ' ', $raw);
        $value = trim($value);

        if ($value !== $raw) {
            $transformations[] = Transformation::TrimmedWhitespace;
        }

        if ($value === '') {
            return NormalisationResult::failure($original, NormalisationFailure::Blank);
        }

        $unquoted = str_replace(self::QUOTING, '', $value);

        if ($unquoted !== $value) {
            $transformations[] = Transformation::RemovedQuoting;
            $value = trim($unquoted);
        }

        if ($value === '') {
            return NormalisationResult::failure($original, NormalisationFailure::Blank);
        }

        if ($this->isScientificNotation($value)) {
            return NormalisationResult::failure($original, NormalisationFailure::ScientificNotation);
        }

        if ($this->holdsMultipleCodes($value)) {
            return NormalisationResult::failure($original, NormalisationFailure::MultipleValues);
        }

        $withoutFloatTail = $this->dropExcelDecimalTail($value);

        if ($withoutFloatTail !== $value) {
            $transformations[] = Transformation::DroppedDecimalZeroes;
            $value = $withoutFloatTail;
        }

        $digits = str_replace(self::SEPARATORS, '', $value);

        if ($digits !== $value) {
            $transformations[] = Transformation::RemovedSeparators;
        }

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return NormalisationResult::failure($original, NormalisationFailure::NonNumeric);
        }

        $padded = $this->restoreStrippedLeadingZero($digits);

        if ($padded !== $digits) {
            $transformations[] = Transformation::PaddedLeadingZero;
            $digits = $padded;
        }

        return NormalisationResult::success($original, $digits, $transformations);
    }

    /**
     * Group a normalised code for display, UK Trade Tariff style.
     *
     * 6201401019 becomes 6201.40.10.19. Codes of a length that cannot be
     * grouped meaningfully are returned unchanged rather than mangled.
     */
    public function formatForDisplay(string $code): string
    {
        if (preg_match('/^\d+$/', $code) !== 1) {
            return $code;
        }

        return match (strlen($code)) {
            6, 8, 10 => rtrim(chunk_split(substr($code, 0, 4), 4, '.')
                . chunk_split(substr($code, 4), 2, '.'), '.'),
            default => $code,
        };
    }

    /**
     * Excel displays long numeric values as 6.20140102E+09 and exports them
     * that way too. The trailing digits are gone; there is nothing to recover.
     */
    private function isScientificNotation(string $value): bool
    {
        return preg_match('/^[+-]?\d+(?:[.,]\d+)?[eE][+-]?\d+$/', $value) === 1;
    }

    /**
     * A cell holding "6201401019, 6202401019" is two codes, not one.
     *
     * Delimiters are only treated as multi-value when at least two of the
     * resulting parts are substantial enough to be codes in their own right.
     * That keeps "6201/40/10/19" - a legitimate if unusual grouping style -
     * from being rejected.
     */
    private function holdsMultipleCodes(string $value): bool
    {
        $parts = preg_split(self::MULTI_VALUE_DELIMITERS, $value);

        if ($parts === false) {
            return false;
        }

        $substantial = 0;

        foreach ($parts as $part) {
            $digitCount = strlen(preg_replace('/\D/', '', $part) ?? '');

            if ($digitCount >= 4) {
                ++$substantial;
            }
        }

        return $substantial >= 2;
    }

    /**
     * Strip the ".0" that float coercion adds to a whole number.
     *
     * Deliberately narrow. It fires only when the entire value is a run of six
     * or more digits followed by nothing but zeroes after a single decimal
     * point, so a genuine dotted code ending in a zero pair - 0101.21.00.00 -
     * is left alone.
     */
    private function dropExcelDecimalTail(string $value): string
    {
        if (preg_match('/^(\d{6,})\.0+$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    /**
     * Commodity codes have an even number of digits. An odd-length code is
     * almost always a chapter 01–09 value whose leading zero was eaten by
     * numeric cell formatting: 0101210000 exports as 101210000.
     *
     * Only applied below eleven digits, so genuinely malformed long values are
     * passed through for the format rule to reject rather than being padded
     * into false plausibility.
     */
    private function restoreStrippedLeadingZero(string $digits): string
    {
        $length = strlen($digits);

        if ($length % 2 === 1 && $length <= 9) {
            return '0' . $digits;
        }

        return $digits;
    }
}
