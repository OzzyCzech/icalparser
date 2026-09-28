# Test fixtures

Every `.ics` file below has a golden file `.expected.json` with the normalized output of
`om\ICal` (see `tests/Integration/fixtures.phpt`). Regenerate them after an intended change:

```shell
UPDATE_SNAPSHOTS=1 composer test:integration
```

| Directory | Content |
|-----------|---------|
| `RFC5545` | examples from RFC 5545, sections 3.8.5.3 and 4 |
| `RFC7986` | properties of RFC 7986 (COLOR, IMAGE, SOURCE, REFRESH-INTERVAL, CONFERENCE) with the examples of the RFC |
| `RFC9073` | VLOCATION components (and PARTICIPANT, kept as generic components) of RFC 9073 |
| `RFC9253` | LINK, RELATED-TO with the relation types and GAP of RFC 9253, CONCEPT and REFID |
| `Google`, `Apple`, `Outlook`, `Exchange`, `Nextcloud`, `Fastmail` | synthetic calendars following the export format of these programs (their typical properties, X- properties, VTIMEZONE styles and quirks) |
| `Broken` | damaged input for the permissive parser |
| `Regression` | bugs found in the past; every bug gets a fixture or a test |
| `Samples` | sample calendars of [ical.js](https://github.com/mozilla-comm/ical.js/tree/master/samples), tested with the new API (`tests/Integration/samples.phpt`) and with the deprecated `IcalParser` (`tests/Legacy/snapshots.phpt`) |
