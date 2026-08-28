# davix/customs-domain

Framework-agnostic customs and tariff logic for PHP.

`davix/customs-domain` handles commodity code normalisation, tariff resolution, customs compliance rules and HMRC tariff data without depending on Magento or any other application framework.

It currently backs `davix/module-customs-radar` for Magento 2, but the domain package is deliberately kept separate so the same logic can be reused by other applications.

## What it does

The package provides:

* Commodity code normalisation and format validation
* Resolution of commodity codes against a local tariff mirror
* Measured-property narrowing for ambiguous classifications
* Great Britain and Northern Ireland tariff support
* Offline and measure-dependent customs compliance rules
* Catalogue scanning with tariff lookup deduplication
* An HMRC tariff client built against PSR interfaces
* Tariff certificate, quota and historic-code lookups

Storage, queues, scheduling, UI and translation are left to the application using the package.

## Requirements

* PHP 8.1+
* PSR-18 HTTP client
* PSR-17 HTTP factories
* PSR-16 cache implementation if caching is required
* PSR-3 logger if application logging is required

The package depends on interfaces rather than concrete implementations, so you can use whichever HTTP client, cache and logger already fit your application.

## Installation

```bash
composer require davix/customs-domain
```

## Package boundary

Nothing under `src/` should depend on a framework or e-commerce platform.

That means no Magento services, Symfony components, WordPress APIs or concrete HTTP clients in the domain layer.

The host application is responsible for reading its own configuration and passing plain values or implementations of the package interfaces into the domain.

The boundary can be checked locally with:

```bash
composer boundary
```

It is also checked in CI.

## Namespaces

| Namespace    | Purpose                                                               |
| ------------ | --------------------------------------------------------------------- |
| `Validation` | Commodity code normalisation, format validation and hierarchy helpers |
| `Tariff`     | Commodity, measure, quota and resolution domain objects               |
| `Product`    | Product customs-data contracts                                        |
| `Rule`       | Compliance rules, evaluation and rule settings                        |
| `Scan`       | Catalogue scanning and lookup deduplication                           |
| `Provider`   | Tariff provider contracts and the HMRC implementation                 |
| `Exception`  | Domain-specific exceptions                                            |

## Commodity code normalisation

Commodity codes often arrive from spreadsheets, supplier feeds or manually maintained product data in inconsistent formats.

`CodeNormaliser` handles common cases where the intended code can be recovered safely.

```php
use Davix\Customs\Validation\CodeNormaliser;

$normaliser = new CodeNormaliser();

$result = $normaliser->normalise('6201.40.10.19');

$result->isSuccess();                            // true
$result->code();                                 // '6201401019'
$result->wasModified();                          // true
$normaliser->formatForDisplay($result->code());  // '6201.40.10.19'
```

Supported cleanup includes things such as:

* dotted or spaced grouping
* surrounding quotes
* invisible whitespace
* Excel-style trailing `.0`
* leading zeroes lost because a cell was treated as numeric

The normaliser deliberately refuses values where recovering the original code would require guessing.

Scientific notation is one example:

```php
$result = $normaliser->normalise('6.20140102E+09');

$result->isFailure(); // true
```

Once a long commodity code has been converted to scientific notation, digits may already have been lost. Returning a failure is safer than producing another valid-looking but incorrect code.

## The local tariff mirror

Commodity resolution is performed against a local tariff mirror through `CommodityRepositoryInterface`.

The host application implements this interface using whatever storage makes sense for it.

The package does not prescribe a database, ORM or persistence model.

The repository provides access to things such as:

* commodity lookups by goods nomenclature SID
* commodity lookups by code
* declarable lines
* child and descendant relationships
* chapter availability
* the jurisdiction represented by the mirror

Resolution itself does not require a network request.

This is intentional. A catalogue scan should still be able to perform useful checks when the remote tariff service is unavailable.

It also means a failed or incomplete tariff sync does not automatically turn every affected product into an `unknown_code` result.

See [Integration](docs/INTEGRATION.md) for more information about implementing the host-side contracts.

## Resolving commodity codes

`CommodityResolver` resolves a normalised commodity code against the local mirror.

```php
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\MeasuredProperty;

$resolver = new CommodityResolver($repository);

$resolution = $resolver->resolve('620140', [
    MeasuredProperty::NET_WEIGHT => 1.5,
]);

$resolution->outcome;
$resolution->commodity;
$resolution->candidates;
$resolution->narrowedByMeasurement;
$resolution->candidatesBeforeNarrowing;
```

A code shorter than ten digits may represent a valid heading or subheading rather than a declarable commodity.

When that happens, the resolver walks the nomenclature below the supplied code and returns the available declarable candidates.

### Measured-property narrowing

Some tariff classifications depend on properties of the goods rather than the code hierarchy alone.

Known measurements can be supplied to help narrow an ambiguous result.

Supported properties include:

| Property           | Key                                  |
| ------------------ | ------------------------------------ |
| Net weight         | `MeasuredProperty::NET_WEIGHT`       |
| Volume             | `MeasuredProperty::VOLUME`           |
| Alcoholic strength | `MeasuredProperty::ALCOHOL_STRENGTH` |
| Fat content        | `MeasuredProperty::FAT_CONTENT`      |
| Protein content    | `MeasuredProperty::PROTEIN_CONTENT`  |
| Sugar content      | `MeasuredProperty::SUGAR_CONTENT`    |
| Starch content     | `MeasuredProperty::STARCH_CONTENT`   |
| Dry matter         | `MeasuredProperty::DRY_MATTER`       |

The measured-property map is intentionally open, so a host application can pass additional measurements without waiting for a dedicated property accessor to be added to the package.

Narrowing is conservative.

If a measurement required to evaluate a candidate is missing, that candidate is not discarded. It is safer to return an extra classification option than to remove the correct one based on incomplete product data.

### Resolution outcomes

Resolution does not treat every unsuccessful lookup as an invalid commodity code.

Important outcomes include:

* resolved to a declarable commodity
* ambiguous, with multiple declarable candidates
* code not present in the mirror
* chapter not available in the mirror
* code outside the standard nomenclature
* a matched grouping line with no usable declarable descendants

The distinction between **unknown** and **inconclusive** is important when working with a local tariff mirror.

If Chapter 62 has not been imported, for example, the resolver cannot reasonably conclude that every Chapter 62 code is invalid.

## Great Britain and Northern Ireland

Great Britain and Northern Ireland use separate tariff services.

The package keeps tariff jurisdiction explicit so data from the two services cannot be mixed accidentally.

```php
use Davix\Customs\Provider\Hmrc\HmrcClientOptions;
use Davix\Customs\Tariff\Jurisdiction;

$options = HmrcClientOptions::for(
    Jurisdiction::NorthernIreland
);
```

Applications supporting both tariffs should maintain a separate local mirror for each jurisdiction.

Before scanning, the host can also verify that the repository and remote provider agree:

```php
if ($repository->jurisdiction() !== $provider->jurisdiction()) {
    throw new RuntimeException(
        'Tariff repository and provider use different jurisdictions.'
    );
}
```

Provider cache keys are scoped by jurisdiction as well.

## Running compliance rules

Rules evaluate a `ProductCustomsDataInterface` together with an `EvaluationContext`.

```php
use Davix\Customs\Rule\DefaultRuleSet;
use Davix\Customs\Rule\EvaluationContext;

$pool = DefaultRuleSet::pool();

// Includes rules that need tariff measures/provider data:
// $pool = DefaultRuleSet::fullPool();

$evaluation = $pool->evaluate(
    $productData,
    new EvaluationContext(
        evaluatedAt: new DateTimeImmutable(),
        settings: $settings,
        resolution: $resolution,
        historic: $historicRecord,
        detail: $commodityDetail,
        certificates: $certificateIndex,
        quotas: $quotaSet,
    ),
);

$evaluation->issueCodes();
$evaluation->highestSeverity();
$evaluation->hasBlocking();
```

## Offline rules

Offline rules only require product data and the local tariff mirror.

| Code                          | Default severity | Fires when                                                   |
| ----------------------------- | ---------------- | ------------------------------------------------------------ |
| `missing_hs_code`             | Blocked          | No commodity code is supplied                                |
| `invalid_code_format`         | Blocked          | The supplied value is not a valid commodity-code shape       |
| `withdrawn_code`              | Attention        | The code existed historically but is no longer current       |
| `unknown_code`                | Blocked          | A well-formed code is not found                              |
| `ambiguous_expansion`         | Attention        | A code expands to more than one declarable line              |
| `missing_origin`              | Attention        | Country of origin is missing                                 |
| `origin_not_in_tariff_areas`  | Blocked          | Origin does not match a recognised tariff geographical area  |
| `vague_description`           | Attention        | Customs description is missing or too generic                |
| `description_is_product_name` | Attention        | Customs description simply repeats the product name          |
| `missing_composition`         | Attention        | Fibre composition is missing for configured textile chapters |
| `net_weight_exceeds_gross`    | Attention        | Net weight is greater than gross weight                      |
| `stale_verification`          | Attention        | Previously verified data is older than the configured period |

## Measure-dependent rules

These checks require tariff measures or other remote tariff information.

When the required information has not been fetched, the rule remains silent rather than trying to infer a result.

| Code                          | Default severity    | Fires when                                                               |
| ----------------------------- | ------------------- | ------------------------------------------------------------------------ |
| `prohibited_goods`            | Blocked             | An applicable prohibition prevents movement                              |
| `licence_required`            | Blocked / Attention | A control requires documentation                                         |
| `additional_duty_applies`     | Attention           | An additional duty or restriction applies                                |
| `preference_available`        | Opportunity         | A lower preferential percentage rate is available                        |
| `quota_exhausted`             | Attention           | A relevant quota is exhausted, unavailable or running low                |
| `vat_zero_rating_available`   | Opportunity         | A zero VAT option is available                                           |
| `missing_supplementary_units` | Attention           | The tariff requires a supplementary quantity not recorded by the product |
| `meursing_code_required`      | Attention           | Duty depends on a Meursing code                                          |
| `entry_price_system`          | Attention           | Duty depends on the goods' entry price                                   |
| `code_expiring_soon`          | Attention           | The commodity code expires inside the configured warning period          |

Some of these checks exist because tariff data contains behaviours that are not obvious from the API structure alone.

Those cases are documented in [Tariff notes](docs/TARIFF-NOTES.md).

## Rule prerequisites

Rules can depend on earlier checks.

For example, there is little value in attempting tariff resolution when the supplied commodity code is not even structurally valid.

Rules declare those dependencies as prerequisites:

```php
public function prerequisites(): array
{
    return [
        InvalidCodeFormat::CODE,
    ];
}
```

If a prerequisite emits an issue, dependent rules are skipped.

Skipping cascades through further dependencies.

A disabled prerequisite is treated as passing. Disabling one rule should not unexpectedly disable unrelated checks further down the chain.

`RuleEvaluation` records skipped rules and the reason they were skipped.

## Rule settings

`RuleSettings` contains merchant or application-specific behaviour.

This includes settings such as:

* disabled rules
* severity overrides
* stale verification age
* vague description terms
* chapters requiring composition
* recognised origin codes
* import or export direction
* quota low-balance warning threshold
* code-expiry warning period

The host application owns configuration storage and any UI used to manage these settings.

## Scanning a catalogue

`ProductScanner` runs a rule pool over an iterable set of products.

```php
use Davix\Customs\Scan\ProductScanner;
use Davix\Customs\Scan\ScanOptions;

$scanner = new ProductScanner(
    rules: DefaultRuleSet::fullPool(),
    resolver: $resolver,
    provider: $provider,
    settings: $settings,
    options: new ScanOptions(),
);

foreach ($scanner->scan($products) as $result) {
    // Persist, display or otherwise process the result.
}

$summary = $scanner->summary();
```

The scanner returns a generator, allowing a host application to process large catalogues without holding every result in memory.

## Lookup deduplication

Remote tariff lookups are cached for the lifetime of a scanner.

Commodity detail and quota requests are made using the **resolved commodity code**, not the individual product.

If 500 products resolve to the same commodity code, they can share the same remote tariff result.

Certificate descriptions are also fetched at most once per scan.

This keeps remote traffic tied more closely to the number of unique tariff classifications than the number of products in the catalogue.

## Network use

`ScanOptions` controls what a scan is allowed to retrieve remotely.

```php
$options = new ScanOptions(
    fetchMeasures: true,
    fetchQuotas: true,
    resolveWithdrawnCodes: true,
);
```

For a completely offline scan:

```php
$options = ScanOptions::offline();
```

Offline scanning disables:

* tariff measure lookups
* quota lookups
* historic-code lookups

Rules requiring those inputs remain silent.

## Provider failures

A remote provider failure does not discard checks that can still be performed locally.

If an HMRC request fails during a product scan, offline rules continue to run and the scan result records that the remote portion was incomplete.

The host can therefore distinguish between:

* a clean product
* a product with customs issues
* a product whose complete status could not be determined

`ScanOptions::$maxProviderFailures` can be used to stop a run after repeated remote failures.

## HMRC tariff provider

`HmrcClient` implements `TariffProviderInterface` and uses PSR interfaces for external dependencies.

```php
use Davix\Customs\Provider\Hmrc\HmrcClient;
use Davix\Customs\Provider\Hmrc\HmrcClientOptions;

$provider = new HmrcClient(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    options: new HmrcClientOptions(),
    cache: $cache,
    logger: $logger,
);
```

The provider supports:

* commodity detail and measures
* complete chapter data for mirror synchronisation
* tariff changes
* certificate descriptions
* historic commodity lookups
* quota balances
* service availability checks

Dated lookups use an `as_of` date.

The client also handles:

* transient request retries
* `Retry-After`
* jittered retry backoff
* optional caching
* jurisdiction-specific cache keys

Cache failures are treated as cache failures rather than tariff failures. A broken cache should not make HMRC appear unavailable.

See [Integration](docs/INTEGRATION.md) for a fuller application wiring example.

## Messages and translation

Rules return message keys and context rather than final English strings.

```php
$issue->messageKey();
$issue->context;
```

The consuming application decides how those messages should be translated and displayed.

This avoids coupling the domain package to any framework translation system.

## Further documentation

* [Integration guide](docs/INTEGRATION.md) - wiring the package into a host application
* [Architecture](docs/ARCHITECTURE.md) - package boundaries and data flow
* [Tariff notes](docs/TARIFF-NOTES.md) - useful edge cases found while working with HMRC tariff data
* [Changelog](CHANGELOG.md) - release history

## Development

Install dependencies:

```bash
composer install
```

Run the complete project check:

```bash
composer check
```

`composer check` currently runs:

1. Framework boundary check
2. Character check
3. Redundant nullsafe check
4. PHPStan
5. PHPUnit

Individual checks can also be run separately:

```bash
composer boundary
composer characters
composer nullsafe
composer analyse
composer test
```

PHPStan runs at maximum level.

Tests use recorded fixtures and do not call the live tariff API.

The nullsafe and character checks require `python3` to be available on `PATH`.

## Status

Pre-1.0 and under active development.

Public interfaces may change before the first stable release.

In particular, consumers should currently treat the following contracts as unstable:

* `RuleInterface`
* `EvaluationContext`
* `CommodityRepositoryInterface`

## Licence

MIT. See [LICENSE](LICENSE).
