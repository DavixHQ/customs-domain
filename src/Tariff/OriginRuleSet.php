<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * The rules that apply to one band of the nomenclature under one scheme.
 *
 * Rules are published against ranges rather than against single commodities:
 * "6201-6208" or "ex Chapter 62". The heading and subdivision are kept because
 * they are how a merchant checks the rule is talking about their goods, and
 * "ex Chapter 62, articles of apparel not knitted or crocheted" is a
 * meaningfully different claim from a rule about knitwear.
 */
class OriginRuleSet
{
    /**
     * @param list<OriginRule> $rules Alternatives, not requirements. Meeting
     *        any one of them is enough, which is why they are worth showing in
     *        full rather than reducing to the first.
     */
    public function __construct(
        public readonly ?string $heading = null,
        public readonly ?string $subdivision = null,
        public readonly array $rules = [],
    ) {
    }

    public function describes(): string
    {
        $parts = array_filter([$this->heading, $this->subdivision]);

        return $parts === [] ? '' : implode(': ', $parts);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }
}