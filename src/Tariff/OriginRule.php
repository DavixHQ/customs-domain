<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * One product-specific rule of origin.
 *
 * The thing that decides whether goods actually qualify for a preferential
 * rate, as opposed to whether one exists. A tariff saying Vietnamese parkas
 * attract 0% under CPTPP is only half an answer: the rate applies to goods
 * that originate there in the agreement's sense, which for apparel usually
 * means the fabric was woven there rather than merely cut and sewn there.
 *
 * Rules arrive as prose because that is what the agreements contain. No
 * attempt is made to evaluate them: whether a particular garment satisfies
 * "weaving accompanied by making-up" is a question about a supply chain that
 * no catalogue holds, and a module that guessed would be inventing a customs
 * position on a merchant's behalf.
 */
class OriginRule
{
    /**
     * @param list<string> $classes Broad kinds the rule falls into, such as
     *        PROCESSING or CHANGE_OF_TARIFF_HEADING. Useful for grouping;
     *        never for deciding.
     */
    public function __construct(
        public readonly string $text,
        public readonly array $classes = [],
        public readonly ?string $operator = null,
    ) {
    }

    /**
     * The rule with the tariff's own markup removed.
     *
     * Rule text carries links back to the tariff site and non-breaking spaces
     * inside code references: "a change to a good of [heading&nbsp;6201]
     * (/headings/6201)". Rendered raw in an admin panel that reads as broken
     * rather than as a citation.
     */
    public function plainText(): string
    {
        $text = $this->text;

        // Markdown links: keep the label, drop the target.
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);

        $text = str_replace(["&nbsp;", "\u{00A0}"], ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Whether the rule turns on where materials came from rather than on what
     * was done to them.
     *
     * Worth distinguishing because the two ask a merchant for different
     * evidence: a change of heading is answered from a bill of materials, a
     * processing rule from knowing what the factory actually did.
     */
    public function isChangeOfHeading(): bool
    {
        foreach ($this->classes as $class) {
            if (str_contains(strtoupper($class), 'CHANGE')) {
                return true;
            }
        }

        return str_contains(strtolower($this->plainText()), 'a change to a good');
    }
}