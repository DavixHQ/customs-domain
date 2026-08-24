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
 * zeroes — heading 6201 as 6201000000 — but trying the literal value first
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
        private readonly WeightCriterionParser $weights = new WeightCriterionParser(),
    ) {
    }

    /**
     * @param float|null $netWeightKg Net weight per item, used to narrow
     *        candidates where the tariff branches on garment weight.
     */
    public function resolve(string $code, ?float $netWeightKg = null): Resolution
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

        return $this->narrow($code, $matched, $candidates, $netWeightKg);
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
     * levels above anything declarable — the candidates are "Parkas", "Other"
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
     * set returned. That means the weight contradicts the whole branch — most
     * likely grams entered as kilograms — and an empty list would be both
     * useless and misleading.
     *
     * @param list<Commodity> $candidates
     */
    private function narrow(
        string $code,
        Commodity $matched,
        array $candidates,
        ?float $netWeightKg,
    ): Resolution {
        $total = count($candidates);

        if ($netWeightKg === null || $netWeightKg <= 0.0) {
            return Resolution::ambiguous($code, $candidates, $matched, false, $total);
        }

        /** @var array<int, Commodity|null> $ancestors */
        $ancestors = [];

        $surviving = array_values(array_filter(
            $candidates,
            function (Commodity $candidate) use ($netWeightKg, $matched, &$ancestors): bool {
                $criterion = $this->weightConditionAbove($candidate, $matched, $ancestors);

                return $criterion === null || $criterion->matches($netWeightKg);
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
     * The nearest weight condition on or above a candidate, below the matched
     * line.
     *
     * Nearest wins: a condition closer to the candidate is more specific than
     * one further up. Ancestors are cached across candidates because siblings
     * share them, which turns a quadratic walk into a handful of lookups.
     *
     * @param array<int, Commodity|null> $ancestors
     */
    private function weightConditionAbove(
        Commodity $candidate,
        ?Commodity $stopAt,
        array &$ancestors,
    ): ?WeightCriterion {
        $node = $candidate;
        $depth = 0;

        while ($node !== null && $depth < self::MAX_ANCESTOR_DEPTH) {
            if ($stopAt !== null && $node->sid === $stopAt->sid) {
                return null;
            }

            $criterion = $this->weights->parse($node->description);

            if ($criterion !== null) {
                return $criterion;
            }

            $parentSid = $node->parentSid;

            if ($parentSid === null) {
                return null;
            }

            if (!array_key_exists($parentSid, $ancestors)) {
                $ancestors[$parentSid] = $this->repository->findBySid($parentSid);
            }

            $node = $ancestors[$parentSid];
            ++$depth;
        }

        return null;
    }
}
