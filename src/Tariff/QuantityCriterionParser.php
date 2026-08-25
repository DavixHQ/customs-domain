<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * Extracts a quantity condition from a commodity's official description.
 *
 * The tariff states these in prose rather than structured fields, so this is
 * unavoidably a heuristic over HMRC's wording - but a heuristic built from
 * counting what the wording actually is across chapters 4, 22 and 62 rather
 * than from what seemed likely.
 *
 * Deliberately conservative in one direction. A description it cannot read
 * yields null, and a candidate with no criterion is never eliminated, so a
 * missed pattern costs narrowing. A misread pattern costs correctness, which
 * is why the number parser refuses ambiguous forms rather than guessing: the
 * tariff writes "not exceeding 2,5 kg" with a decimal comma, and reading that
 * as a thousands separator gives 25 kg.
 *
 * Order matters throughout. Negative forms are tested before positive ones,
 * because "not exceeding 1 kg" contains "exceeding 1 kg" and the naive order
 * inverts every upper bound in the nomenclature. Ranges are tested before
 * either, because "exceeding 1 % but not exceeding 6 %" would otherwise be
 * read as a lower bound and lose its ceiling.
 */
final class QuantityCriterionParser
{
    /**
     * Space-like characters PCRE will not treat as whitespace outside Unicode
     * mode. The tariff separates a quantity from its unit with U+00A0
     * throughout, so without this every condition reads as none.
     *
     * @var list<string>
     */
    private const UNICODE_SPACES = ["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xE2\x80\x8B"];

    /** Quantities the tariff sometimes spells out. */
    private const WORD_NUMBERS = [
        'one' => 1.0, 'two' => 2.0, 'three' => 3.0, 'four' => 4.0, 'five' => 5.0,
        'six' => 6.0, 'seven' => 7.0, 'eight' => 8.0, 'nine' => 9.0, 'ten' => 10.0,
        'eleven' => 11.0, 'twelve' => 12.0,
    ];

    /**
     * Words naming what is being measured, longest first so "milkfat" is not
     * matched as "fat" and "dry matter" wins over a bare match.
     *
     * @var array<string, string>
     */
    private const SUBJECTS = [
        'alcoholic strength' => MeasuredProperty::ALCOHOL_STRENGTH,
        'alcoholic content' => MeasuredProperty::ALCOHOL_STRENGTH,
        'dry matter' => MeasuredProperty::DRY_MATTER,
        'milkfat' => MeasuredProperty::FAT_CONTENT,
        'milk fat' => MeasuredProperty::FAT_CONTENT,
        'butterfat' => MeasuredProperty::FAT_CONTENT,
        'fat content' => MeasuredProperty::FAT_CONTENT,
        'protein content' => MeasuredProperty::PROTEIN_CONTENT,
        'milk protein' => MeasuredProperty::PROTEIN_CONTENT,
        'sucrose' => MeasuredProperty::SUGAR_CONTENT,
        'sugar content' => MeasuredProperty::SUGAR_CONTENT,
        'starch' => MeasuredProperty::STARCH_CONTENT,
        'glucose' => MeasuredProperty::STARCH_CONTENT,
    ];

    /** Units mapped to a dimension and a multiplier onto its base unit. */
    private const UNITS = [
        'kg' => [Dimension::Mass, 1.0],
        'kilogram' => [Dimension::Mass, 1.0],
        'kilograms' => [Dimension::Mass, 1.0],
        'g' => [Dimension::Mass, 0.001],
        'gram' => [Dimension::Mass, 0.001],
        'grams' => [Dimension::Mass, 0.001],
        'l' => [Dimension::Volume, 1.0],
        'litre' => [Dimension::Volume, 1.0],
        'litres' => [Dimension::Volume, 1.0],
        'liter' => [Dimension::Volume, 1.0],
        'liters' => [Dimension::Volume, 1.0],
        'cl' => [Dimension::Volume, 0.01],
        'ml' => [Dimension::Volume, 0.001],
        '%' => [Dimension::Percentage, 1.0],
        '% vol' => [Dimension::Percentage, 1.0],
    ];

    private const NUMBER = '([\d]+(?:[.,]\d+)?|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)';
    private const UNIT = '(%\s*vol|%|kg|kilograms?|g|grams?|litres?|liters?|l|cl|ml)';

    public function parse(string $description): ?QuantityCriterion
    {
        $text = $this->normalise($description);

        $criterion = $this->parseRange($text)
            ?? $this->parseUpperBound($text)
            ?? $this->parseLowerBound($text);

        if ($criterion === null) {
            return null;
        }

        $property = $this->subjectOf($text, $criterion->dimension);

        return $property === null ? $criterion : $criterion->withProperty($property);
    }

    public function describesQuantity(string $description): bool
    {
        return $this->parse($description) !== null;
    }

    /**
     * The property a description names, whether or not it states a threshold.
     *
     * Exists for the 73 lines in chapter 4 reading simply "Not exceeding 3 %".
     * Their parent says "Of a fat content, by weight:" and carries no number
     * at all, so the subject has to be read from a line the condition is not
     * on. Without this those conditions are parsed and then never usable.
     */
    public function subjectIn(string $description): ?string
    {
        $lower = strtolower($this->normalise($description));

        foreach (self::SUBJECTS as $phrase => $property) {
            if (str_contains($lower, $phrase)) {
                return $property;
            }
        }

        return null;
    }

    /**
     * A band stated in one line: "exceeding 1 % but not exceeding 6 %".
     *
     * Read first, because either half in isolation is a valid-looking bound
     * and taking only the first loses the other end of the range.
     */
    private function parseRange(string $text): ?QuantityCriterion
    {
        $pattern = '/(?:exceeding|more than|over)\s+' . self::NUMBER . '\s*' . self::UNIT
            . '?\s*(?:but\s+)?(?:not exceeding|less than|up to)\s+' . self::NUMBER . '\s*' . self::UNIT . '/i';

        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        // The closing unit, not the opening one. In "exceeding 1 but not
        // exceeding 6 %" only the second is written, and the pattern makes the
        // first optional for exactly that reason - so the second is always the
        // one present and always the authoritative one.
        $unit = $this->unitOf($m[4]);
        $minimum = $this->numberOf($m[1]);
        $maximum = $this->numberOf($m[3]);

        if ($unit === null || $minimum === null || $maximum === null) {
            return null;
        }

        [$dimension, $factor] = $unit;

        return QuantityCriterion::between($minimum * $factor, $maximum * $factor, $dimension);
    }

    private function parseUpperBound(string $text): ?QuantityCriterion
    {
        $patterns = [
            ['/not exceeding\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', true],
            ['/(?:not more than|no more than)\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', true],
            ['/less than\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', false],
            ['/under\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', false],
            ['/' . self::NUMBER . '\s*' . self::UNIT . '\s+or less/i', true],
        ];

        foreach ($patterns as [$pattern, $inclusive]) {
            $parsed = $this->applyBound($pattern, $text);

            if ($parsed !== null) {
                [$value, $dimension] = $parsed;

                return QuantityCriterion::notExceeding($value, $dimension, null, $inclusive);
            }
        }

        return null;
    }

    private function parseLowerBound(string $text): ?QuantityCriterion
    {
        $patterns = [
            ['/exceeding\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', false],
            ['/more than\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', false],
            ['/over\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', false],
            ['/(?:not less than|at least)\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', true],
            ['/(?:equal (?:to )?or higher than)\s+' . self::NUMBER . '\s*' . self::UNIT . '/i', true],
            ['/' . self::NUMBER . '\s*' . self::UNIT . '\s+or (?:more|higher)/i', true],
        ];

        foreach ($patterns as [$pattern, $inclusive]) {
            $parsed = $this->applyBound($pattern, $text);

            if ($parsed !== null) {
                [$value, $dimension] = $parsed;

                return QuantityCriterion::exceeding($value, $dimension, null, $inclusive);
            }
        }

        return null;
    }

    /**
     * @return array{float, Dimension}|null
     */
    private function applyBound(string $pattern, string $text): ?array
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $value = $this->numberOf($m[1]);
        $unit = $this->unitOf($m[2]);

        if ($value === null || $unit === null || $value < 0.0) {
            return null;
        }

        [$dimension, $factor] = $unit;

        $converted = $value * $factor;

        // A zero threshold is either meaningless or a parse gone wrong, and a
        // criterion of "exceeding 0" eliminates nothing while implying it did.
        return $converted <= 0.0 ? null : [$converted, $dimension];
    }

    /**
     * What the condition is measuring, when the line says so.
     *
     * Percentages are the case that needs this: 342 lines measure alcoholic
     * strength and 35 measure fat content, and a product's fat content cannot
     * be tested against an alcohol threshold. Mass and volume conditions
     * default to the obvious property, since a line branching on kilograms in
     * this nomenclature is branching on net weight.
     */
    private function subjectOf(string $text, Dimension $dimension): ?string
    {
        $lower = strtolower($text);

        foreach (self::SUBJECTS as $phrase => $property) {
            if (str_contains($lower, $phrase)
                && MeasuredProperty::dimensionOf($property) === $dimension
            ) {
                return $property;
            }
        }

        return match ($dimension) {
            Dimension::Mass => MeasuredProperty::NET_WEIGHT,
            Dimension::Volume => MeasuredProperty::VOLUME,
            // A bare percentage names no subject. Chapter 4 has 76 such lines,
            // whose parent supplies the missing word.
            default => null,
        };
    }

    /**
     * @return array{Dimension, float}|null
     */
    private function unitOf(string $unit): ?array
    {
        $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $unit)));

        return self::UNITS[$key] ?? null;
    }

    /**
     * Read a number the way the tariff writes them.
     *
     * A comma followed by one or two digits is a decimal separator - "2,5 kg"
     * is two and a half kilograms, and reading it as a thousands separator
     * gives 25. A comma followed by groups of three is a thousands separator.
     * Anything else is ambiguous enough that refusing beats guessing.
     */
    private function numberOf(string $raw): ?float
    {
        $value = strtolower(trim($raw));

        if ($value === '') {
            return null;
        }

        if (isset(self::WORD_NUMBERS[$value])) {
            return self::WORD_NUMBERS[$value];
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $value) === 1) {
            return (float) $value;
        }

        if (preg_match('/^\d+,\d{1,2}$/', $value) === 1) {
            return (float) str_replace(',', '.', $value);
        }

        if (preg_match('/^\d{1,3}(?:,\d{3})+$/', $value) === 1) {
            return (float) str_replace(',', '', $value);
        }

        return null;
    }

    private function normalise(string $description): string
    {
        $replaced = str_replace(self::UNICODE_SPACES, ' ', $description);

        return (string) preg_replace('/\s+/', ' ', $replaced);
    }
}