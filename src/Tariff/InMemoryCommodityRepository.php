<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * An in-memory nomenclature mirror.
 *
 * Intended for tests and for tooling that holds a small slice of the tree
 * without a database. Every rule and the resolver can be exercised against
 * this with no Magento, no network and no fixtures beyond a handful of
 * Commodity objects.
 *
 * Not intended for production use over a full nomenclature — that is 20,000
 * or so lines and belongs in indexed storage.
 */
final class InMemoryCommodityRepository implements CommodityRepositoryInterface
{
    /** @var array<int, Commodity> */
    private array $bySid = [];

    /** @var array<string, list<Commodity>> */
    private array $byCode = [];

    /** @var array<int, list<Commodity>> */
    private array $byParent = [];

    /**
     * @param iterable<Commodity> $commodities
     */
    public function __construct(
        iterable $commodities = [],
        private readonly Jurisdiction $jurisdiction = Jurisdiction::Uk,
    ) {
        foreach ($commodities as $commodity) {
            $this->add($commodity);
        }
    }

    public function jurisdiction(): Jurisdiction
    {
        return $this->jurisdiction;
    }

    public function add(Commodity $commodity): void
    {
        $this->bySid[$commodity->sid] = $commodity;

        $this->byCode[$commodity->code] ??= [];
        $this->byCode[$commodity->code][] = $commodity;

        if ($commodity->parentSid !== null) {
            $this->byParent[$commodity->parentSid] ??= [];
            $this->byParent[$commodity->parentSid][] = $commodity;
        }
    }

    public function findBySid(int $sid): ?Commodity
    {
        return $this->bySid[$sid] ?? null;
    }

    public function findByCode(string $code): array
    {
        return $this->byCode[$code] ?? [];
    }

    public function findDeclarable(string $code): ?Commodity
    {
        foreach ($this->findByCode($code) as $commodity) {
            if ($commodity->isDeclarable()) {
                return $commodity;
            }
        }

        return null;
    }

    public function childrenOf(int $parentSid): array
    {
        return $this->byParent[$parentSid] ?? [];
    }

    public function declarableDescendantsOf(int $sid): array
    {
        /** @var array<int, Commodity> $found */
        $found = [];
        /** @var array<int, true> $visited */
        $visited = [];

        $this->collectDeclarable($sid, $found, $visited);

        return array_values($found);
    }

    /**
     * Depth-first descent with a visited set.
     *
     * The visited set is not paranoia about tree shape — it is protection
     * against a corrupt mirror. A parent reference that loops back on itself
     * turns this walk into infinite recursion that exhausts memory and takes
     * the whole scan with it, and a partial or interrupted sync is exactly the
     * situation where such a reference can exist.
     *
     * @param array<int, Commodity> $found
     * @param array<int, true> $visited
     */
    private function collectDeclarable(int $sid, array &$found, array &$visited): void
    {
        if (isset($visited[$sid])) {
            return;
        }

        $visited[$sid] = true;

        foreach ($this->childrenOf($sid) as $child) {
            if ($child->sid === $sid) {
                continue;
            }

            if ($child->isDeclarable()) {
                $found[$child->sid] = $child;
            }

            $this->collectDeclarable($child->sid, $found, $visited);
        }
    }

    public function hasChapter(string $chapter): bool
    {
        foreach ($this->bySid as $commodity) {
            if (str_starts_with($commodity->code, $chapter)) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->bySid);
    }
}
