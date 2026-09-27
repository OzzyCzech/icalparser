# Test fixtures

Every `.ics` file below has a golden file `.expected.json` with the normalized output of
`om\ICal` (see `tests/Integration/fixtures.phpt`). Regenerate them after an intended change:

```shell
UPDATE_SNAPSHOTS=1 composer test:integration
```

| Directory | Content |
|-----------|---------|
| `RFC5545` | examples from RFC 5545, sections 3.8.5.3 and 4 |
| `Google`, `Apple`, `Outlook`, `Exchange`, `Nextcloud`, `Fastmail` | synthetic calendars following the export format of these programs (their typical properties, X- properties, VTIMEZONE styles and quirks) |
| `Broken` | damaged input for the permissive parser |
| `Regression` | bugs found in the past; every bug gets a fixture or a test |
| `Samples` | sample calendars of [ical.js](https://github.com/mozilla-comm/ical.js/tree/master/samples) used by the tests of the array based `IcalParser` |
