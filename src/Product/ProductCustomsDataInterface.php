<?php

declare(strict_types=1);

namespace Davix\Customs\Product;

use Davix\Customs\Validation\NormalisationResult;
use DateTimeImmutable;

/**
 * The customs data a rule sees when it examines one product.
 *
 * Deliberately not a platform product object. A rule must not be able to reach
 * into a catalogue, load a related entity or trigger a database query, it is
 * a pure function over this data and a resolved commodity, which is what makes
 * the whole rule set testable with no framework present.
 *
 * The host application implements this over its own storage. In Magento that
 * is a bridge over EAV attribute values; elsewhere it might be metafields or
 * plain columns.
 */
interface ProductCustomsDataInterface
{
    /**
     * The host application's identifier for this product.
     *
     * A string rather than an integer, because not every platform uses
     * numeric identifiers. Magento passes its entity ID cast to string.
     */
    public function identifier(): string;

    public function sku(): string;

    public function name(): string;

    /**
     * ISO 3166-1 alpha-2 country of origin, or null if unset.
     */
    public function countryOfOrigin(): ?string;

    /**
     * The commodity code, already normalised.
     *
     * Returns the full normalisation result rather than a bare string so that
     * a rule can distinguish "no code supplied" from "code supplied but
     * unreadable", explain what went wrong in terms of what the merchant
     * actually typed, and report what was corrected. A merchant told their
     * code is invalid needs to see their own value, not a silently repaired
     * one.
     */
    public function hsCode(): NormalisationResult;

    /**
     * The plain commercial description used on a customs declaration.
     *
     * Distinct from the product name. "Blue" is a fine product name and a
     * useless customs description.
     */
    public function customsDescription(): ?string;

    /**
     * Net weight in kilograms, excluding packaging.
     *
     * A classification input, not merely a duty input: apparel subheadings
     * frequently branch on garment weight, so a populated net weight often
     * halves the candidate list before the merchant is asked anything.
     */
    public function netWeight(): ?float;

    /**
     * Gross weight in kilograms, including packaging.
     *
     * Supplied by the host application's own shipping weight. Used only to
     * sanity-check net weight against it, which catches grams entered as
     * kilograms.
     */
    public function grossWeight(): ?float;

    /**
     * Quantity in whatever supplementary unit the commodity requires.
     *
     * The unit itself comes from the resolved commodity, not from here.
     */
    public function supplementaryQuantity(): ?float;

    /**
     * Materials and percentages. Drives classification throughout the textile
     * chapters, where fibre content decides the subheading.
     */
    public function composition(): ?string;

    public function intendedUse(): ?string;

    public function manufacturer(): ?string;

    /**
     * When a person last confirmed this product's customs data was correct.
     */
    public function verifiedAt(): ?DateTimeImmutable;

    /**
     * Measured properties of the goods, keyed by property name.
     *
     * What the tariff branches on, beyond weight. Chapter 22 splits 348 times
     * on alcoholic strength and 43 on container volume; chapter 4 splits on
     * fat content. A merchant who records those gets their candidate list
     * narrowed the same way an apparel merchant does when they record a
     * garment weight.
     *
     * An open map rather than fixed accessors, because the nomenclature
     * measures dozens of things and a host that can supply one this package
     * has not named should not have to wait for a release. See
     * MeasuredProperty for the well-known keys.
     *
     * A condition on a property absent from this map never eliminates a
     * candidate.
     *
     * @return array<string, float>
     */
    public function measuredProperties(): array;
}
