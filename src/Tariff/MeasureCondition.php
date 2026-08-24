<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * A qualification on a measure: what must be presented, and what happens if it
 * is not.
 *
 * The conditions on a single commodity run to dozens — artillery weapons carry
 * 59 — and most of them are not requirements at all. Reading them well is the
 * difference between telling a merchant something true and burying them in
 * regulatory noise.
 */
final class MeasureCondition
{
    /**
     * Action codes on a negative condition that mean the goods cannot move.
     *
     * Observed live: 09 "Import/export not allowed after control", 06 "Import
     * is not allowed", 05 "Export is not allowed". Matching on 09 alone, as is
     * sometimes documented, misses the other two.
     *
     * @var list<string>
     */
    public const PROHIBITION_ACTION_CODES = ['05', '06', '09'];

    /** Prohibitions that speak only to goods leaving the country. */
    public const EXPORT_PROHIBITION_ACTION_CODE = '05';

    /** Prohibitions that speak only to goods entering it. */
    public const IMPORT_PROHIBITION_ACTION_CODE = '06';

    public function __construct(
        public readonly MeasureConditionClass $class,
        public readonly ?string $conditionCode = null,
        public readonly ?string $condition = null,
        public readonly ?string $action = null,
        public readonly ?string $actionCode = null,
        public readonly ?string $documentCode = null,
        public readonly ?string $requirement = null,
        public readonly ?string $certificateDescription = null,
        public readonly ?string $guidance = null,
    ) {
    }

    /**
     * Whether this condition stops the goods.
     */
    public function prohibits(): bool
    {
        return $this->class === MeasureConditionClass::Negative
            && $this->actionCode !== null
            && in_array($this->actionCode, self::PROHIBITION_ACTION_CODES, true);
    }

    /**
     * The direction a prohibition applies to, or null when it applies to both.
     *
     * Action 09 covers import and export together; 05 and 06 are specific.
     */
    public function prohibitionDirection(): ?TradeDirection
    {
        return match ($this->actionCode) {
            self::EXPORT_PROHIBITION_ACTION_CODE => TradeDirection::Export,
            self::IMPORT_PROHIBITION_ACTION_CODE => TradeDirection::Import,
            default => null,
        };
    }

    public function hasDocumentCode(): bool
    {
        return $this->documentCode !== null && trim($this->documentCode) !== '';
    }

    /**
     * Whether the document code follows the international certificate
     * convention rather than a national one.
     *
     * Useful for display grouping and nothing more. It is emphatically *not* a
     * test of whether something must be obtained: the firearms control offers
     * 9020 "This product is exempt as it is not a firearm" and 9023 "DBT
     * Firearms Import License" side by side, both numeric, one a formality and
     * one a licence. An earlier version of this class used the numeric prefix
     * to tell licences from statements and was simply wrong.
     */
    public function hasInternationalDocumentCode(): bool
    {
        if (!$this->hasDocumentCode()) {
            return false;
        }

        return preg_match('/^[A-Za-z]/', trim((string) $this->documentCode)) === 1;
    }

    /**
     * Whether this condition offers a way to satisfy its measure.
     *
     * A control measure is satisfied by presenting any one of its documentary
     * options. Those options mix licences a merchant must obtain with
     * exemptions they may simply declare, and the payload gives no reliable
     * way to tell which is which — so both count, and the merchant is shown
     * the list to pick from.
     */
    public function isDocumentaryOption(): bool
    {
        return $this->hasDocumentCode()
            && (
                $this->class === MeasureConditionClass::Document
                || $this->class === MeasureConditionClass::Exemption
            );
    }

    public function isExemption(): bool
    {
        return $this->class === MeasureConditionClass::Exemption;
    }

    /**
     * The best human-readable summary available, preferring the specific.
     */
    public function summary(): ?string
    {
        return $this->certificateDescription
            ?? $this->requirement
            ?? $this->condition
            ?? $this->action;
    }
}
