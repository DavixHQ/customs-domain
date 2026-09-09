<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

use Davix\Customs\Tariff\OriginProof;
use Davix\Customs\Tariff\OriginRule;
use Davix\Customs\Tariff\OriginRuleSet;
use Davix\Customs\Tariff\OriginScheme;
use Davix\Customs\Tariff\OriginSchemeSet;

/**
 * Builds origin schemes from the rules of origin response.
 *
 * Two things in the live payload the published examples do not show.
 *
 * Rules arrive as `rules_of_origin_v2_rule`, not the `rules_of_origin_rule`
 * the documentation illustrates. Reading only the documented type finds
 * nothing and reports every scheme as having no rules, which looks like an
 * agreement that does not cover the goods rather than like a mapper looking in
 * the wrong place.
 *
 * Rules hang off rule sets rather than off the scheme. The scheme's own
 * `rules` relationship came back empty for every scheme tested, while the
 * `rule_sets` relationship held everything. Both are read, because a payload
 * that populated the other way round would otherwise silently lose its rules.
 */
class RulesOfOriginMapper
{
    private const RULE_TYPES = ['rules_of_origin_v2_rule', 'rules_of_origin_rule'];

    public function map(JsonApiDocument $document): OriginSchemeSet
    {
        $schemes = [];

        foreach ($document->allOfType('rules_of_origin_scheme') as $resource) {
            $schemes[] = $this->mapScheme($document, $resource);
        }

        return new OriginSchemeSet($schemes);
    }

    /**
     * The service returns schemes in `data`, not `included`.
     *
     * @param array<string, mixed> $payload
     */
    public function mapArray(array $payload): OriginSchemeSet
    {
        $document = new JsonApiDocument($payload);
        $data = $payload['data'] ?? [];

        if (!is_array($data)) {
            return new OriginSchemeSet();
        }

        $schemes = [];

        foreach ($data as $resource) {
            if (is_array($resource)) {
                /** @var array<string, mixed> $resource */
                $schemes[] = $this->mapScheme($document, $resource);
            }
        }

        return new OriginSchemeSet($schemes);
    }

    public function mapJson(string $json): OriginSchemeSet
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->mapArray($decoded);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function mapScheme(JsonApiDocument $document, array $resource): OriginScheme
    {
        $attributes = JsonApiDocument::attributesOf($resource);

        $countries = $attributes['countries'] ?? [];

        return new OriginScheme(
            code: $this->stringOf($attributes['scheme_code'] ?? null)
                ?? $this->stringOf($resource['id'] ?? null)
                ?? '',
            title: $this->stringOf($attributes['title'] ?? null) ?? '',
            countryCodes: $this->stringList($countries),
            ruleSets: $this->mapRuleSets($document, $resource),
            proofs: $this->mapProofs($document, $resource),
            introduction: $this->stringOf($attributes['fta_intro'] ?? null),
            unilateral: (bool) ($attributes['unilateral'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $resource
     * @return list<OriginRuleSet>
     */
    private function mapRuleSets(JsonApiDocument $document, array $resource): array
    {
        $sets = [];

        foreach ($document->resolveMany($resource, 'rule_sets') as $setResource) {
            $attributes = JsonApiDocument::attributesOf($setResource);

            $sets[] = new OriginRuleSet(
                heading: $this->stringOf($attributes['heading'] ?? null),
                subdivision: $this->stringOf($attributes['subdivision'] ?? null),
                rules: $this->mapRules($document, $setResource),
            );
        }

        // Rules hung directly off the scheme, which the documented shape
        // implies and the live payload has not used. Kept as a set of its own
        // so nothing is lost if that changes.
        $direct = $this->mapRules($document, $resource);

        if ($direct !== []) {
            $sets[] = new OriginRuleSet(rules: $direct);
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $resource
     * @return list<OriginRule>
     */
    private function mapRules(JsonApiDocument $document, array $resource): array
    {
        $rules = [];

        foreach ($document->resolveMany($resource, 'rules') as $ruleResource) {
            $type = $ruleResource['type'] ?? null;

            if (!is_string($type) || !in_array($type, self::RULE_TYPES, true)) {
                continue;
            }

            $attributes = JsonApiDocument::attributesOf($ruleResource);
            $text = $this->stringOf($attributes['rule'] ?? null);

            if ($text === null) {
                continue;
            }

            $rules[] = new OriginRule(
                text: $text,
                classes: $this->stringList($attributes['rule_class'] ?? []),
                operator: $this->stringOf($attributes['operator'] ?? null),
            );
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $resource
     * @return list<OriginProof>
     */
    private function mapProofs(JsonApiDocument $document, array $resource): array
    {
        $proofs = [];

        foreach ($document->resolveMany($resource, 'proofs') as $proofResource) {
            $attributes = JsonApiDocument::attributesOf($proofResource);
            $summary = $this->stringOf($attributes['summary'] ?? null);

            if ($summary === null) {
                continue;
            }

            $proofs[] = new OriginProof(
                summary: $summary,
                subtext: $this->stringOf($attributes['subtext'] ?? null),
                content: $this->stringOf($attributes['content'] ?? null),
                url: $this->stringOf($attributes['url'] ?? null),
            );
        }

        return $proofs;
    }

    /**
     * A list of plain strings from whatever the payload actually held.
     *
     * Anything that is not a scalar string is dropped rather than coerced. A
     * nested object cast to a string becomes the word "Array", and a country
     * list containing "Array" is worse than one item shorter.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            } elseif (is_int($item) || is_float($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    private function stringOf(mixed $value): ?string
    {
        if (!is_string($value)) {
            return is_int($value) || is_float($value) ? (string) $value : null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}