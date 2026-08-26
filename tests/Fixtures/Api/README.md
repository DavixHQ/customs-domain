# Recorded API responses

Real responses from the UK Trade Tariff API, captured 22 August 2026 and kept
verbatim. Tests replay these; nothing in the suite contacts the live service.

| File | Endpoint |
|---|---|
| `commodity-6201401019.json` | `/commodities/6201401019` — men's cotton parka, over 1 kg |
| `commodity-9301100000.json` | `/commodities/9301100000` — artillery weapons, heavily controlled |
| `changes.json` | `/changes/{date}` — the daily changes feed |

The three chapters were chosen because they branch on entirely different
things. Apparel splits on garment weight, dairy on fat content, beverages on
alcoholic strength - and a quantity parser that reads only mass works for the
first and does nothing for the other two. Coverage is asserted against them
directly, so a pattern that stops matching shows up as a failing count rather
than as quietly worse narrowing.

Both commodities were chosen to exercise different parts of the mapper. The
parka carries ordinary duty, a tariff preference, a quota, VAT with a zero-rate
option and a trade defence flag. The artillery weapons carry an import
prohibition, licence requirements, and export controls.

## Do not tidy these

Keep them byte-for-byte as they arrived. Several details in the real payloads
are surprising enough that a "cleaned up" fixture would quietly stop testing
the thing that matters:

- The field is `producline_suffix`, missing the 'd'. This is not a typo in our
  code and must not be corrected here.
- `included` is a flat array of twenty different resource types. Resolution is
  by `(type, id)` pair, not by position or by type alone.
- `data.meta.duty_calculator` sits under `data`, not at the document root.
- `excluded_countries` on a measure are references to `geographical_area`
  resources keyed by country code, while the measure's own area is a numeric
  group id. Both appear in `included`.
- Prohibition action codes are `09`, `06` and `05` not `09` alone.
- Document codes in the `90xx` range are national exemption statements paired
  with "Import allowed" actions, not licence requirements.

## Editor-wide find and replace

These files are excluded from any repository-wide character substitution. They
are recorded API responses, not prose, and their value depends on matching what
the service actually sent.

Two characters matter more than they look:

- `chapter-62.csv` contains 19 non-breaking spaces, U+00A0, between a quantity
  and its unit. The weight parser normalises them precisely because PCRE will
  not treat them as whitespace, and a test asserts they are still present. A
  replace that turns them into ordinary spaces makes that test pass for the
  wrong reason and hides a real parsing hazard.
- `commodity-9301100000.json` contains em dashes inside `guidance_cds` text.
  Nothing asserts on those, so substituting them is harmless today, but the
  file has then drifted from the response it claims to record.

If a substitution has already run over them, refetch rather than trying to
reverse it.

## Refreshing them

The tariff changes, so a refreshed capture may legitimately differ. If a test
starts failing after a refresh, establish whether the tariff changed or the
mapper broke before editing either.

```bash
BASE=https://www.trade-tariff.service.gov.uk/api/v2
curl -s -H "Accept: application/vnd.hmrc.2.0+json" \
  "$BASE/commodities/6201401019?as_of=$(date +%Y-%m-%d)" \
  > commodity-6201401019.json
```
