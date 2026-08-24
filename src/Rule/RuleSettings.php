<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

use Davix\Customs\Tariff\TradeDirection;

/**
 * Merchant configuration, in a form this package can read.
 *
 * The host reads its own config store and passes plain values in. Nothing here
 * knows what a Magento scope is.
 */
final class RuleSettings
{
    /**
     * Descriptions that get customs declarations rejected. Generic to the
     * point of uselessness — a carrier cannot classify "gift" or "parts".
     *
     * @var list<string>
     */
    public const DEFAULT_VAGUE_TERMS = [
        'gift',
        'gifts',
        'parts',
        'part',
        'sample',
        'samples',
        'goods',
        'clothing',
        'accessories',
        'accessory',
        'merchandise',
        'items',
        'item',
        'misc',
        'miscellaneous',
        'other',
        'various',
        'general goods',
        'personal effects',
    ];

    /**
     * Chapters where fibre composition drives classification, so a missing
     * composition is a real obstacle rather than a nicety.
     *
     * @var list<string>
     */
    public const DEFAULT_COMPOSITION_CHAPTERS = [
        '50', '51', '52', '53', '54', '55', '56', '57',
        '58', '59', '60', '61', '62', '63',
    ];

    /**
     * @param list<string> $disabledRules Rule codes the merchant has switched off.
     * @param array<string, Severity> $severityOverrides Rule code to replacement severity.
     * @param list<string> $vagueDescriptionTerms
     * @param list<string> $compositionChapters
     * @param list<string> $recognisedOriginCodes Country codes the tariff has a
     *        matching geographical area for. Supplied by the host from its own
     *        mirror. Left empty, origins are checked for shape only — better
     *        than rejecting valid countries against an empty list.
     * @param TradeDirection $direction Which way the merchant's goods move.
     *        A single commodity carries both directions' measures, and they
     *        are different restrictions — the artillery fixture has nine
     *        import prohibitions and eleven export ones. Reporting the wrong
     *        set is noise that teaches merchants to ignore the module.
     */
    public function __construct(
        public readonly int $staleVerificationMonths = 12,
        public readonly array $disabledRules = [],
        public readonly array $severityOverrides = [],
        public readonly array $vagueDescriptionTerms = self::DEFAULT_VAGUE_TERMS,
        public readonly array $compositionChapters = self::DEFAULT_COMPOSITION_CHAPTERS,
        public readonly int $expiryWarningDays = 60,
        public readonly array $recognisedOriginCodes = [],
        public readonly TradeDirection $direction = TradeDirection::Import,
    ) {
    }

    public function canCheckOriginAgainstTariffAreas(): bool
    {
        return $this->recognisedOriginCodes !== [];
    }

    public function recognisesOrigin(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->recognisedOriginCodes, true);
    }

    public function isEnabled(string $ruleCode): bool
    {
        return !in_array($ruleCode, $this->disabledRules, true);
    }

    /**
     * The severity to use for a rule, honouring any merchant override.
     */
    public function severityFor(string $ruleCode, Severity $default): Severity
    {
        return $this->severityOverrides[$ruleCode] ?? $default;
    }

    public function requiresCompositionFor(string $chapter): bool
    {
        return in_array($chapter, $this->compositionChapters, true);
    }
}
