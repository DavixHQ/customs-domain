# davix/customs-domain

Platform-agnostic customs and tariff logic: commodity code normalisation and
validation, nomenclature resolution against a local mirror, and the compliance
rules that decide whether a product's customs data is fit to ship on.

This package knows nothing about any e-commerce platform. It backs
`davix/module-customs-radar` for Magento 2 and is intended to support
equivalent integrations elsewhere without modification.

## Requirements

PHP 8.1 or later. The only runtime dependencies are PSR interfaces -
`psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/log` and
`psr/simple-cache`. No concrete HTTP client, cache or logger is bundled; the
consuming application supplies those.

## Installation

```bash
composer require davix/customs-domain
```

## The boundary

One rule, enforced in CI: nothing under `src/` may reference any framework. No
`Magento\`, no `WP_`, no Guzzle, no Symfony. Configuration arrives as
constructor arguments; the host reads its own config and passes plain values
in. Messages come out as a translation key plus a context array, so the host
owns wording and locale.

```bash
composer boundary
```

The moment this package can only run inside one platform, it has stopped being
worth separating from that platform's module.

## What lives here

| Namespace | Contents |
|---|---|
| `Validation` | Code normalisation, format checking, hierarchy helpers |
| `Tariff` | Commodity and measure value objects, repository contract, resolver, weight parsing |
| `Product` | The product data contract a host implements over its own storage |
| `Rule` | The rule engine, settings, and the checks themselves |
| `Provider` | The tariff service contract, and an HMRC client over PSR-18 |
| `Exception` | One base type so hosts can isolate domain failures |

## What does not

Storage, scheduling, queueing, user interface, translation, and anything
deciding *when* work happens. This package defines what a resolution is and
what makes a product non-compliant. It never decides where data is kept, when
it is checked, or how it is presented.

## Normalising a merchant's code

Merchant commodity data almost always originates in a supplier spreadsheet,
and spreadsheets damage codes in predictable ways. Normalisation runs in front
of every rule.

```php
use Davix\Customs\Validation\CodeNormaliser;

$normaliser = new CodeNormaliser();
$result = $normaliser->normalise('6201.40.10.19');

$result->isSuccess();                            // true
$result->code();                                 // '6201401019'
$result->wasModified();                          // true
$normaliser->formatForDisplay($result->code());  // '6201.40.10.19'
```

It repairs what can be repaired unambiguously - dotted and spaced grouping,
Excel's trailing `.0`, leading zeroes stripped by numeric formatting - and
refuses to guess at anything else:

```php
$result = $normaliser->normalise('6.20140102E+09');

$result->isFailure();   // true
$result->failure;       // NormalisationFailure::ScientificNotation
```

Excel exports long codes in scientific notation, destroying the trailing
digits. A plausible-looking wrong commodity code is more damaging than a
visible error, so the normaliser reports rather than salvages.

## Resolving against the mirror

The host implements `CommodityRepositoryInterface` over its own storage. The
resolver reads it and never touches the network, which is what lets a scan run
with zero HTTP per product and stops a provider outage from flagging an entire
catalogue as broken.

```php
use Davix\Customs\Tariff\CommodityResolver;

$resolver = new CommodityResolver($repository);
$resolution = $resolver->resolve('620140', netWeightKg: 1.5);

$resolution->outcome;          // ResolutionOutcome::Ambiguous
$resolution->candidates;       // declarable lines to choose between
$resolution->narrowedByWeight; // whether net weight eliminated any
```

`ResolutionOutcome` separates "we checked and it is wrong" from "we could not
check". An unmirrored chapter proves nothing about a code, and reporting a
failed sync as thousands of bad merchant codes would be both wrong and
corrosive to trust.

## Running the rules

```php
use Davix\Customs\Rule\{DefaultRuleSet, EvaluationContext};

$pool = DefaultRuleSet::pool();      // offline checks only
// $pool = DefaultRuleSet::fullPool(); // including measure-dependent checks

$evaluation = $pool->evaluate($productData, new EvaluationContext(
    evaluatedAt: new DateTimeImmutable(),
    settings: $settings,
    resolution: $resolution,
    historic: $historicRecord,
    detail: $commodityDetail,
));

$evaluation->issueCodes();       // ['ambiguous_expansion', 'missing_origin']
$evaluation->highestSeverity();  // Severity::Attention
$evaluation->hasBlocking();      // false
```

### The offline rule set

Every check below evaluates against the local mirror or the product's own
data, with no network access.

| Code | Severity | Fires when |
|---|---|---|
| `missing_hs_code` | Blocked | No commodity code supplied |
| `invalid_code_format` | Blocked | Present but not a shape a code can be |
| `withdrawn_code` | Attention | Real once, withdrawn since |
| `unknown_code` | Blocked | Well formed but never existed |
| `ambiguous_expansion` | Attention | Real, but more than one declarable line beneath |
| `missing_origin` | Attention | No country of origin |
| `origin_not_in_tariff_areas` | Blocked | Origin matches no tariff geographical area |
| `vague_description` | Attention | Description missing or too generic to use |
| `description_is_product_name` | Attention | Description is a copy of the product name |
| `missing_composition` | Attention | No fibre content in a textile chapter |
| `net_weight_exceeds_gross` | Attention | Net heavier than gross, which is impossible |
| `stale_verification` | Attention | Confirmed once, not since the configured period |

### The measure-dependent rule set

These need commodity measures fetched over the wire, so they cost an HTTP call
per distinct commodity. A merchant can run the offline set across a whole
catalogue nightly and reserve these for products that resolved cleanly. All of
them stay silent when no measures were fetched, so including them in an offline
scan is harmless rather than wrong.

| Code | Severity | Fires when |
|---|---|---|
| `prohibited_goods` | Blocked | An unconditional prohibition covers this origin |
| `licence_required` | Blocked / Attention | A control needs a document; attention where a declaration suffices |
| `additional_duty_applies` | Attention | An additional duty or sanction applies to this origin |
| `preference_available` | Opportunity | A lower rate exists for this origin than the standard one |
| `vat_zero_rating_available` | Opportunity | The commodity carries a zero VAT option |
| `missing_supplementary_units` | Attention | The commodity is declared in a unit the product does not record |
| `meursing_code_required` | Attention | Duty depends on composition via a Meursing code |
| `entry_price_system` | Attention | Duty depends on the price the goods enter at |
| `code_expiring_soon` | Attention | The code stops being valid inside the warning window |

Two things these get right that a naive reading of the payload does not.

**A negative condition is not a prohibition.** Nearly every control measure
carries one reading "not allowed after control" - the branch taken when the
required document is absent, not the measure's normal outcome. Chapter 62
garments carry several, so counting them marks an entire apparel catalogue
unshippable. A prohibition means measure series A, or a negative condition with
no documentary route at all.

**Precomputed flags are commodity-level, not origin-level.** A cotton parka
comes back with `trade_defence: true` because Russia and Belarus attract a 35%
additional duty. Firing on the flag would report that surcharge against
Vietnamese stock, so the measures decide and the flag only says whether looking
is worthwhile.

### Prerequisites

Rules declare which other rules must pass before they are worth running.
Without that, one unreadable commodity code produces five issues on a single
product, four of them derived from the first. The merchant fixes one thing,
four issues vanish, and the counts look arbitrary.

```php
public function prerequisites(): array
{
    return [InvalidCodeFormat::CODE];
}
```

A rule whose prerequisite emitted an issue is skipped, and skipping cascades.
A rule the merchant *disabled* counts as passing instead - silencing one check
should not silently disable everything downstream of it. `RuleEvaluation`
keeps the skip list with reasons, so a host can honestly report that a check
did not run rather than implying it passed.

### Adding a rule

One class implementing `RuleInterface`, and one registration. If it ever takes
more than that, the abstraction has gone wrong and should be fixed before more
checks are written.

## Messages

Rules emit translation keys and context, never sentences:

```php
$issue->messageKey();   // 'rule.withdrawn_code.message.with_successor'
$issue->context;        // ['code' => '6201930000', 'successor_code' => '...']
```

The host renders them. This package has no framework and no locale, and baking
English into it would make every consumer inherit one language.

## Development

```bash
composer install
composer check      # boundary, nullsafe, static analysis, tests
```

Individually: `composer boundary`, `composer nullsafe`, `composer analyse`,
`composer test`.

PHPStan runs at level max. Tests use recorded fixtures and never contact the
live tariff API.

`bin/check-nullsafe.py` catches redundant `?->` operators before PHPStan does,
with an explanation rather than a lint code. It needs `python3` on PATH.

## Status

Pre-1.0 and under active development. Public interfaces - particularly
`RuleInterface`, `EvaluationContext` and `CommodityRepositoryInterface` -
should be treated as unstable until a 1.0 tag.

## Licence

MIT. See [LICENSE](LICENSE).