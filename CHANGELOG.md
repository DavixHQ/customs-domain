# Changelog

Notable changes to `davix/customs-domain` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).

Until `1.0.0`, minor releases may include breaking changes to public interfaces.

## [Unreleased]

### Added

#### Validation

* Added `CodeNormaliser` for common commodity-code formatting problems including grouping characters, quotes, invisible whitespace, trailing `.0` and missing leading zeroes.
* Added explicit failures for values that cannot be recovered safely, including scientific notation and multi-value cells.
* Added commodity-code format and hierarchy helpers.

#### Tariff

* Added tariff commodity value objects and `CommodityRepositoryInterface`.
* Added `CommodityResolver` for resolving headings and subheadings against a local tariff mirror.
* Added support for ambiguous classifications and measured-property narrowing.
* Added resolution outcomes for resolved, ambiguous, unknown and inconclusive lookups.
* Added historic commodity records for identifying withdrawn codes.
* Added tariff measure, certificate, quota, duty and geographical-area models.

#### Product data

* Added `ProductCustomsDataInterface`.
* Added the default `ProductCustomsData` implementation.
* Added open measured-property support through `MeasuredProperty` and `measuredProperties()`.

#### Rules

* Added the rule engine, evaluation context, rule prerequisites and skip reasons.
* Added configurable rule severities and rule settings.
* Added offline checks for commodity codes, origin, descriptions, composition, weights and stale verification.
* Added tariff-measure checks for prohibitions, licences, additional duties, preferences, quotas, VAT, supplementary units, Meursing codes, entry prices and expiring commodity codes.

#### Provider

* Added `TariffProviderInterface`.
* Added the PSR-based HMRC tariff client.
* Added commodity, chapter, tariff change, certificate, historic-code and quota lookups.
* Added caching and retry handling, including `Retry-After` support and jittered backoff.

#### Scanning

* Added `ProductScanner`, `ProductScanResult`, `ScanOptions` and `ScanSummary`.
* Added provider lookup deduplication by resolved commodity code.
* Added scan-level certificate caching.
* Added offline scanning.
* Added provider-failure tracking and configurable failure limits.

#### Jurisdictions

* Added explicit Great Britain and Northern Ireland tariff support.
* Added jurisdiction-aware repositories, providers and cache keys.

#### Tooling

* Added CI across supported PHP versions.
* Added PHPStan at maximum level.
* Added framework-boundary checks.
* Added redundant-nullsafe checks.
* Added source character checks.

### Changed

* Replaced weight-only classification narrowing with general measured-property narrowing.
* Updated the Chapter 62 fixture to more closely represent the structure of live tariff data.

### Fixed

* Fixed European decimal commas in tariff quantity conditions being interpreted as thousands separators.
* Fixed negative tariff conditions being treated as unconditional prohibitions.
* Fixed licence detection relying on document-code prefixes.
* Fixed measured-property narrowing reading conditions from the wrong nomenclature level.
* Added cycle protection when walking malformed tariff parent relationships.

## Further information

Background on some of the less obvious tariff behaviours and fixes is kept in [docs/TARIFF-NOTES.md](docs/TARIFF-NOTES.md).

That document is intentionally separate from the changelog so useful implementation knowledge is retained without turning release history into a development diary.