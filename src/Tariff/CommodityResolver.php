<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

use Davix\Customs\Validation\CodeFormat;
use Davix\Customs\Validation\CodeLevel;

/**
 * Turns a merchant's commodity code into a declarable tariff line, or into an
 * honest account of why it could not.
 *
 * Reads the local mirror only. No network call happens here, which is what
 * lets several rules evaluate with zero HTTP per product and stops a provider
 * outage from flagging an entire catalogue as broken. Distinguishing a
 * withdrawn code from a mistyped one does need a historic lookup against the
 * provider, so this class reports NotInMirror and leaves that call to the host.
 *
 * Codes shorter than ten digits are looked up as supplied first and only then
 * zero-padded. The nomenclature stores every line at full length with trailing
 * zeroes heading 6201 as 6201000000, but trying the literal value first
 * costs one lookup and makes the resolver correct under either convention.
 */
final class CommodityResolver
{
    /**
     * Guard against a malformed mirror producing an unterminated parent chain.
     * The nomenclature never runs deeper than about ten indents.
     */
    private const MAX_ANCESTOR_DEPTH = 12;

    public function __construct(
        private readonly CommodityRepositoryInterface $repository,
        private readonly CodeFormat $format = new CodeFormat(),
        private readonly QuantityCriterionParser $quantities = new QuantityCriterionParser(),
    ) {
    }

    /**
     * @param array<string, float> $measuredProperties What is known about the
     *        goods, keyed by property name. Net weight narrows apparel; alcoholic
     *        strength narrows chapter 22, where 348 lines branch on it; fat
     *        content narrows chapter 4. See MeasuredProperty for the keys.
     */
    public function resolve(string $code, array $measuredProperties = []): Resolution
    {
        $chapter = $this->format->chapterOf($code);

        if ($chapter === null) {
            return Resolution::notInMirror($code);
        }

        if ($this->format->isOutsideStandardNomenclature($code)) {
            return Resolution::outsideStandardNomenclature($code);
        }

        if (!$this->repository->hasChapter($chapter)) {
            return Resolution::chapterNotMirrored($code);
        }

        $lines = $this->matchingLines($code);

        if ($lines === []) {
            return Resolution::notInMirror($code);
        }

        foreach ($lines as $line) {
            if ($line->isDeclarable()) {
                return Resolution::resolved($code, $line);
            }
        }

        $matched = $lines[0];
        $candidates = $this->declarableBeneath($lines);

        if ($candidates === []) {
            return Resolution::deadEnd($code, $matched);
        }

        if (count($candidates) === 1) {
            return Resolution::resolved($code, $candidates[0], $matched);
        }

        return $this->narrow($code, $matched, $candidates, $measuredProperties);
    }

    /**
     * Lines carrying this code, trying the literal value before the padded one.
     *
     * @return list<Commodity>
     */
    private function matchingLines(string $code): array
    {
        $lines = $this->repository->findByCode($code);

        if ($lines !== []) {
            return $lines;
        }

        if (strlen($code) >= CodeLevel::Commodity->digitLength()) {
            return [];
        }

        $padded = $this->format->padTo($code, CodeLevel::Commodity);

        if ($padded === null || $padded === $code) {
            return [];
        }

        return $this->repository->findByCode($padded);
    }

    /**
     * Every declarable line beneath the matched lines, deduplicated by SID.
     *
     * @param list<Commodity> $lines
     * @return list<Commodity>
     */
    private function declarableBeneath(array $lines): array
    {
        /** @var array<int, Commodity> $bySid */
        $bySid = [];

        foreach ($lines as $line) {
            foreach ($this->repository->declarableDescendantsOf($line->sid) as $descendant) {
                $bySid[$descendant->sid] = $descendant;
            }
        }

        return array_values($bySid);
    }

    /**
     * Apply net weight to a candidate set.
     *
     * The weight condition is almost never on the candidate itself. In the real
     * nomenclature the split at 1 kg per garment sits on a grouping line two
     * levels above anything declarable, the candidates are "Parkas", "Other"
     * and "Hand-printed by the batik method", none of which mention weight. So
     * each candidate is matched against the nearest weight condition found by
     * walking up its ancestors, stopping at the line the merchant's own code
     * matched. Ancestors at or above that point are shared by every candidate
     * and cannot discriminate between them.
     *
     * Only candidates whose condition the product actually violates are
     * removed. A candidate with no condition anywhere above it is always kept,
     * because discarding the correct classification is far worse than
     * presenting one extra option.
     *
     * If weight eliminates everything, the narrowing is abandoned and the full
     * set returned. That means the weight contradicts the whole branch, most
     * likely grams entered as kilograms and an empty list would be both
     * useless and misleading.
     *
     * @param list<Commodity> $candidates
     * @param array<string, float> $measuredProperties
     */
    private function narrow(
        string $code,
        Commodity $matched,
        array $candidates,
        array $measuredProperties,
    ): Resolution {
        $total = count($candidates);
        $measuredProperties = $this->usableProperties($measuredProperties);

        if ($measuredProperties === []) {
            return Resolution::ambiguous($code, $candidates, $matched, false, $total);
        }

        /** @var array<int, Commodity|null> $ancestors */
        $ancestors = [];

        $surviving = array_values(array_filter(
            $candidates,
            function (Commodity $candidate) use ($measuredProperties, $matched, &$ancestors): bool {
                $criterion = $this->conditionAbove($candidate, $matched, $ancestors);

                return $criterion === null || $criterion->matchesProperties($measuredProperties);
            },
        ));

        if ($surviving === [] || count($surviving) === $total) {
            return Resolution::ambiguous($code, $candidates, $matched, false, $total);
        }

        if (count($surviving) === 1) {
            return Resolution::resolved($code, $surviving[0], $matched, true, $total);
        }

        return Resolution::ambiguous($code, $surviving, $matched, true, $total);
    }

    /**
     * Discard measurements that cannot be real.
     *
     * A negative value is never meaningful. Zero is more delicate: a garment
     * weighing nothing and a container holding nothing are both missing data
     * dressed up as a measurement, but a drink of zero per cent alcohol is an
     * ordinary product and chapter 22 has lines for it. Rejecting zero across
     * the board would push every alcohol-free beverage into the wrong branch.
     *
     * @param array<string, float> $properties
     * @return array<string, float>
     */
    private function usableProperties(array $properties): array
    {
        $usable = [];

        foreach ($properties as $name => $value) {
            if ($value < 0.0) {
                continue;
            }

            $dimension = MeasuredProperty::dimensionOf($name);
            $zeroIsMeaningful = $dimension === Dimension::Percentage;

            if ($value === 0.0 && !$zeroIsMeaningful) {
                continue;
            }

            $usable[$name] = $value;
        }

        return $usable;
    }

    /**
     * The nearest quantity condition on or above a candidate, below the
     * matched line.
     *
     * Nearest wins: a condition closer to the candidate is more specific than
     * one further up. Ancestors are cached across candidates because siblings
     * share them, which turns a quadratic walk into a handful of lookups.
     *
     * A condition naming no subject has its subject filled in from further up
     * the same chain. Chapter 4 has 73 lines reading simply "Not exceeding
     * 3 %", whose parent says "Of a fat content, by weight" and carries no
     * number - so the threshold and the thing it measures live on different
     * lines, and neither is usable without the other.
     *
     * @param array<int, Commodity|null> $ancestors
     */
    private function conditionAbove(
        Commodity $candidate,
        ?Commodity $stopAt,
        array &$ancestors,
    ): ?QuantityCriterion {
        $node = $candidate;
        $depth = 0;
        $criterion = null;

        while ($node !== null && $depth < self::MAX_ANCESTOR_DEPTH) {
            if ($stopAt !== null && $node->sid === $stopAt->sid) {
                return null;
            }

            if ($criterion === null) {
                $criterion = $this->quantities->parse($node->description);

                if ($criterion !== null && $criterion->hasKnownProperty()) {
                    return $criterion;
                }
            } else {
                // Carrying a subjectless condition upward, looking for the
                // line that names what it measures.
                $subject = $this->quantities->subjectIn($node->description);

                if ($subject !== null) {
                    return $criterion->withProperty($subject);
                }
            }

            $parentSid = $node->parentSid;

            if ($parentSid === null) {
                break;
            }

            if (!array_key_exists($parentSid, $ancestors)) {
                $ancestors[$parentSid] = $this->repository->findBySid($parentSid);
            }

            $node = $ancestors[$parentSid];
            ++$depth;
        }

        // A condition whose subject was never found stays unusable, and an
        // unusable condition eliminates nothing.
        return $criterion !== null && $criterion->hasKnownProperty() ? $criterion : null;
    }
}
