<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

use Davix\Customs\Rule\Checks\AdditionalDutyApplies;
use Davix\Customs\Rule\Checks\AmbiguousExpansion;
use Davix\Customs\Rule\Checks\CodeExpiringSoon;
use Davix\Customs\Rule\Checks\EntryPriceSystem;
use Davix\Customs\Rule\Checks\LicenceRequired;
use Davix\Customs\Rule\Checks\MeursingCodeRequired;
use Davix\Customs\Rule\Checks\MissingSupplementaryUnits;
use Davix\Customs\Rule\Checks\PreferenceAvailable;
use Davix\Customs\Rule\Checks\ProhibitedGoods;
use Davix\Customs\Rule\Checks\VatZeroRatingAvailable;
use Davix\Customs\Rule\Checks\DescriptionIsProductName;
use Davix\Customs\Rule\Checks\InvalidCodeFormat;
use Davix\Customs\Rule\Checks\MissingComposition;
use Davix\Customs\Rule\Checks\MissingHsCode;
use Davix\Customs\Rule\Checks\MissingOrigin;
use Davix\Customs\Rule\Checks\NetWeightExceedsGross;
use Davix\Customs\Rule\Checks\OriginNotInTariffAreas;
use Davix\Customs\Rule\Checks\StaleVerification;
use Davix\Customs\Rule\Checks\UnknownCode;
use Davix\Customs\Rule\Checks\VagueDescription;
use Davix\Customs\Rule\Checks\WithdrawnCode;

/**
 * The rules that need no network access at evaluation time.
 *
 * Every check here resolves against the local mirror or the product's own
 * data, which is what lets a scan run at full speed with zero HTTP per product
 * and keeps a provider outage from flagging an entire catalogue as broken.
 * Measure-dependent checks - prohibitions, licences, quotas, preferences -
 * arrive separately because they need commodity measures fetched over the wire.
 *
 * A host with its own dependency injection will usually register rules
 * individually so a merchant can enable and disable them. This factory exists
 * for tests, CLI tooling, and any consumer that just wants the standard set.
 */
final class DefaultRuleSet
{
    /**
     * @return list<RuleInterface>
     */
    public static function offline(): array
    {
        return [
            // Commodity code: presence, then shape, then existence, then specificity.
            new MissingHsCode(),
            new InvalidCodeFormat(),
            new WithdrawnCode(),
            new UnknownCode(),
            new AmbiguousExpansion(),

            // Origin.
            new MissingOrigin(),
            new OriginNotInTariffAreas(),

            // Declaration content.
            new VagueDescription(),
            new DescriptionIsProductName(),
            new MissingComposition(),

            // Physical data.
            new NetWeightExceedsGross(),

            // Housekeeping.
            new StaleVerification(),
        ];
    }

    /**
     * Checks that need commodity measures fetched over the wire.
     *
     * Separate from the offline set because they cost an HTTP call per
     * distinct commodity. A merchant can run the offline set across a whole
     * catalogue nightly and reserve these for products that resolved cleanly,
     * which is the difference between a scan that finishes and one that does
     * not.
     *
     * All of them stay silent when no measures were fetched, so including them
     * in an offline scan is harmless rather than wrong.
     *
     * @return list<RuleInterface>
     */
    public static function measureDependent(): array
    {
        return [
            // Movement.
            new ProhibitedGoods(),
            new LicenceRequired(),

            // Cost.
            new AdditionalDutyApplies(),
            new PreferenceAvailable(),
            new VatZeroRatingAvailable(),

            // Declaration completeness.
            new MissingSupplementaryUnits(),

            // Awareness.
            new MeursingCodeRequired(),
            new EntryPriceSystem(),
            new CodeExpiringSoon(),
        ];
    }

    /**
     * @return list<RuleInterface>
     */
    public static function all(): array
    {
        return [...self::offline(), ...self::measureDependent()];
    }

    /**
     * A pool of the offline checks only.
     */
    public static function pool(): RulePool
    {
        return new RulePool(self::offline());
    }

    /**
     * A pool of every check.
     */
    public static function fullPool(): RulePool
    {
        return new RulePool(self::all());
    }
}
