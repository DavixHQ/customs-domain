# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

Until 1.0.0, minor version bumps may contain breaking changes to public
interfaces. `RuleInterface`, `EvaluationContext` and
`CommodityRepositoryInterface` are the ones most likely to move.

## [Unreleased]

### Added

#### Validation

- `CodeNormaliser` - repairs spreadsheet damage to commodity codes: dotted and
  spaced grouping, quoting, invisible whitespace, Excel's trailing `.0`, and
  leading zeroes stripped by numeric cell formatting. Returns a
  `NormalisationResult` carrying the raw input, the normalised code, the
  failure reason and the list of transformations applied, so a host can explain
  what it changed rather than altering merchant data silently.
- Refuses rather than guesses on scientific notation and multi-value cells.
  Excel destroys the trailing digits of a ten-digit code rendered as
  `6.20140102E+09`; producing a plausible wrong classification would be worse
  than reporting the problem.
- `CodeFormat` - validates shape and reports the hierarchy level, with
  truncation and padding helpers. A six-digit subheading is *valid* here and
  simply not declarable, which keeps format failures separate from
  classification work.
- `CodeLevel`, `FormatFailure`, `NormalisationFailure`, `Transformation` enums.

#### Tariff

- `Commodity` value object keyed on the goods nomenclature SID rather than the
  commodity code, because codes are not unique - the same code appears as both
  an intermediate grouping line and a declarable line.
- `CommodityRepositoryInterface` and an `InMemoryCommodityRepository` for
  tests and small consumers.
- `CommodityResolver` - matches a code against the mirror, tries the padded
  form for codes shorter than ten digits, walks the tree through intermediate
  lines, and narrows candidates by net weight.
- `Resolution` and `ResolutionOutcome`, separating "checked and wrong" from
  "could not check". An unmirrored chapter and a national chapter are both
  reported as inconclusive.
- `WeightCriterion` and `WeightCriterionParser` for the weight conditions the
  tariff expresses in prose.
- `HistoricRecord` - the result of a baseline lookup, which is what separates a
  withdrawn code from one that never existed.

#### Product

- `ProductCustomsDataInterface`, the contract a host implements over its own
  storage, plus a `ProductCustomsData` carrier for tests and simple consumers.
- The commodity code accessor returns a `NormalisationResult`, not a string, so
  rules can distinguish missing from unreadable and quote what the merchant
  actually typed.

#### Rules

- `RuleInterface`, `RulePool`, `Issue`, `Severity`, `RemediationHint`,
  `RuleEvaluation`, `SkipReason`, `RuleSettings`, `EvaluationContext`.
- Prerequisite declaration and short-circuiting, so one unreadable code
  produces one issue rather than five derived ones. Skipping cascades; a
  disabled prerequisite counts as passing.
- Configuration errors - duplicate codes, unknown prerequisites, self
  references and cycles - throw at pool construction rather than mid-scan.
- Merchant severity overrides applied by the pool, so rules never read config.
- Twelve offline checks: `missing_hs_code`, `invalid_code_format`,
  `withdrawn_code`, `unknown_code`, `ambiguous_expansion`, `missing_origin`,
  `origin_not_in_tariff_areas`, `vague_description`,
  `description_is_product_name`, `missing_composition`,
  `net_weight_exceeds_gross`, `stale_verification`.
- `DefaultRuleSet` factory for the standard offline set.

#### Provider

- `TariffProviderInterface` and `HmrcClient` over PSR-18, PSR-17, PSR-16 and
  PSR-3. Every request carries `as_of`, because the tariff is a function of a
  date and a lookup without one gives an answer that changes underneath you.
- Failures separated by whether retrying could help. A 404 means the code does
  not exist; a 429 or 5xx means ask later; a malformed body is a well-formed
  response that will not fix itself. Backoff is exponential with full jitter,
  and a `Retry-After` header takes precedence over computed delay.
- Caching that degrades rather than fails. A broken cache costs the
  optimisation, never the request.
- `CommodityMapper`, `ChapterCsvParser` and `ChangesMapper`, all written
  against recorded live responses.
- Chapter sync uses the CSV form: 64 KB against 221 KB of JSON for chapter 62,
  and the parent SID arrives as a column rather than a nested relationship.
- `Clock` and `Sleeper` injected, so `as_of` is an input rather than an ambient
  fact and backoff can be asserted without waiting.

#### Measures

- `Measure`, `MeasureSet`, `MeasureCondition`, `MeasureType`,
  `GeographicalArea`, `DutyExpression`, `MeasureComponent`,
  `DutyCalculatorFlags` and `CommodityDetail`.
- Nine measure-dependent checks: `prohibited_goods`, `licence_required`,
  `additional_duty_applies`, `preference_available`,
  `vat_zero_rating_available`, `missing_supplementary_units`,
  `meursing_code_required`, `entry_price_system`, `code_expiring_soon`.
- `TradeDirection` on `RuleSettings`. A single commodity carries import and
  export measures together and they are different restrictions.

#### Tooling

- `bin/check-boundary.sh` - fails the build if platform-specific code appears
  under `src/`. Fails loudly on a missing or empty target directory, so a typo
  cannot read as a clean result, and reports the file count checked.
- `bin/check-nullsafe.py` - catches redundant nullsafe operators with an
  explanation, ahead of PHPStan.
- CI across PHP 8.1 to 8.4, PHPStan at level max.

### Fixed

- **A negative condition was treated as a prohibition.** Nearly every control
  measure carries one reading "not allowed after control" - the branch taken
  when the required document is absent, not the measure's normal outcome.
  Chapter 62 garments carry several, so the first version of `prohibited_goods`
  reported every parka in a catalogue as unshippable. A prohibition now means
  measure series A, or a negative condition with no documentary route at all.
- **Licences were told from statements by their code prefix.** They cannot be.
  The firearms control lists 9020 "This product is exempt as it is not a
  firearm" beside 9023 "DBT Firearms Import License" - structurally identical,
  one a formality and one a licence. `licence_required` now names the control
  and lists every option that would satisfy it, and splits severity on whether
  a declaration suffices rather than guessing which document is which.
- **Weight narrowing read the wrong descriptions.** Candidates were matched
  against their own description, but the real nomenclature never puts a weight
  condition on a declarable line - the split at 1 kg per garment sits on a
  grouping line two levels above, and the candidates are "Parkas", "Other" and
  "Hand-printed by the batik method". Narrowing therefore did nothing at all on
  live data. Each candidate is now matched against the nearest weight condition
  found by walking its ancestors, stopping at the line the merchant's code
  matched, since anything at or above that point is shared by every candidate
  and cannot discriminate. Found by rebuilding the test fixture from a live
  chapter 62 response.
- **A cyclic parent reference could exhaust memory.** `declarableDescendantsOf`
  recursed without a visited set, so a corrupt mirror - which a partial or
  interrupted sync can produce - would loop until the process died, taking the
  whole scan with it. Both directions of the tree walk are now bounded: a
  visited set descending, a depth guard ascending.

### Changed

- The chapter 62 test fixture is transcribed from a live API response rather
  than invented, including real SIDs, productline suffixes, indent levels and
  descriptions. The invented version had weight conditions on declarable lines,
  which is the shape that hid the narrowing bug.

### Notes

- Rules emit translation keys and context arrays rather than sentences. This
  package has no locale and no framework; the host renders wording.
- `stale_verification` deliberately does not fire on never-verified products.
  On a first scan that would be the entire catalogue, burying the issues that
  need attention.
- `ambiguous_expansion` is never automatically fixable. Choosing between
  classifications has legal consequences and must not be guessed.
- Net weight halves a candidate set rather than resolving it. Under "Of cotton"
  eight declarable lines become four, and the merchant still chooses between a
  parka, a batik print and two kinds of other.
- `additional_duty_applies` is named for what it detects rather than for
  anti-dumping. The `trade_defence` flag on a cotton parka comes from a 35%
  duty on Russia and Belarus and sanctions on North Korea and Ukraine. The flag
  is commodity-level, so the rule filters by origin and uses the flag only to
  decide whether looking is worthwhile.
- Measure rules stay silent when no measures were fetched, so an offline scan
  is unaffected by their presence in the pool.