# Tariff notes

This document records behaviours and edge cases discovered while working with UK tariff data.

It is not intended to be a complete description of the HMRC APIs. The aim is to keep hold of details that affect how `davix/customs-domain` resolves classifications or evaluates customs rules.

Some of these behaviours were found while debugging real tariff data and are easy to forget once the related code has been fixed.

## Local tariff data is not the same as invalid tariff data

`CommodityResolver` works against a local tariff mirror.

That makes it important to distinguish between:

* a code that does not exist in tariff data
* a code that cannot currently be checked because the relevant part of the tariff has not been mirrored

For example, if Chapter 62 is not present locally, a lookup for a Chapter 62 commodity code is inconclusive.

It should not automatically become an `unknown_code` issue.

Treating those cases separately avoids large numbers of false errors after a partial or failed tariff synchronisation.

## Grouping codes are not necessarily declarable codes

Valid tariff headings and subheadings can be shorter than the final declarable commodity code.

A product may therefore contain a structurally valid code that identifies a point in the tariff hierarchy but is not itself sufficient for a customs declaration.

`CommodityResolver` expands these codes to the declarable lines below them.

If there is only one usable descendant, resolution can complete automatically.

If several declarable lines remain, the result stays ambiguous unless available measured properties can narrow it safely.

## Measurement conditions may live above the declarable line

Tariff classification conditions do not always live directly on the final declarable commodity.

A condition that separates child classifications may be attached to a parent heading or another ancestor in the nomenclature.

This matters when narrowing candidates using properties such as:

* net weight
* volume
* alcoholic strength
* fat content
* protein content
* sugar content
* starch content
* dry matter

Candidate narrowing therefore cannot assume that inspecting the declarable line alone will reveal the condition responsible for the split.

Relevant conditions may need to be considered while walking the candidate's ancestry.

## Missing measurements must not eliminate candidates

Measured-property narrowing should only remove a classification when the available product data proves that the candidate does not apply.

If a candidate depends on a measurement the product does not provide, the result is unknown rather than false.

For example, suppose two tariff lines are distinguished by alcoholic strength but the product only provides net weight.

Neither candidate should be removed based on the missing alcohol measurement.

This can leave a classification ambiguous, but it avoids silently selecting the wrong commodity code.

## Decimal commas appear in quantity conditions

Tariff descriptions can contain decimal values written with a comma rather than a decimal point.

For example:

```text
net content not exceeding 2,5 kg
```

A generic numeric cleanup can easily turn:

```text
2,5
```

into:

```text
25
```

That failure is particularly dangerous because `25` is still a perfectly valid numeric value. The parser appears to have succeeded while changing the meaning of the tariff condition.

Quantity parsing therefore needs to handle decimal commas explicitly rather than treating every comma as a thousands separator.

## Negative conditions do not automatically mean prohibition

A tariff measure can contain a negative condition without the measure itself meaning that the goods are prohibited.

For example, a condition may describe an exception, exemption or route where a particular document is not required.

Inferring a prohibition from the presence of a negative condition can therefore produce false blocking results.

The rule engine evaluates the actual measure and its applicable conditions rather than treating negative condition syntax as proof of prohibition.

## Document codes do not reliably describe document purpose

Certificate and document codes should not be classified solely from their prefix.

A code that looks similar to another licence-related document is not necessarily interchangeable with it.

The useful meaning comes from the tariff measure, its conditions and the certificate metadata returned by the tariff service.

Where a human-readable certificate description is needed, `HmrcClient` can fetch and cache it during a scan.

Certificate descriptions are shared for the duration of the scan so the same code is not repeatedly requested.

## Licence controls are not all equally blocking

Not every documentation-related tariff measure means that movement is impossible without a traditional licence.

Some routes allow a declaration or alternative supporting document.

For that reason `licence_required` may produce different default severities depending on the applicable route rather than treating every document requirement as an unconditional block.

The consuming application can still override rule severity through `RuleSettings`.

## Quotas need more context than a yes/no flag

The presence of a quota measure does not by itself tell us whether a product can use it.

Useful quota information includes things such as:

* current balance
* exhaustion
* whether the quota is currently available
* the geographical area it applies to
* the product's origin
* the date of evaluation

`quota_exhausted` is therefore based on the applicable quota state rather than simply checking whether a quota measure exists.

A configurable low-balance threshold can also be used to warn before a quota is fully exhausted.

## Geographical areas are part of tariff logic

Tariff measures frequently apply to geographical areas rather than one literal country code.

A condition may apply to:

* a single country
* a group of countries
* a trade area
* all countries except a defined set

The product's country of origin therefore has to be considered against tariff geographical-area data rather than by comparing one string directly with another.

This is also why an origin that is syntactically valid can still fail `origin_not_in_tariff_areas`.

## Preferential rates should be useful opportunities

The existence of a preference measure does not automatically mean the application can present a simple "cheaper rate available" message.

Some duty expressions depend on additional information or calculations that are not represented by a straightforward percentage.

`preference_available` therefore concentrates on preference data that can be presented safely, such as a lower plain-percentage rate.

More complicated duty expressions should not be reduced to a misleading percentage comparison.

## Supplementary units are separate from normal weight

Some commodity codes require a supplementary unit in addition to normal customs quantities.

Examples can include units such as:

* number of items
* litres
* square metres
* other tariff-specific quantities

A product can therefore have perfectly valid net and gross weights and still be missing a quantity required by the tariff.

`missing_supplementary_units` exists to keep that case separate from general weight validation.

## Meursing codes and entry prices need additional product data

Some tariff measures cannot be evaluated completely from the commodity code and country of origin alone.

Two examples are:

* Meursing-dependent duties
* entry price systems

When the tariff says one of these mechanisms applies, the package reports that additional information is required rather than attempting to invent a duty result.

## Historic codes should be distinguished from unknown codes

A commodity code that no longer exists may still have been valid previously.

Where historic lookup is enabled, the package can use HMRC historic data to distinguish a withdrawn code from one that has never been recognised.

That allows an application to give a more useful result:

```text
This commodity code has been withdrawn.
```

rather than:

```text
Unknown commodity code.
```

Historic lookups can be disabled for offline scans.

## GB and Northern Ireland data must not be mixed

Great Britain and Northern Ireland use different tariff services.

Their API responses may have similar structures, which makes an accidental mismatch surprisingly easy to overlook.

For that reason jurisdiction is carried explicitly through:

* the tariff provider
* the local repository
* provider configuration
* cache keys

An application supporting both should keep separate mirrors and verify that the selected repository and provider belong to the same jurisdiction before starting a scan.

## Remote failures should not erase local findings

A catalogue scan can contain both local checks and checks requiring HMRC data.

If a provider request fails, local validation is still useful.

For example, an HMRC outage should not prevent the scanner from identifying:

* missing commodity codes
* invalid code formats
* missing origin
* poor descriptions
* impossible net/gross weight combinations

`ProductScanner` therefore keeps the offline evaluation and marks the result as incomplete when the remote portion could not be evaluated.

This lets the host tell the difference between a product with no issues and a product that could not be checked completely.

## Deduplicate by tariff classification, not by product

A large catalogue may contain thousands of products but only hundreds of distinct commodity codes.

Tariff measures and quotas belong to the classification rather than the individual catalogue row.

`ProductScanner` therefore reuses remote results by resolved commodity code during a scan.

If several products resolve to the same commodity code, they can share:

* commodity detail
* measures
* quota information

Certificate descriptions are also cached at scan level.

This substantially reduces unnecessary HMRC traffic when scanning larger catalogues.

## Why these notes are separate from the changelog

Several of these behaviours were originally documented alongside the change that introduced or fixed them.

That made the changelog useful while development was happening, but over time it also made release history harder to read.

The changelog now records **what changed**.

This document keeps the more useful explanation of **why the implementation behaves that way**.
