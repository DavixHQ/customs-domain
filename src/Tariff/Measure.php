<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

use DateTimeImmutable;

/**
 * One measure applying to a commodity.
 *
 * Direction is the first thing to get right. A single commodity response mixes
 * import and export measures freely - a cotton parka arrives with third
 * country duty, a tariff preference, and export controls on cat and dog fur
 * all in the same array. Anything that does not filter by direction will tell
 * a merchant importing stock about restrictions on goods leaving the country.
 *
 * Origin is the second. A measure is usually attached to a group rather than a
 * country, frequently ERGA OMNES with a handful of exclusions, so deciding
 * whether it applies to one merchant's origin means checking membership and
 * then subtracting exclusions. Skipping the subtraction applies third country
 * duty to origins that are exempt from it.
 */
final class Measure
{
    /**
     * @param list<MeasureComponent> $components
     * @param list<MeasureCondition> $conditions
     * @param list<string> $excludedAreaCodes Country codes carved out of the
     *        measure's geographical area.
     * @param list<string> $footnoteCodes
     */
    public function __construct(
        public readonly int $id,
        public readonly MeasureType $type,
        public readonly bool $import = true,
        public readonly bool $export = false,
        public readonly ?GeographicalArea $geographicalArea = null,
        public readonly array $excludedAreaCodes = [],
        public readonly ?DutyExpression $dutyExpression = null,
        public readonly array $components = [],
        public readonly array $conditions = [],
        public readonly ?DateTimeImmutable $effectiveFrom = null,
        public readonly ?DateTimeImmutable $effectiveTo = null,
        public readonly bool $vat = false,
        public readonly bool $excise = false,
        public readonly bool $meursing = false,
        public readonly ?string $orderNumber = null,
        public readonly ?string $preferenceCode = null,
        public readonly ?string $additionalCode = null,
        public readonly array $footnoteCodes = [],
    ) {
    }

    public function appliesTo(TradeDirection $direction): bool
    {
        return $direction === TradeDirection::Import ? $this->import : $this->export;
    }

    /**
     * Whether this measure applies to goods from a given origin.
     *
     * Membership first, then exclusions. A measure on ERGA OMNES with thirty
     * exclusions covers every origin except those thirty, and the order of
     * those two checks is the whole point.
     */
    public function appliesToOrigin(string $countryCode): bool
    {
        $code = strtoupper(trim($countryCode));

        if ($code === '') {
            return false;
        }

        if (in_array($code, array_map('strtoupper', $this->excludedAreaCodes), true)) {
            return false;
        }

        if ($this->geographicalArea === null) {
            return true;
        }

        return $this->geographicalArea->covers($code);
    }

    public function isInForceOn(DateTimeImmutable $date): bool
    {
        if ($this->effectiveFrom !== null && $date < $this->effectiveFrom) {
            return false;
        }

        if ($this->effectiveTo !== null && $date > $this->effectiveTo) {
            return false;
        }

        return true;
    }

    /**
     * Whether this measure stops the goods outright.
     *
     * The distinction that decides whether a merchant is told they cannot ship
     * a product or merely that they need paperwork, so getting it wrong in the
     * lenient direction risks a seizure and in the strict direction makes them
     * delist something they were entitled to sell.
     *
     * A measure type in series A is a prohibition and needs no further reading.
     *
     * Beyond that, nearly every control measure carries a negative condition
     * reading "import not allowed after control" - that is the branch taken
     * when the required document is *not* presented, not the measure's normal
     * outcome. A men's cotton parka carries several: import controls on cat
     * and dog fur, on seal products, on luxury goods. Treating those negative
     * conditions as prohibitions reports every parka in a catalogue as
     * unshippable, which is exactly the false alarm that teaches a merchant to
     * ignore the module.
     *
     * So a negative condition only prohibits when nothing else offers a way
     * through. If any document satisfies the measure, this is a licence
     * requirement and licence_required will report it as one.
     */
    public function prohibits(): bool
    {
        if ($this->type->isProhibition()) {
            return true;
        }

        if ($this->hasDocumentaryRoute()) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if ($condition->prohibits()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any condition names a document that satisfies the measure.
     *
     * Both document and exemption conditions count, because both are routes
     * through and the tariff uses whichever fits. The import control on cat
     * and dog fur - which sits on every garment in chapter 62 - offers its way
     * out as an *exemption* carrying code Y922, "Other than cats and dogs fur":
     * a declaration that the goods are not the controlled thing. Reading only
     * document-class conditions misses it and reports every parka in a
     * catalogue as unshippable.
     *
     * National statements count as well as certificates. A numeric 90xx code
     * is a declaration the merchant makes rather than a licence they obtain,
     * but the goods still move once it is given.
     */
    public function hasDocumentaryRoute(): bool
    {
        foreach ($this->conditions as $condition) {
            $offersRoute = $condition->class === MeasureConditionClass::Document
                || $condition->class === MeasureConditionClass::Exemption;

            if ($offersRoute && $condition->hasDocumentCode()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The documents that would satisfy this measure, any one of which is
     * enough.
     *
     * @return list<MeasureCondition>
     */
    public function documentaryOptions(): array
    {
        return array_values(array_filter(
            $this->conditions,
            static fn (MeasureCondition $c): bool => $c->isDocumentaryOption(),
        ));
    }

    /**
     * Whether a merchant has to present something for these goods to move.
     *
     * Scoped to control measures - series B - because that is what the series
     * means, and because a duty measure with a certificate option attached is
     * offering a cheaper rate rather than imposing a requirement.
     */
    public function requiresDocumentation(): bool
    {
        return $this->type->isControl() && $this->documentaryOptions() !== [];
    }

    /**
     * Whether a declaration the merchant makes themselves would satisfy this
     * control.
     *
     * Chapter 62 illustrates why this matters. Every garment carries import
     * controls on cat and dog fur and on seal products, and both are satisfied
     * by stating the goods are not those things - Y922 and Y032. The seal
     * control also lists certificates for anyone genuinely importing seal
     * products, but their presence does not make a cotton parka unshippable.
     *
     * So the question is whether *a* route by declaration exists, not whether
     * every route is one. Getting that backwards marks an entire apparel
     * catalogue as blocked, which is the fastest way to teach a merchant that
     * this rule is noise.
     */
    public function hasDeclarationRoute(): bool
    {
        foreach ($this->documentaryOptions() as $option) {
            if ($option->class === MeasureConditionClass::Exemption) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<MeasureCondition>
     */
    public function prohibitingConditions(): array
    {
        return array_values(array_filter(
            $this->conditions,
            static fn (MeasureCondition $c): bool => $c->prohibits(),
        ));
    }

    public function isQuota(): bool
    {
        return $this->orderNumber !== null && trim($this->orderNumber) !== '';
    }

    public function isPreference(): bool
    {
        return $this->type->isPreference();
    }

    public function hasDuty(): bool
    {
        return $this->dutyExpression !== null && !$this->dutyExpression->isEmpty();
    }

    /**
     * Whether any component charges by quantity or weight rather than value.
     */
    public function isQuantityBased(): bool
    {
        foreach ($this->components as $component) {
            if ($component->isSpecific()) {
                return true;
            }
        }

        return false;
    }
}
