<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A trade agreement under which goods may qualify for a preferential rate.
 *
 * More than one can apply to the same goods from the same country. Vietnamese
 * apparel falls under both the UK-Vietnam FTA and CPTPP, and they impose
 * different rules and accept different proof: one wants weaving in country,
 * the other a change of tariff heading, and the certification differs. A
 * merchant who cannot satisfy one may well satisfy the other, so both are
 * carried rather than the first that matched.
 */
class OriginScheme
{
    /**
     * @param list<string> $countryCodes Every country the scheme covers.
     * @param list<OriginRuleSet> $ruleSets
     * @param list<OriginProof> $proofs
     */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly array $countryCodes = [],
        public readonly array $ruleSets = [],
        public readonly array $proofs = [],
        public readonly ?string $introduction = null,
        public readonly bool $unilateral = false,
    ) {
    }

    public function covers(string $countryCode): bool
    {
        return in_array(strtoupper(trim($countryCode)), array_map('strtoupper', $this->countryCodes), true);
    }

    /**
     * Every rule across every set, for a scheme whose rules are worth reading
     * as a flat list.
     *
     * @return list<OriginRule>
     */
    public function allRules(): array
    {
        $rules = [];

        foreach ($this->ruleSets as $set) {
            foreach ($set->rules as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    public function hasRules(): bool
    {
        return $this->allRules() !== [];
    }

    /**
     * Whether this scheme is the one a measure's geographical area named.
     *
     * Matched on the area description rather than a code, because the tariff
     * expresses a preference measure's scope as an area name such as "CPTPP
     * All Members" while the rules of origin service keys on a scheme code
     * such as "cptpp". Nothing in either payload joins the two, so the
     * comparison is on words and is deliberately loose: a scheme wrongly
     * ordered second is a small cost, and a scheme wrongly hidden is a
     * merchant losing a saving they were entitled to.
     */
    public function matchesArea(?string $areaDescription): bool
    {
        if ($areaDescription === null || trim($areaDescription) === '') {
            return false;
        }

        $area = strtolower($areaDescription);
        $code = strtolower($this->code);

        if ($code !== '' && str_contains($area, $code)) {
            return true;
        }

        // Compare on the distinctive words of the title, ignoring the
        // boilerplate every agreement shares.
        $noise = ['uk', 'the', 'and', 'of', 'agreement', 'free', 'trade', 'partnership', 'members', 'all'];
        $words = array_diff(
            preg_split('/[^a-z0-9]+/', strtolower($this->title)) ?: [],
            $noise,
        );

        foreach ($words as $word) {
            if (mb_strlen($word) > 3 && str_contains($area, $word)) {
                return true;
            }
        }

        return false;
    }
}