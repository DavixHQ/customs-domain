<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * Extracts a weight condition from a commodity's official description.
 *
 * The tariff expresses these in prose rather than structured fields, so this
 * is unavoidably a heuristic over HMRC's wording. It is deliberately
 * conservative: a description it cannot parse yields null, and a candidate
 * with no parsed criterion is never eliminated. Wrongly discarding the correct
 * classification is far worse than leaving a merchant with one more option to
 * choose between.
 *
 * The negative form is tested first, because "not exceeding 1 kg" contains
 * "exceeding 1 kg" and would otherwise match the wrong pattern and invert the
 * condition.
 *
 * Descriptions are whitespace-normalised before matching. The tariff separates
 * a quantity from its unit with a non-breaking space — the live bytes for
 * "1 kg" are 31 c2 a0 6b 67 — and PCRE's \s does not match one outside Unicode
 * mode. Without normalisation every weight condition in the nomenclature reads
 * as no condition at all, and weight narrowing silently does nothing.
 */
final class WeightCriterionParser
{
    /**
     * Space-like characters that PCRE's \s does not match outside Unicode
     * mode: non-breaking space, narrow non-breaking space, thin space and
     * zero-width space. The first of these appears throughout the tariff.
     *
     * @var list<string>
     */
    private const UNICODE_SPACES = ["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xE2\x80\x8B"];

    /**
     * Phrasings meaning "no heavier than", checked first.
     */
    private const MAXIMUM_PATTERNS = [
        '/not\s+exceeding\s+([\d.,]+)\s*(kg|kilograms?|g|grams?)\b/i',
        '/(?:of\s+a\s+weight\s+)?less\s+than\s+([\d.,]+)\s*(kg|kilograms?|g|grams?)\b/i',
        '/(?:weighing\s+)?not\s+more\s+than\s+([\d.,]+)\s*(kg|kilograms?|g|grams?)\b/i',
        '/(?:of\s+a\s+weight\s+of\s+)?under\s+([\d.,]+)\s*(kg|kilograms?|g|grams?)\b/i',
    ];

    /**
     * Phrasings meaning "heavier than".
     */
    private const MINIMUM_PATTERNS = [
        '/exceeding\s+([\d.,]+)\s*(kg|kilograms?|g|grams?)\b/i',
        '/(?:weighing\s+)?more\s+than\s+([\d.,]+)\s*(kg|kilograms?|g|grams?)\b/i',
        '/(?:of\s+a\s+weight\s+of\s+)?over\s+([\d.,]+)\s*(kg|kilograms?|g|grams?)\b/i',
        '/([\d.,]+)\s*(kg|kilograms?|g|grams?)\s+or\s+more\b/i',
    ];

    public function parse(string $description): ?WeightCriterion
    {
        $description = $this->normaliseSpaces($description);

        foreach (self::MAXIMUM_PATTERNS as $pattern) {
            $kilograms = $this->match($pattern, $description);

            if ($kilograms !== null) {
                return WeightCriterion::notExceeding($kilograms);
            }
        }

        foreach (self::MINIMUM_PATTERNS as $pattern) {
            $kilograms = $this->match($pattern, $description);

            if ($kilograms !== null) {
                return WeightCriterion::exceeding($kilograms);
            }
        }

        return null;
    }

    public function describesWeight(string $description): bool
    {
        return $this->parse($description) !== null;
    }

    /**
     * Replace space-like characters PCRE will not treat as whitespace, and
     * collapse runs so a pattern expecting one space matches several.
     */
    private function normaliseSpaces(string $description): string
    {
        $replaced = str_replace(self::UNICODE_SPACES, ' ', $description);

        return preg_replace('/\s+/', ' ', $replaced) ?? $replaced;
    }

    private function match(string $pattern, string $description): ?float
    {
        if (preg_match($pattern, $description, $matches) !== 1) {
            return null;
        }

        $value = (float) str_replace(',', '', $matches[1]);
        $unit = strtolower($matches[2]);

        if ($value <= 0.0) {
            return null;
        }

        return str_starts_with($unit, 'g') ? $value / 1000 : $value;
    }
}
