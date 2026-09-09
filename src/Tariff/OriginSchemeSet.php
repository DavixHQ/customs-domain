<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * Every scheme that could apply to some goods from some country.
 */
class OriginSchemeSet
{
    /**
     * @param list<OriginScheme> $schemes
     */
    public function __construct(
        private readonly array $schemes = [],
    ) {
    }

    /**
     * @return list<OriginScheme>
     */
    public function all(): array
    {
        return $this->schemes;
    }

    public function isEmpty(): bool
    {
        return $this->schemes === [];
    }

    public function count(): int
    {
        return count($this->schemes);
    }

    /**
     * Schemes ordered with the one the preference measure named first.
     *
     * All of them are kept. A merchant who cannot meet CPTPP's change of
     * heading may well meet the Vietnam agreement's weaving rule, and hiding
     * the second because the first was named would cost them the saving.
     * Ordering says which is most likely relevant without deciding for them.
     *
     * @return list<OriginScheme>
     */
    public function orderedFor(?string $areaDescription): array
    {
        $matching = [];
        $rest = [];

        foreach ($this->schemes as $scheme) {
            if ($scheme->matchesArea($areaDescription)) {
                $matching[] = $scheme;
            } else {
                $rest[] = $scheme;
            }
        }

        return [...$matching, ...$rest];
    }

    /**
     * Schemes that actually carry rules for this commodity.
     *
     * A scheme can cover a country and say nothing about the goods, and an
     * empty scheme on screen reads as a gap in the data rather than as an
     * agreement that does not reach this product.
     */
    public function withRules(): self
    {
        return new self(array_values(array_filter(
            $this->schemes,
            static fn (OriginScheme $scheme): bool => $scheme->hasRules(),
        )));
    }
}