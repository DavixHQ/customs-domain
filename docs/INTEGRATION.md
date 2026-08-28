# Integration

This guide covers the parts a host application needs to provide in order to use `davix/customs-domain`.

The package contains the customs and tariff logic. Your application still owns its products, storage, configuration, scheduling and presentation.

A typical integration needs to provide:

* product customs data
* a local tariff mirror
* a tariff provider if live HMRC checks are required
* rule settings
* somewhere to persist or display scan results

## Installation

```bash
composer require davix/customs-domain
```

The package requires PHP 8.1 or later.

It uses PSR interfaces for HTTP, caching and logging rather than selecting implementations for you.

Your application will normally already have suitable implementations for:

* `Psr\Http\Client\ClientInterface`
* `Psr\Http\Message\RequestFactoryInterface`
* `Psr\SimpleCache\CacheInterface`
* `Psr\Log\LoggerInterface`

The cache and logger are optional.

## Integration overview

The usual flow looks like this:

```text
Your product
    |
    v
ProductCustomsDataInterface
    |
    v
CommodityResolver
    |
    v
CommodityRepositoryInterface
    |
    v
Resolution
    |
    +----------------------+
    |                      |
    | optional             |
    v                      |
TariffProviderInterface    |
    |                      |
    +----------+-----------+
               |
               v
        EvaluationContext
               |
               v
            RulePool
               |
               v
       ProductScanResult
               |
               v
      Your storage / UI
```

For most applications, `ProductScanner` handles the middle part of this flow.

You provide it with products, a resolver, rules and optionally a tariff provider. It returns one `ProductScanResult` for each product.

## 1. Expose product data

Rules never receive your framework's product object directly.

Instead, the host provides `ProductCustomsDataInterface`.

This keeps rules independent from Magento, an ORM, a database connection or any other platform service.

The interface currently contains:

```php
interface ProductCustomsDataInterface
{
    public function identifier(): string;

    public function sku(): string;

    public function name(): string;

    public function countryOfOrigin(): ?string;

    public function hsCode(): NormalisationResult;

    public function customsDescription(): ?string;

    public function netWeight(): ?float;

    public function grossWeight(): ?float;

    public function supplementaryQuantity(): ?float;

    public function composition(): ?string;

    public function intendedUse(): ?string;

    public function manufacturer(): ?string;

    public function verifiedAt(): ?DateTimeImmutable;

    /**
     * @return array<string, float>
     */
    public function measuredProperties(): array;
}
```

A framework integration would normally create a small adapter around its own product model.

For example:

```php
use Davix\Customs\Product\ProductCustomsDataInterface;
use Davix\Customs\Tariff\MeasuredProperty;
use Davix\Customs\Validation\CodeNormaliser;
use Davix\Customs\Validation\NormalisationResult;
use DateTimeImmutable;

final class ProductCustomsAdapter implements ProductCustomsDataInterface
{
    public function __construct(
        private readonly Product $product,
        private readonly CodeNormaliser $normaliser,
    ) {
    }

    public function identifier(): string
    {
        return (string) $this->product->getId();
    }

    public function sku(): string
    {
        return (string) $this->product->getSku();
    }

    public function name(): string
    {
        return (string) $this->product->getName();
    }

    public function countryOfOrigin(): ?string
    {
        return $this->product->getCountryOfOrigin();
    }

    public function hsCode(): NormalisationResult
    {
        return $this->normaliser->normalise(
            $this->product->getCommodityCode()
        );
    }

    public function customsDescription(): ?string
    {
        return $this->product->getCustomsDescription();
    }

    public function netWeight(): ?float
    {
        return $this->product->getNetWeight();
    }

    public function grossWeight(): ?float
    {
        return $this->product->getShippingWeight();
    }

    public function supplementaryQuantity(): ?float
    {
        return $this->product->getSupplementaryQuantity();
    }

    public function composition(): ?string
    {
        return $this->product->getComposition();
    }

    public function intendedUse(): ?string
    {
        return $this->product->getIntendedUse();
    }

    public function manufacturer(): ?string
    {
        return $this->product->getManufacturer();
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        return $this->product->getCustomsVerifiedAt();
    }

    public function measuredProperties(): array
    {
        $properties = [];

        if ($this->netWeight() !== null) {
            $properties[MeasuredProperty::NET_WEIGHT] = $this->netWeight();
        }

        return $properties;
    }
}
```

The example `Product` methods above belong to the host application. They are not part of this package.

The important part is the boundary: by the time the product reaches the rule engine, all framework-specific loading has already happened.

### Do not load data from inside the adapter

Ideally the adapter should expose data that is already available.

Avoid implementations where methods such as:

```php
$product->composition();
```

quietly trigger another database query.

A catalogue scan can call the interface many times, so hidden I/O quickly becomes expensive.

Prepare or preload the product data at the host level instead.

## 2. Normalise commodity codes

`ProductCustomsDataInterface::hsCode()` returns a `NormalisationResult`, not a plain string.

Use `CodeNormaliser` when adapting merchant data:

```php
use Davix\Customs\Validation\CodeNormaliser;

$normaliser = new CodeNormaliser();

$result = $normaliser->normalise(
    $merchantCommodityCode
);
```

This lets the rules distinguish between:

* no code supplied
* a valid code
* a code that was safely repaired
* a value that cannot be interpreted safely

Do not silently replace a failed normalisation with an empty string.

The distinction is useful when explaining the problem back to the merchant.

## 3. Implement the tariff mirror

Commodity resolution uses `CommodityRepositoryInterface`.

The repository is deliberately read-only:

```php
use Davix\Customs\Tariff\Commodity;
use Davix\Customs\Tariff\CommodityRepositoryInterface;
use Davix\Customs\Tariff\Jurisdiction;

interface CommodityRepositoryInterface
{
    public function jurisdiction(): Jurisdiction;

    public function findBySid(int $sid): ?Commodity;

    /**
     * @return list<Commodity>
     */
    public function findByCode(string $code): array;

    public function findDeclarable(string $code): ?Commodity;

    /**
     * @return list<Commodity>
     */
    public function childrenOf(int $parentSid): array;

    /**
     * @return list<Commodity>
     */
    public function declarableDescendantsOf(int $sid): array;

    public function hasChapter(string $chapter): bool;
}
```

Your implementation can sit over any storage you want:

* MySQL
* PostgreSQL
* SQLite
* Magento resource models
* Doctrine
* custom persistence
* an in-memory repository for tests

The domain package does not need to know which one is being used.

### Repository scope

One repository represents one tariff jurisdiction.

For example:

```php
final class MysqlCommodityRepository implements CommodityRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Jurisdiction $jurisdiction,
    ) {
    }

    public function jurisdiction(): Jurisdiction
    {
        return $this->jurisdiction;
    }

    // ...
}
```

If the application supports both Great Britain and Northern Ireland, construct a separate repository for each mirror.

Do not put both tariffs into one repository and switch them using a parameter on every lookup.

## 4. Populate the tariff mirror

The domain package provides tariff data through `TariffProviderInterface`, but it does not decide how your local mirror is stored or synchronised.

For a complete chapter:

```php
foreach ($provider->chapter('62', $asOf) as $commodity) {
    // Store or update $commodity using the host application's persistence.
}
```

The HMRC implementation also exposes `rawChapter()` when the host needs the original chapter response, for example to hash it before deciding whether a chapter needs to be re-imported.

Exactly how synchronisation works belongs to the consuming application.

A typical sync job might:

1. select the jurisdiction
2. fetch a chapter
3. compare it with the locally stored version
4. update the host's tariff tables
5. record that the chapter synced successfully
6. repeat for the remaining chapters

The important part from the domain's point of view is that `hasChapter()` is trustworthy.

If a chapter has not been successfully mirrored, return `false`.

That allows resolution to report an inconclusive result rather than incorrectly treating every code in that chapter as unknown.

## 5. Create the resolver

Once a repository is available:

```php
use Davix\Customs\Tariff\CommodityResolver;

$resolver = new CommodityResolver(
    $commodityRepository
);
```

The resolver only uses the local repository.

It does not call HMRC.

A direct lookup can then be performed with:

```php
$resolution = $resolver->resolve(
    '620140',
    [
        'net_weight' => 1.5,
    ],
);
```

In normal catalogue scanning you usually do not need to call the resolver yourself. `ProductScanner` takes care of it using the product's `measuredProperties()`.

## 6. Configure the HMRC provider

Live tariff checks use `TariffProviderInterface`.

The included HMRC implementation is `HmrcClient`.

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

The HTTP client and request factory are PSR implementations supplied by your application.

Caching and logging are optional.

Without a cache:

```php
$provider = new HmrcClient(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
);
```

### Client options

Provider behaviour is configured with `HmrcClientOptions`:

```php
$options = new HmrcClientOptions(
    maxAttempts: 3,
    baseDelaySeconds: 1.0,
    maxDelaySeconds: 30.0,
    commodityCacheTtl: 86400,
    chapterCacheTtl: 0,
    jitter: true,
    userAgent: 'example-store/1.0',
);
```

The default client already includes sensible retry behaviour.

Transient failures are retried, `Retry-After` is respected where supplied, and retry delays can use jitter.

### Great Britain

Great Britain is the default jurisdiction:

```php
$options = HmrcClientOptions::for(
    Jurisdiction::Uk
);
```

### Northern Ireland

For Northern Ireland:

```php
use Davix\Customs\Tariff\Jurisdiction;

$options = HmrcClientOptions::for(
    Jurisdiction::NorthernIreland
);
```

This selects the correct tariff service as well as recording the jurisdiction on the client.

## 7. Verify the jurisdiction

Before combining a local mirror with a remote provider, compare them:

```php
if ($repository->jurisdiction() !== $provider->jurisdiction()) {
    throw new RuntimeException(
        'Tariff repository and provider jurisdictions do not match.'
    );
}
```

This check is worth doing during application wiring rather than halfway through a scan.

Great Britain and Northern Ireland tariff responses have the same general structure, so using the wrong service can produce data that looks valid while belonging to the wrong tariff.

## 8. Configure rule settings

Merchant-specific behaviour is passed through `RuleSettings`.

```php
use Davix\Customs\Rule\RuleSettings;
use Davix\Customs\Rule\Severity;
use Davix\Customs\Tariff\TradeDirection;

$settings = new RuleSettings(
    staleVerificationMonths: 12,
    disabledRules: [],
    severityOverrides: [
        'missing_origin' => Severity::Blocked,
    ],
    expiryWarningDays: 60,
    recognisedOriginCodes: $recognisedOriginCodes,
    direction: TradeDirection::Import,
    quotaLowThreshold: 0.10,
);
```

The host owns the configuration store.

For example, a Magento module might read scoped configuration and construct `RuleSettings` from it.

The domain package never reads Magento configuration itself.

### Recognised origin codes

`recognisedOriginCodes` allows the `origin_not_in_tariff_areas` rule to compare a product's origin against geographical areas known by the local tariff data.

If the array is empty, the package limits itself to basic origin-code validation rather than treating the empty list as proof that every country is invalid.

The host should populate this list from its own mirror where possible.

## 9. Choose the rule set

For local checks only:

```php
use Davix\Customs\Rule\DefaultRuleSet;

$rules = DefaultRuleSet::pool();
```

For all standard rules:

```php
$rules = DefaultRuleSet::fullPool();
```

`fullPool()` includes checks that use tariff measures, quotas and other provider data.

Those rules remain silent when their required provider data is unavailable.

A host with its own dependency-injection setup can also register individual rules rather than using `DefaultRuleSet`.

## 10. Create a scanner

For a full scan:

```php
use Davix\Customs\Scan\ProductScanner;
use Davix\Customs\Scan\ScanOptions;

$scanner = new ProductScanner(
    rules: DefaultRuleSet::fullPool(),
    resolver: $resolver,
    provider: $provider,
    settings: $settings,
    options: new ScanOptions(),
    logger: $logger,
);
```

`ProductScanner` handles:

* commodity resolution
* measured-property narrowing
* historic lookups where useful
* commodity measure fetching
* quota fetching
* certificate fetching
* rule evaluation
* lookup deduplication
* provider failure tracking
* scan totals

One scanner should normally represent one scan or batch.

Its in-memory lookup caches live for the scanner's lifetime.

## 11. Supply products

`scan()` accepts any iterable of `ProductCustomsDataInterface` objects.

That means the host can stream products from its own storage:

```php
function products(): iterable
{
    foreach ($productRepository->iterateForCustomsScan() as $product) {
        yield new ProductCustomsAdapter(
            product: $product,
            normaliser: new CodeNormaliser(),
        );
    }
}
```

Then scan them:

```php
foreach ($scanner->scan(products()) as $result) {
    // Persist or display each result.
}
```

Because the scanner itself returns a generator, results do not need to be held in memory until the entire catalogue has finished.

## 12. Handle scan results

Each product returns a `ProductScanResult`.

Useful values include:

```php
$result->identifier;
$result->sku;

$result->resolution;
$result->evaluation;

$result->hasIssues();
$result->issueCount();
$result->highestSeverity();
$result->isBlocked();

$result->measuresFetched;
$result->providerFailure;
$result->isIncomplete();
```

A product with no issues is not necessarily the same thing as a product that was checked completely.

Always pay attention to `isIncomplete()` when live provider checks are enabled.

For example:

```php
if ($result->isIncomplete()) {
    // Some provider-dependent checks could not be completed.
}

if ($result->isBlocked()) {
    // At least one blocking rule fired.
}
```

### Persisting results

Persistence belongs to the host.

A useful stored result will normally include at least:

* product identifier
* SKU
* scan date
* resolution outcome
* resolved commodity code, if any
* issue rule codes
* issue severities
* issue message keys
* issue context
* whether provider-dependent checks completed
* provider failure information if applicable

Avoid storing only rendered English messages.

Rule codes and message keys are more useful long term because they remain machine-readable.

## 13. Use the scan summary

The scanner keeps running totals:

```php
$summary = $scanner->summary();

$summary->products();
$summary->productsWithIssues();
$summary->issues();
$summary->clean();
$summary->incomplete();

$summary->distinctCodes();

$summary->providerCalls();
$summary->providerFailures();
$summary->callsSaved();

$summary->byRule();
```

The summary can be read while the generator is still running, so a host can use it for progress reporting.

For example:

```php
foreach ($scanner->scan($products) as $result) {
    $resultRepository->save($result);

    $summary = $scanner->summary();

    $progress->update(
        processed: $summary->products(),
        issues: $summary->issues(),
    );
}
```

## 14. Run an offline scan

For a scan with no remote tariff calls:

```php
$scanner = new ProductScanner(
    rules: DefaultRuleSet::fullPool(),
    resolver: $resolver,
    provider: null,
    settings: $settings,
    options: ScanOptions::offline(),
);
```

Or use only the offline rule set:

```php
$scanner = new ProductScanner(
    rules: DefaultRuleSet::pool(),
    resolver: $resolver,
    settings: $settings,
    options: ScanOptions::offline(),
);
```

`ScanOptions::offline()` disables:

* commodity measure fetching
* quota fetching
* historic-code lookup

This works well for frequent catalogue-wide checks where the local mirror can answer most questions cheaply.

A separate live scan can then perform the more expensive checks when required.

## 15. Control network use

`ScanOptions` lets the host decide how much remote work a scan can perform.

```php
$options = new ScanOptions(
    fetchMeasures: true,
    fetchQuotas: false,
    resolveWithdrawnCodes: true,
    maxProviderFailures: 5,
);
```

The options describe scan behaviour rather than customs policy.

That distinction is deliberate:

* `RuleSettings` says **what counts as a problem**
* `ScanOptions` says **what the scan is allowed to fetch in order to find out**

For example, disabling quota fetching does not mean quotas are acceptable. It means quota-dependent checks cannot be completed during that scan.

## 16. Provider failures

Provider failures should normally be treated as an incomplete check rather than as proof that a product passed.

`ProductScanner` preserves offline findings when an HMRC request fails.

For example, a product may still report:

```text
missing_origin
vague_description
```

even if its remote measure lookup failed.

The result will also be marked incomplete.

This gives the host enough information to say:

```text
2 customs issues found.
Some live tariff checks could not be completed.
```

rather than incorrectly presenting the product as clean.

### Failure limit

`ScanOptions::$maxProviderFailures` controls how many consecutive provider failures are accepted before the scanner gives up.

The default is `10`.

Set it to `0` to disable the limit:

```php
$options = new ScanOptions(
    maxProviderFailures: 0,
);
```

For scheduled jobs, keeping a sensible failure limit is normally safer than continuing through an outage and producing a large incomplete report.

## 17. Render rule messages

Rules return message keys and context rather than finished English text.

For example:

```php
$issue->messageKey();
$issue->context;
```

The host translates the key using its own translation system:

```php
$message = $translator->translate(
    $issue->messageKey(),
    $issue->context,
);
```

This keeps the domain package independent from framework translation APIs and allows different hosts to choose their own wording.

It also means the same rule codes can be presented differently in:

* an admin grid
* a product editor
* an API
* an email
* a CLI command

## 18. Scheduling and queues

The package does not schedule itself.

The host decides whether a scan runs:

* when a product is saved
* from a CLI command
* from cron
* in a queue worker
* on demand from an admin screen
* as a nightly catalogue scan

The recommended split is:

```text
Host
    decides when work runs
    loads products
    handles batching
    handles progress
    handles cancellation
    persists results

davix/customs-domain
    resolves classifications
    decides which provider data is useful
    evaluates rules
    deduplicates lookups
    reports findings
```

## 19. Suggested application wiring

A simple application bootstrap can look roughly like this:

```php
use Davix\Customs\Provider\Hmrc\HmrcClient;
use Davix\Customs\Provider\Hmrc\HmrcClientOptions;
use Davix\Customs\Rule\DefaultRuleSet;
use Davix\Customs\Rule\RuleSettings;
use Davix\Customs\Scan\ProductScanner;
use Davix\Customs\Scan\ScanOptions;
use Davix\Customs\Tariff\CommodityResolver;
use Davix\Customs\Tariff\Jurisdiction;

$jurisdiction = Jurisdiction::Uk;

$repository = new AppCommodityRepository(
    database: $database,
    jurisdiction: $jurisdiction,
);

$provider = new HmrcClient(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    options: HmrcClientOptions::for($jurisdiction),
    cache: $cache,
    logger: $logger,
);

if ($repository->jurisdiction() !== $provider->jurisdiction()) {
    throw new RuntimeException(
        'Tariff mirror and provider jurisdiction mismatch.'
    );
}

$resolver = new CommodityResolver($repository);

$settings = new RuleSettings(
    recognisedOriginCodes: $originCodes,
);

$scanner = new ProductScanner(
    rules: DefaultRuleSet::fullPool(),
    resolver: $resolver,
    provider: $provider,
    settings: $settings,
    options: new ScanOptions(),
    logger: $logger,
);

foreach ($scanner->scan($products) as $result) {
    $resultStore->save($result);
}

$summary = $scanner->summary();
```

The classes beginning with `App...`, along with `$database`, `$products`, `$resultStore` and the PSR implementations, belong to the host application.

## Testing an integration

The package is designed so most host integration tests do not need the live HMRC service.

Useful test doubles include:

* an in-memory `CommodityRepositoryInterface`
* a recorded `TariffProviderInterface`
* simple `ProductCustomsDataInterface` fixtures
* a fixed clock where dates affect the result

A useful integration test should be able to create:

```text
product data
+ local tariff fixture
+ provider fixture
+ settings
----------------------
expected resolution
+ expected rule issues
```

without loading the host framework's complete application stack.

That is a good indication that the boundary is being kept clean.

## Common integration mistakes

### Passing platform models into domain code

Don't add Magento products, Doctrine entities or framework services to rule constructors.

Adapt them to `ProductCustomsDataInterface` first.

### Making repository methods call HMRC

`CommodityRepositoryInterface` represents the local mirror.

Its methods should not make remote requests.

Live tariff access belongs behind `TariffProviderInterface`.

### Treating a missing chapter as an unknown code

If the local mirror does not contain the chapter, `hasChapter()` should return `false`.

Do not pretend an incomplete mirror is complete.

### Mixing GB and NI data

Keep separate repositories and providers for each jurisdiction and compare them before scanning.

### Fetching tariff data per product

Use `ProductScanner` rather than writing a loop that calls `provider->commodity()` for every catalogue row.

The scanner already deduplicates lookups by resolved commodity code.

### Treating incomplete as clean

When remote checks fail, `ProductScanResult::isIncomplete()` matters even if no issues were found by the checks that did run.

### Storing rendered messages as the only result

Persist stable rule codes and context.

Render human-readable text at the edge of the application.

## Further reading

* [README](../README.md) - package overview and API examples
* [Architecture](ARCHITECTURE.md) - how the package is divided internally
* [Tariff notes](TARIFF-NOTES.md) - non-obvious behaviours in tariff data
* [Changelog](../CHANGELOG.md) - release history
