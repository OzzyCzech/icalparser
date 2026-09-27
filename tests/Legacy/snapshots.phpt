<?php
declare(strict_types=1);

/**
 * Snapshot tests: every calendar in tests/cal is parsed and compared with tests/snapshots.
 *
 * Any change of the parser output shows up as a diff of a snapshot file.
 * Regenerate the snapshots after an intended change:
 *
 *     UPDATE_SNAPSHOTS=1 composer test
 */

use om\IcalParser;
use om\ParserOptions;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';
date_default_timezone_set('UTC');

const HEAD = 60;
const TAIL = 10;

function normalize(mixed $value): mixed {
	if ($value instanceof DateTimeInterface) {
		return $value->format('Y-m-d H:i:s ') . $value->getTimezone()->getName();
	}
	if ($value instanceof DateTimeZone) {
		return 'TZ ' . $value->getName();
	}
	if (is_array($value)) {
		return array_map(normalize(...), $value);
	}
	return $value;
}

function snapshot(string $file): string {
	$parser = new IcalParser(new ParserOptions(now: new DateTimeImmutable('2026-01-01T00:00:00Z')));
	try {
		$data = $parser->parseFile($file);
	} catch (Throwable $e) {
		return 'EXCEPTION ' . $e::class . ': ' . $e->getMessage() . "\n";
	}

	// recurrences are listed in the events section
	foreach ($data['VEVENT'] ?? [] as $i => $event) {
		if (isset($event['RECURRENCES'])) {
			$data['VEVENT'][$i]['RECURRENCES'] = count($event['RECURRENCES']) . ' dates';
		}
	}
	unset($data['_RECURRENCE_IDS'], $data['_RECURRENCE_COUNTERS_BY_UID']);

	$lines = [];
	foreach ($parser->getEvents()->sorted() as $event) {
		$lines[] = implode(' | ', [
			normalize($event['DTSTART'] ?? null) ?? '-',
			normalize($event['DTEND'] ?? null) ?? '-',
			$event['RECURRENCE_INSTANCE'] ?? '-',
			$event['RECURRENCE-ID'] ?? '-',
			$event['UID'] ?? '-',
			str_replace("\n", '\n', (string) ($event['SUMMARY'] ?? '-')),
		]);
	}
	$shown = count($lines) > HEAD + TAIL
		? [...array_slice($lines, 0, HEAD), sprintf('... %d events omitted ...', count($lines) - HEAD - TAIL), ...array_slice($lines, -TAIL)]
		: $lines;

	return "# data\n" . json_encode(normalize($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n"
		. sprintf("# events: %d, md5 %s\n", count($lines), md5(implode("\n", $lines)))
		. implode("\n", $shown) . "\n";
}

$update = (bool) getenv('UPDATE_SNAPSHOTS');
foreach (glob(__DIR__ . '/../Fixtures/Samples/*.ics') as $file) {
	$target = __DIR__ . '/snapshots/' . basename($file, '.ics') . '.txt';
	$actual = snapshot($file);
	if ($update) {
		@mkdir(dirname($target));
		file_put_contents($target, $actual);
		continue;
	}
	Assert::true(is_file($target), "Missing snapshot $target, run UPDATE_SNAPSHOTS=1 composer test");
	Assert::same(file_get_contents($target), $actual, basename($target));
}

if ($update) {
	Tester\Environment::skip('Snapshots were updated.');
}
