# Recorded API responses

Real responses from the UK Trade Tariff API, captured 22 August 2026 and kept
verbatim. Tests replay these; nothing in the suite contacts the live service.

| File | Endpoint |
|---|---|
| `commodity-6201401019.json` | `/commodities/6201401019` — men's cotton parka, over 1 kg |
| `commodity-9301100000.json` | `/commodities/9301100000` — artillery weapons, heavily controlled |
| `changes.json` | `/changes/{date}` — the daily changes feed |

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
- Prohibition action codes are `09`, `06` and `05` — not `09` alone.
- Document codes in the `90xx` range are national exemption statements paired
  with "Import allowed" actions, not licence requirements.

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
