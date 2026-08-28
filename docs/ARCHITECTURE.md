# Architecture

`davix/customs-domain` contains customs and tariff domain logic without owning the application around it.

The package is deliberately small at its boundaries:

* products come in through interfaces
* tariff nomenclature is read through a repository
* live tariff information comes through a provider
* rules operate on prepared data
* results go back to the host

The host application remains responsible for infrastructure.

This document explains how those pieces fit together and why the boundaries exist.

## The main boundary

The most important rule in the project is simple:

> Code under `src/` must not depend on the framework consuming it.

The package may know about PHP and the PSR interfaces declared in `composer.json`.

It should not know about:

* Magento
* WordPress
* Symfony services
* Laravel models
* Doctrine entities
* a particular database
* a particular queue
* a particular HTTP client
* a particular cache implementation
* a translation framework

The boundary is enforced by the project's boundary check:

```bash
composer boundary
```

The purpose is not abstraction for its own sake.

Customs rules, tariff classification and HMRC response handling are the parts most likely to be reused and the parts where inconsistent behaviour between integrations would be costly.

## Dependency direction

Dependencies point toward the domain rather than from the domain into the host.

```text
+-------------------------------------------------------+
|                    Host application                   |
|                                                       |
| Products | Database | Config | Queue | UI | Scheduler |
+--------------------------+----------------------------+
                           |
                           | adapters / plain values
                           v
+-------------------------------------------------------+
|                 davix/customs-domain                  |
|                                                       |
| Product   Tariff   Rules   Scan   Provider   Validation|
+-------------------------------------------------------+
                           |
                           | PSR interfaces
                           v
+-------------------------------------------------------+
|          HTTP client | Cache | Logger                 |
+-------------------------------------------------------+
```

The package defines the information it needs.

The host decides where that information comes from.

## Package areas

The main namespaces each have a fairly specific responsibility.

### `Validation`

Responsible for values before tariff logic starts.

This includes:

* commodity-code normalisation
* format validation
* hierarchy helpers
* safe handling of damaged spreadsheet values

Validation should not know whether a code exists in the tariff.

Those are separate questions:

```text
"6201.40.10.19"
        |
        v
Can it be normalised?
        |
        v
Is the resulting shape valid?
        |
        v
Does it exist in the tariff?
```

Keeping these separate gives much better error reporting.

A malformed value is not the same problem as a well-formed code that no longer exists.

### `Product`

Defines the product data visible to customs rules.

The central contract is:

```php
ProductCustomsDataInterface
```

It is deliberately not a product model.

A rule cannot:

* save the product
* load another product
* query attributes
* inspect a framework service locator
* trigger lazy database access intentionally
* change catalogue state

It receives a snapshot of the customs data required for evaluation.

That keeps rule behaviour deterministic and makes rule tests cheap.

### `Tariff`

Contains tariff-domain values and classification logic.

This area includes things such as:

* commodities
* tariff measures
* quotas
* geographical areas
* certificates
* historic records
* jurisdictions
* resolution outcomes
* `CommodityResolver`
* `CommodityRepositoryInterface`

The important distinction is that tariff-domain objects do not imply network access.

A `Commodity` is data.

A `CommodityResolver` works from the local repository.

Fetching HMRC data belongs to `Provider`.

### `Provider`

Defines access to an external tariff service.

The public boundary is:

```php
TariffProviderInterface
```

The included implementation is:

```php
Davix\Customs\Provider\Hmrc\HmrcClient
```

Consumers are not required to use it.

A different implementation could be used for:

* recorded test data
* another tariff service
* an internal proxy
* a future provider

The rest of the package works against the role of "tariff provider", not against HMRC-specific HTTP code.

### `Rule`

Contains customs compliance evaluation.

A rule implements:

```php
RuleInterface
```

A rule receives:

```php
ProductCustomsDataInterface
EvaluationContext
```

and returns either:

```php
Issue
```

or:

```php
null
```

Rules should be pure.

They do not fetch missing information themselves.

### `Scan`

Coordinates work across products.

`ProductScanner` sits above resolution, provider access and the rule engine.

Its job includes:

* resolving the product's commodity code
* deciding whether a live lookup would be useful
* fetching live tariff data where enabled
* deduplicating provider calls
* constructing the evaluation context
* evaluating the rules
* preserving offline results when remote checks fail
* maintaining scan totals

The scanner coordinates domain work.

It does not own application infrastructure around the scan.

## Product data is a snapshot

A rule sees `ProductCustomsDataInterface`.

Conceptually, that interface represents this:

```text
Product customs snapshot
├── identifier
├── SKU
├── product name
├── country of origin
├── commodity-code normalisation result
├── customs description
├── net weight
├── gross weight
├── supplementary quantity
├── composition
├── intended use
├── manufacturer
├── verification date
└── measured properties
```

There is no method such as:

```php
$product->save();
```

and no generic:

```php
$product->getAttribute($name);
```

That is intentional.

Rules should only depend on data they declare through the contract.

If a new rule genuinely needs another piece of product information, that requirement should be considered explicitly rather than hidden behind arbitrary product-model access.

## Normalisation before resolution

Merchant commodity codes cannot be assumed to arrive clean.

A value might be:

```text
6201401019
6201.40.10.19
6201 40 10 19
"6201401019"
6201401019.0
```

or it may have been damaged badly enough that the original value cannot be recovered safely.

The architecture keeps normalisation separate from tariff resolution.

```text
Raw merchant value
        |
        v
CodeNormaliser
        |
        v
NormalisationResult
        |
        v
format rules
        |
        v
CommodityResolver
```

`ProductCustomsDataInterface::hsCode()` carries the complete normalisation result so later rules can tell the difference between:

* missing input
* safely repaired input
* invalid input

The resolver should only have to deal with the tariff meaning of a usable code.

## The local mirror

`CommodityRepositoryInterface` represents local nomenclature data.

It is read-only from the domain's point of view.

```text
CommodityResolver
       |
       v
CommodityRepositoryInterface
       |
       v
Host storage
```

The package does not define a repository method such as:

```php
saveCommodity()
```

because synchronisation and persistence belong to the host.

This separation makes the same resolver usable over:

* Magento tables
* a conventional SQL schema
* SQLite
* an in-memory fixture

without changing classification logic.

## Why resolution is local

`CommodityResolver` does not use the tariff provider.

That is an important part of the design.

Resolution is expected to be:

* fast
* deterministic for a given mirror
* usable offline
* cheap across large catalogues

If every product required an HMRC request just to determine whether its code existed, catalogue scanning would become dependent on network availability and product count.

Instead:

```text
product
   |
   v
local mirror
   |
   v
resolution
```

and only then, where useful:

```text
resolved commodity
   |
   v
live tariff provider
```

## Resolution is richer than found/not found

A local tariff mirror can be incomplete.

A commodity code can also refer to a grouping line rather than a final declarable line.

For that reason resolution is represented by `Resolution` and `ResolutionOutcome`, rather than a nullable `Commodity`.

Conceptually:

```text
                    +--> resolved
                    |
normalised code ----+--> ambiguous
                    |
                    +--> not in mirror
                    |
                    +--> mirror incomplete
                    |
                    +--> dead end / other inconclusive state
```

This prevents several very different situations from collapsing into:

```php
$commodity === null
```

A rule can then distinguish between a genuine problem and a situation that could not be checked properly.

## Ambiguous classification

Shorter headings and subheadings may expand to several declarable lines.

The resolver works down the local nomenclature tree to produce those candidates.

```text
620140
   |
   +-- 6201401010
   |
   +-- 6201401090
   |
   +-- ...
```

Where product measurements provide enough information, candidates may be narrowed.

This logic belongs to tariff resolution rather than to a UI.

A Magento integration, CLI application and another platform should all reach the same classification result from the same product data.

## Measured properties

Classification is not always decided by code hierarchy alone.

Tariff branches may depend on properties such as:

* net weight
* volume
* alcoholic strength
* fat content
* protein content
* sugar content
* starch content
* dry matter

These are exposed through:

```php
ProductCustomsDataInterface::measuredProperties()
```

rather than creating a new fixed accessor for every property the tariff might ever use.

The map is open:

```php
[
    'net_weight' => 1.5,
    'alcoholic_strength' => 12.5,
]
```

Well-known keys are provided through `MeasuredProperty`.

The important rule during narrowing is conservative evaluation:

> Missing information must not be treated as evidence that a candidate is false.

If a classification depends on alcoholic strength and the product does not record alcoholic strength, that candidate remains possible.

An ambiguous answer is preferable to a confidently wrong classification.

## The provider boundary

Live tariff access goes through:

```php
TariffProviderInterface
```

The interface provides access to:

* a commodity and its measures
* complete chapter data
* tariff changes
* certificate metadata
* historic commodity records
* quotas
* jurisdiction
* service availability

The provider owns communication with the tariff service.

It does not own product scanning.

## Date-aware tariff data

Tariff data changes over time.

For that reason provider lookups support an `asOf` date.

Conceptually:

```text
commodity code + jurisdiction + date
                  |
                  v
             tariff answer
```

rather than:

```text
commodity code
      |
      v
permanent answer
```

This matters for:

* withdrawn codes
* future expiries
* changed measures
* historic verification
* reproducible tests

Where the caller does not supply a date, the current clock is used.

## Time is injected where it affects behaviour

Rules themselves should not call:

```php
new DateTimeImmutable()
```

to decide what "now" means.

The evaluation date is supplied through `EvaluationContext`.

The provider and scanner also support clock abstractions where current time affects lookup behaviour.

This makes time-dependent behaviour testable.

For example, a `code_expiring_soon` test can use a fixed date instead of relying on whichever day PHPUnit happens to run.

## Evaluation context

`EvaluationContext` contains information that does not belong to the product itself.

Conceptually:

```text
EvaluationContext
├── evaluation date
├── RuleSettings
├── Resolution
├── HistoricRecord
├── CommodityDetail
├── CertificateIndex
└── QuotaSet
```

The split is useful because product facts and tariff facts have different lifetimes.

For example:

```text
Product
  country of origin = VN

Tariff
  additional duty applies to RU and BY
```

The rule needs both.

Neither piece of data belongs inside the other object.

## Rules do not perform I/O

`RuleInterface` has one important architectural constraint:

> A rule evaluates information. It does not go looking for information.

A rule should not:

```php
$this->repository->load(...);
$this->httpClient->send(...);
$this->clock->now();
$this->config->get(...);
```

Instead everything required for the decision arrives through:

```php
ProductCustomsDataInterface
EvaluationContext
```

This makes rules:

* predictable
* independently testable
* cheap to run
* reusable across platforms

It also keeps network behaviour under the scanner's control, where calls can be deduplicated.

## Rule identity

Each rule has a stable code:

```php
missing_origin
unknown_code
prohibited_goods
```

The code is more than a class name.

A host may use it for:

* stored scan results
* grid filters
* dashboard counts
* notification thresholds
* severity configuration
* disabling checks
* reporting
* remediation workflows

Changing a rule code after it has been persisted should therefore be treated more like a data migration than a normal PHP rename.

## Rule prerequisites

Some checks only make sense after others succeed.

For example:

```text
missing_hs_code
       |
       v
invalid_code_format
       |
       v
unknown_code
       |
       v
ambiguous_expansion
```

Running every rule independently can create several secondary issues from one broken input.

`RuleInterface::prerequisites()` lets a rule declare which earlier checks must pass before it is useful.

If a prerequisite emits an issue, the dependent rule is skipped.

Skipping can cascade.

This gives a result closer to what the user actually needs to fix rather than reporting every consequence of the same bad input.

### Disabled rules

A rule disabled through configuration counts as passing for prerequisite purposes.

Otherwise disabling one check could unintentionally disable every downstream rule.

That would turn a presentation/configuration choice into a hidden change in evaluation behaviour.

## Rule settings vs scan options

The project has two configuration objects that answer different questions.

### `RuleSettings`

Answers:

> What does this merchant consider a problem?

Examples:

* disabled rules
* severity overrides
* stale-verification age
* vague description terms
* textile composition chapters
* recognised origins
* trade direction
* quota warning threshold

### `ScanOptions`

Answers:

> What work is this scan allowed to perform?

Examples:

* fetch measures
* fetch quotas
* perform historic lookups
* stop after repeated provider failures

Keeping those separate prevents an operational decision from changing customs policy.

For example:

```php
fetchQuotas: false
```

means:

> We are not checking quota balances in this run.

It does not mean:

> Exhausted quotas are acceptable.

## ProductScanner

`ProductScanner` is the main orchestration layer.

For each product, its current flow is roughly:

```text
ProductCustomsDataInterface
           |
           v
     hsCode()
           |
           v
   CommodityResolver
           |
           v
       Resolution
           |
           +------------------------------+
           |                              |
      missing code?                  resolved code?
           |                              |
           v                              v
 optional historic lookup        optional commodity detail
                                          |
                                          v
                                    optional quotas
                                          |
                                          v
                                  certificate index
           \                              /
            \                            /
             +------------+-------------+
                          |
                          v
                 EvaluationContext
                          |
                          v
                      RulePool
                          |
                          v
                 ProductScanResult
```

The scanner decides whether each remote lookup can contribute useful information.

This is different from letting every rule fetch whatever it wants.

## Network calls are demand-driven

The scanner does not blindly call every provider method for every product.

For example, commodity measures are only useful when resolution produced one declarable commodity.

If resolution is ambiguous:

```text
620140
  |
  +-- candidate A
  +-- candidate B
```

there is no single commodity whose measures represent the product yet.

Fetching one would mean choosing a classification before classification is complete.

Likewise, a code genuinely absent from the mirror has no current commodity detail to fetch.

A historic lookup may be useful there instead.

Network work follows the state of resolution.

## Lookup deduplication

Provider calls are cached inside the scanner for its lifetime.

The important key is the **resolved commodity code**, not the merchant's original formatting.

These inputs:

```text
620130
6201.30
6201300000
```

may ultimately resolve to the same declarable commodity.

Once resolved, they should share the same provider lookup.

Conceptually:

```text
Product A ----\
Product B -----+--> resolved 6201300000 --> one HMRC lookup
Product C ----/
```

This matters much more than caching by product.

A large catalogue may contain thousands of products but only hundreds of distinct customs classifications.

## Certificate lookup is scan-wide

Certificate metadata is different again.

The tariff exposes a certificate listing that can be reused across controls and products.

`ProductScanner` therefore attempts to fetch the certificate index at most once during a scan.

It is not fetched once per product or once per certificate code.

If certificate descriptions cannot be fetched, the failure reduces readability but does not invalidate the underlying tariff measure.

For that reason the scanner logs that failure rather than marking every affected product incomplete.

## Provider failures

A provider outage should not throw away work that did not require the provider.

Suppose a product already has:

```text
missing_origin
vague_description
```

and the live measure request fails.

Those two findings are still valid.

The scanner therefore returns the offline evaluation and records the provider failure on `ProductScanResult`.

```text
ProductScanResult
├── evaluation
├── resolution
├── measuresFetched
└── providerFailure
```

`isIncomplete()` tells the host that the product's complete state is not known.

This distinction is important:

```text
no issues + complete
```

means something different from:

```text
no issues + incomplete
```

The first is clean according to the configured scan.

The second still has unanswered checks.

## Repeated provider failures

One failed request can be temporary.

A long sequence of failures probably means the remote service is unavailable.

`ScanOptions::$maxProviderFailures` allows the scanner to stop after repeated consecutive failures rather than producing a whole-catalogue report where most live checks were never performed.

Successful provider calls reset the consecutive failure count.

The host still decides what to do when the scan stops:

* retry the job later
* alert an administrator
* leave the previous completed result in place
* mark the scan run failed

## Scanner lifetime

A scanner is intended to live for one scan or one scan batch.

Its in-memory caches are scoped accordingly.

```text
new ProductScanner
      |
      +-- detail cache
      +-- quota cache
      +-- historic cache
      +-- certificate cache
      +-- summary
      |
      v
scan iterable
      |
      v
discard scanner
```

A host processing a very large catalogue in queue batches can create a scanner per batch.

The HMRC provider's PSR cache can continue to provide reuse beyond the lifetime of an individual scanner.

## ScanSummary

`ScanSummary` collects totals as products are yielded.

It keeps product counts and issue counts separate.

That distinction matters.

One product may have three issues:

```text
Products with issues: 1
Issues:               3
```

The summary also tracks:

* incomplete products
* issues by severity
* issues by rule
* distinct commodity codes
* provider calls
* provider failures
* approximate calls saved through deduplication

These are domain scan statistics.

The host decides how or whether to present them.

## Jurisdiction

Jurisdiction is explicit throughout tariff-facing components.

A local repository declares:

```php
$repository->jurisdiction();
```

A provider declares:

```php
$provider->jurisdiction();
```

The host should compare them before performing live checks.

```text
Great Britain repository
          +
Northern Ireland provider
          =
configuration error
```

The response structures alone are not a safe way to detect the mismatch.

Provider cache keys are also scoped by jurisdiction so data from one tariff cannot be reused accidentally for another.

## Caching layers

There are two useful cache scopes.

### Scanner cache

Short-lived, in-memory and tied to one scan.

Used for:

* commodity detail
* quotas
* historic lookups
* certificate data

Its purpose is deduplication within the current catalogue run.

### Provider cache

Optional PSR cache supplied to `HmrcClient`.

Its lifetime can span:

* multiple scanners
* queue batches
* HTTP requests
* CLI commands

Its purpose is avoiding repeated remote retrieval of tariff data that is still fresh.

These layers solve different problems and can be used together.

## Cache failure is not tariff failure

Caching is an optimisation.

If the PSR cache throws while reading or writing, `HmrcClient` logs the problem and continues with the tariff request.

Conceptually:

```text
cache unavailable
      |
      v
lose optimisation
```

not:

```text
cache unavailable
      |
      v
pretend HMRC is unavailable
```

This keeps infrastructure failures isolated to the responsibility that actually failed.

## HTTP implementation

`HmrcClient` depends on:

```php
Psr\Http\Client\ClientInterface
Psr\Http\Message\RequestFactoryInterface
```

rather than Guzzle or another concrete client.

This is part of the framework boundary.

The application may use Guzzle internally if it wants to, but `davix/customs-domain` only sees the PSR contracts.

The same applies to:

```php
Psr\SimpleCache\CacheInterface
Psr\Log\LoggerInterface
```

## HMRC retry behaviour

The HMRC provider distinguishes errors that may benefit from retrying from errors where retrying would not help.

Transient responses can be retried with exponential backoff.

Where HMRC supplies `Retry-After`, that instruction takes priority within the configured maximum delay.

Jitter can be applied to normal retries to avoid many installations retrying at exactly the same moment.

This behaviour belongs in the provider.

Neither rules nor platform integrations should have to reimplement it.

## Historic lookups

A code absent from the current mirror may be:

* mistyped
* never valid
* previously valid but withdrawn

The scanner can perform a historic provider lookup for a conclusive `NotInMirror` result.

It does not perform the historic lookup where the local mirror itself is known to be incomplete.

For example:

```text
code absent
+
chapter present
=
historic lookup can be useful
```

whereas:

```text
code absent
+
chapter not mirrored
=
current result is already inconclusive
```

A remote historic request would not fix the underlying mirror problem.

## Messages are output, not presentation

Issues contain:

```text
rule code
severity
message key
context
```

They do not contain framework-translated final UI text as their primary contract.

The host owns presentation.

```text
Issue
  |
  +--> Magento translator
  |
  +--> CLI formatter
  |
  +--> API response mapper
  |
  +--> another application's translator
```

This avoids making the domain package inherit one application's language or UI conventions.

## What belongs in the host

The host application is responsible for:

### Product storage

Loading catalogue products and mapping them to `ProductCustomsDataInterface`.

### Tariff mirror persistence

Creating tables, writing synced commodities and maintaining chapter state.

### Configuration

Reading application or merchant settings and constructing:

```php
RuleSettings
ScanOptions
HmrcClientOptions
```

### Job orchestration

Deciding:

* when scans run
* batch size
* queue behaviour
* retries at job level
* cancellation
* progress
* locking

### Result persistence

Deciding how long scan results are retained and how they relate to products.

### Translation

Turning issue message keys and context into user-facing messages.

### User interface

Presenting:

* product issues
* dashboard totals
* blocking conditions
* opportunities
* incomplete checks
* suggested remediation

None of these responsibilities need to be the same across consuming platforms.

## What belongs in the domain

The package owns behaviour that should remain consistent across hosts:

### Commodity normalisation

The same damaged merchant value should be interpreted the same way everywhere.

### Classification resolution

The same mirror and product measurements should produce the same candidates.

### Tariff mapping

The same HMRC response should produce the same domain objects.

### Compliance rules

The same product, tariff data and settings should produce the same issues.

### Scan lookup strategy

A platform should not accidentally make 5,000 provider calls where another integration makes 200 for the same catalogue.

### Failure meaning

An outage, incomplete mirror and genuinely unknown code should remain distinct states.

## Adding a rule

A normal new customs check should require:

1. a class implementing `RuleInterface`
2. registration in the relevant rule set

For example:

```php
final class ExampleRule implements RuleInterface
{
    public const CODE = 'example_rule';

    public function code(): string
    {
        return self::CODE;
    }

    public function severity(): Severity
    {
        return Severity::Attention;
    }

    public function prerequisites(): array
    {
        return [];
    }

    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): ?Issue {
        // ...
    }
}
```

Then register it in the appropriate collection.

If the rule can evaluate entirely from product/local data, it belongs with offline checks.

If it requires tariff measures or other provider data already represented by `EvaluationContext`, it belongs with measure-dependent checks.

A rule should not add its own HTTP client or repository lookup.

If it needs new external information, add that information to the orchestration/context layer first.

## Adding product information

If a new rule needs a well-defined piece of product information, consider whether it belongs in:

```php
ProductCustomsDataInterface
```

or:

```php
measuredProperties()
```

Physical tariff measurements usually fit the measured-property map.

A broader semantic product value may deserve an explicit interface method.

Avoid adding a generic escape hatch such as:

```php
get(string $attribute): mixed
```

because that would recreate the host product model inside the domain boundary.

## Adding provider information

If multiple rules need data the tariff provider can supply:

1. represent it as a domain value
2. add the capability to `TariffProviderInterface`
3. map it in the HMRC implementation
4. fetch it in the scanner where useful
5. carry it through `EvaluationContext`
6. let rules evaluate the prepared value

The dependency should remain:

```text
Provider
   |
   v
Scanner
   |
   v
EvaluationContext
   |
   v
Rule
```

not:

```text
Rule
   |
   v
Provider
```

## Adding a new platform integration

A new consuming platform should normally need adapters rather than changes to this package.

At minimum it needs:

```text
Platform product
      |
      v
ProductCustomsDataInterface

Platform tariff tables
      |
      v
CommodityRepositoryInterface

Platform configuration
      |
      v
RuleSettings / ScanOptions / HmrcClientOptions

ProductScanResult
      |
      v
Platform persistence / UI
```

If adding another platform repeatedly requires framework checks inside `davix/customs-domain`, the boundary has probably been crossed in the wrong direction.

## Testing strategy

The architecture is designed to keep most tests away from live services.

### Rule tests

Use plain product and context fixtures.

No database.

No HTTP.

No real clock.

### Resolver tests

Use an in-memory or fixture repository.

Test:

* declarable codes
* grouping lines
* ambiguous expansions
* measured-property narrowing
* missing chapters
* malformed hierarchy data

### Provider tests

Use recorded HTTP responses and PSR test doubles.

Test mapping, retry and failure behaviour separately from the rules.

### Scanner tests

Combine:

* product fixtures
* local repository fixtures
* recorded provider behaviour
* fixed clock
* known rules

Then verify:

* results
* lookup counts
* deduplication
* incomplete status
* summary totals
* failure limits

### Host integration tests

Keep these focused on adapters:

* product field mapping
* repository persistence
* configuration mapping
* result storage
* translation

There should be little need to retest the complete customs rule set inside every host application.

## Things this package should not become

It is useful to be explicit about what is outside the project's scope.

### A product repository

It should not load catalogue products.

### A tariff database layer

It defines the read contract required by resolution, but it should not force every host to use the same SQL schema.

### A scheduler

It should not decide when scans happen.

### A queue system

It should not own workers, batches or retries for application jobs.

### A UI package

It should not render dashboards or admin grids.

### A translation package

It should return stable message information and let the host choose wording.

### An HTTP-client framework

It should use PSR abstractions and leave concrete infrastructure to the application.

Keeping those boundaries narrow is what makes the domain logic reusable.

## Design principles

A few principles are useful when deciding where new code should live.

### Prefer an explicit unknown over a confident guess

This applies to:

* damaged commodity codes
* incomplete mirrors
* missing measurements
* provider failures
* tariff conditions that cannot be interpreted safely

Customs mistakes can have real consequences. An inconclusive result is often the correct result.

### Keep I/O at the edges

Rules and resolution should work on prepared data.

Repositories and providers define the points where storage and network access enter.

### Preserve the reason something could not be checked

Do not collapse:

```text
not found
not mirrored
provider unavailable
ambiguous
invalid
```

into one generic failure.

The difference affects what the user should do next.

### Deduplicate expensive work centrally

If several rules need the same tariff data, fetch it once before evaluation.

If several products resolve to the same commodity, reuse the provider result.

### Keep host policy explicit

Merchant configuration arrives as values.

Do not read hidden global settings from inside domain objects.

### Keep rule output machine-readable

Stable codes and context make persistence, filtering, translation and automation possible.

### Make time an input

Where a result depends on the date, pass the date or clock in rather than reading global time from deep inside the decision.

## Further reading

* [README](../README.md) - package overview and examples
* [Integration](INTEGRATION.md) - wiring the package into an application
* [Tariff notes](TARIFF-NOTES.md) - tariff behaviours and edge cases found during development
* [Changelog](../CHANGELOG.md) - release history
