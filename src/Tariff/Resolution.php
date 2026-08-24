<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * The result of resolving one commodity code against the mirror.
 */
final class Resolution
{
    /**
     * @param string $code The normalised code that was looked up.
     * @param Commodity|null $commodity The single declarable line, when resolved.
     * @param Commodity|null $matchedLine The line the code itself matched, even
     *        when that line is a grouping rather than something declarable.
     *        Carries the official description, which is what makes a message
     *        like "620140 means 'Of man-made fibres'" possible.
     * @param list<Commodity> $candidates Declarable lines to choose between.
     * @param bool $narrowedByWeight Whether net weight removed any candidate.
     * @param int $candidatesBeforeNarrowing How many there were before weight
     *        was applied, so the UI can say what the merchant's data bought them.
     */
    private function __construct(
        public readonly ResolutionOutcome $outcome,
        public readonly string $code,
        public readonly ?Commodity $commodity = null,
        public readonly ?Commodity $matchedLine = null,
        public readonly array $candidates = [],
        public readonly bool $narrowedByWeight = false,
        public readonly int $candidatesBeforeNarrowing = 0,
    ) {
    }

    public static function resolved(
        string $code,
        Commodity $commodity,
        ?Commodity $matchedLine = null,
        bool $narrowedByWeight = false,
        int $candidatesBeforeNarrowing = 1,
    ): self {
        return new self(
            ResolutionOutcome::Resolved,
            $code,
            $commodity,
            $matchedLine ?? $commodity,
            [],
            $narrowedByWeight,
            $candidatesBeforeNarrowing,
        );
    }

    /**
     * @param list<Commodity> $candidates
     */
    public static function ambiguous(
        string $code,
        array $candidates,
        ?Commodity $matchedLine = null,
        bool $narrowedByWeight = false,
        int $candidatesBeforeNarrowing = 0,
    ): self {
        return new self(
            ResolutionOutcome::Ambiguous,
            $code,
            null,
            $matchedLine,
            $candidates,
            $narrowedByWeight,
            $candidatesBeforeNarrowing !== 0 ? $candidatesBeforeNarrowing : count($candidates),
        );
    }

    public static function notInMirror(string $code): self
    {
        return new self(ResolutionOutcome::NotInMirror, $code);
    }

    public static function chapterNotMirrored(string $code): self
    {
        return new self(ResolutionOutcome::ChapterNotMirrored, $code);
    }

    public static function outsideStandardNomenclature(string $code): self
    {
        return new self(ResolutionOutcome::OutsideStandardNomenclature, $code);
    }

    public static function deadEnd(string $code, Commodity $matchedLine): self
    {
        return new self(ResolutionOutcome::DeadEnd, $code, null, $matchedLine);
    }

    public function isResolved(): bool
    {
        return $this->outcome === ResolutionOutcome::Resolved;
    }

    public function isAmbiguous(): bool
    {
        return $this->outcome === ResolutionOutcome::Ambiguous;
    }

    public function isConclusive(): bool
    {
        return $this->outcome->isConclusive();
    }

    public function candidateCount(): int
    {
        return count($this->candidates);
    }

    /**
     * How many candidates net weight eliminated.
     */
    public function candidatesEliminated(): int
    {
        if (!$this->narrowedByWeight) {
            return 0;
        }

        return max(0, $this->candidatesBeforeNarrowing - max(1, $this->candidateCount()));
    }
}
