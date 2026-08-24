<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

use DateTimeImmutable;

/**
 * The measures on one commodity, with the filters rules actually need.
 *
 * A real commodity carries dozens — 74 on a cotton parka — spanning both trade
 * directions, dozens of geographical areas and every kind of duty, control and
 * prohibition. Handing a rule a raw array would mean every rule reimplementing
 * the same direction and origin filtering, and getting it subtly different.
 *
 * Filters return new sets, so they chain:
 *
 *     $measures->forDirection(TradeDirection::Import)
 *              ->forOrigin('CN')
 *              ->prohibitions();
 */
final class MeasureSet
{
    /**
     * @param list<Measure> $measures
     */
    public function __construct(
        private readonly array $measures = [],
    ) {
    }

    /**
     * @return list<Measure>
     */
    public function all(): array
    {
        return $this->measures;
    }

    public function count(): int
    {
        return count($this->measures);
    }

    public function isEmpty(): bool
    {
        return $this->measures === [];
    }

    public function forDirection(TradeDirection $direction): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->appliesTo($direction),
        );
    }

    public function forOrigin(string $countryCode): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->appliesToOrigin($countryCode),
        );
    }

    public function inForceOn(DateTimeImmutable $date): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->isInForceOn($date),
        );
    }

    public function ofType(string $measureTypeId): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->type->id === $measureTypeId,
        );
    }

    public function inSeries(string $series): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->type->isInSeries($series),
        );
    }

    public function prohibitions(): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->prohibits(),
        );
    }

    public function requiringDocumentation(): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->requiresDocumentation(),
        );
    }

    public function quotas(): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->isQuota(),
        );
    }

    public function preferences(): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->isPreference(),
        );
    }

    public function supplementaryUnits(): self
    {
        return $this->filter(
            static fn (Measure $m): bool => $m->type->isSupplementaryUnit(),
        );
    }

    public function first(): ?Measure
    {
        return $this->measures[0] ?? null;
    }

    /**
     * Every document that would satisfy some control in the set, keyed by code
     * so one appearing on several measures is listed once.
     *
     * The key type is deliberately array-key rather than string. Document
     * codes are a mix of lettered and numeric — C052 alongside 9020 — and PHP
     * silently casts a numeric-string array key to an integer. Declaring these
     * as string-keyed was simply false, and static analysis cannot see the
     * difference: a strict comparison against '9020' fails against the integer
     * 9020 that actually comes back. Use documentCodes() when you want strings.
     *
     * @return array<array-key, MeasureCondition>
     */
    public function documentaryOptions(): array
    {
        $found = [];

        foreach ($this->measures as $measure) {
            if (!$measure->requiresDocumentation()) {
                continue;
            }

            foreach ($measure->documentaryOptions() as $condition) {
                $code = (string) $condition->documentCode;
                $found[$code] ??= $condition;
            }
        }

        return $found;
    }

    /**
     * The document codes as strings, in the order first encountered.
     *
     * Exists because array_keys() on documentaryOptions() hands back integers
     * for every numeric code, which breaks strict comparison and anything
     * expecting the codes to look the way the tariff writes them.
     *
     * @return list<string>
     */
    public function documentCodes(): array
    {
        return array_map(
            static fn (MeasureCondition $condition): string => (string) $condition->documentCode,
            array_values($this->documentaryOptions()),
        );
    }

    /**
     * @param callable(Measure): bool $predicate
     */
    private function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->measures, $predicate)));
    }
}