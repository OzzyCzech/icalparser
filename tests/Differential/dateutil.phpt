<?php
declare(strict_types=1);

/**
 * Differential test against python-dateutil (https://github.com/dateutil/dateutil).
 *
 * Random rules in several timezones are expanded by both implementations. Rules touching
 * known deviations of dateutil from RFC 5545 are skipped:
 * - DTSTART that does not match the rule (RFC 5545: it is the first instance; dateutil drops it),
 * - BYDAY mixing weekdays with and without an ordinal (dateutil requires both),
 * - BYSETPOS with WEEKLY and finer frequencies (dateutil applies it to the first week only),
 * - negative BYWEEKNO (dateutil does not map it to the adjacent year),
 * - BYWEEKNO 52 and 53 (dateutil assigns the first days of a year to the wrong ISO week: 2022-01-01
 *   is in week 52 of 2021, dateutil treats it as week 53).
 *
 * dateutil returns nonexistent local times of DST gaps; they are resolved as RFC 5545 requires
 * (with the offset before the gap) by the helper script.
 *
 * Needs Python 3 with dateutil: set ICALPARSER_PYTHON to its interpreter, e.g.
 *     python3 -m venv .venv && .venv/bin/pip install python-dateutil
 *     ICALPARSER_PYTHON=.venv/bin/python composer test:differential
 */

use om\RRule\Expander;
use om\RRule\Rule;
use Tester\Assert;
use Tester\Environment;

require_once __DIR__ . '/../bootstrap.php';

$python = getenv('ICALPARSER_PYTHON') ?: 'python3';
exec(escapeshellarg($python) . ' -c "import dateutil" 2>/dev/null', $output, $status);
if ($status !== 0) {
	Environment::skip("Python with dateutil is not available ($python), set ICALPARSER_PYTHON.");
}

mt_srand(8601);
$pick = static fn(array $values): mixed => $values[array_rand($values)];
$zones = ['UTC', 'Europe/Prague', 'America/New_York', 'Australia/Lord_Howe', 'America/Santiago', 'Asia/Kolkata'];
$cases = [];
for ($i = 0; $i < 1500; $i++) {
	$freq = $pick(['YEARLY', 'MONTHLY', 'WEEKLY', 'DAILY', 'HOURLY', 'MINUTELY']);
	$parts = ['FREQ' => $freq];
	if (mt_rand(0, 2) === 0) {
		$parts['INTERVAL'] = mt_rand(2, 5);
	}
	$ordinals = in_array($freq, ['MONTHLY', 'YEARLY'], true) && mt_rand(0, 1) === 0;
	$generators = [
		'BYMONTH' => fn() => implode(',', array_unique([mt_rand(1, 12), mt_rand(1, 12)])),
		'BYMONTHDAY' => fn() => $pick(['1', '15', '-1', '1,15', '31', '-3,10', '29']),
		'BYYEARDAY' => fn() => $pick(['1', '100', '-1', '1,200', '366']),
		'BYWEEKNO' => fn() => $pick(['1', '20', '1,26', '10,40']),
		'BYDAY' => fn() => $ordinals ? $pick(['1MO', '-1FR', '2TU,-2TH', '3WE', '20MO']) : $pick(['MO', 'TU,TH', 'SA,SU', 'MO,TU,WE,TH,FR']),
		'BYHOUR' => fn() => $pick(['9', '9,17', '0,12']),
		'BYMINUTE' => fn() => $pick(['0', '15,45']),
		'BYSECOND' => fn() => $pick(['0', '30']),
		'BYSETPOS' => fn() => $pick(['1', '-1', '2', '-2']),
		'WKST' => fn() => $pick(['MO', 'SU', 'TH']),
	];
	foreach ($generators as $name => $generate) {
		if (mt_rand(0, 4) === 0) {
			$parts[$name] = $generate();
		}
	}
	if (isset($parts['BYSETPOS']) && !in_array($freq, ['MONTHLY', 'YEARLY'], true)) {
		unset($parts['BYSETPOS']);
	}
	$timezone = new DateTimeZone($pick($zones));
	$start = (new DateTimeImmutable('@' . mt_rand(946684800, 1893456000)))->setTimezone($timezone)->setTime(mt_rand(0, 23), $pick([0, 15, 30]));
	$days = match ($freq) {
		'MINUTELY' => 2,
		'HOURLY' => 30,
		default => mt_rand(200, 3000),
	};
	$parts['UNTIL'] = $start->modify("+$days days")->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
	$rule = implode(';', array_map(fn($name, $value) => "$name=$value", array_keys($parts), $parts));
	$cases[] = ['rule' => $rule, 'start' => $start->format('Y-m-d\TH:i:s'), 'tz' => $timezone->getName(), 'take' => 150];
}

$process = proc_open([$python, __DIR__ . '/dateutil_expand.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], json_encode($cases, JSON_THROW_ON_ERROR));
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
Assert::same(0, proc_close($process), $errors);
$expected = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);

$compared = $oracleErrors = 0;
$mismatches = [];
foreach ($cases as $index => $case) {
	if ($expected[$index] === null) {
		$oracleErrors++;
		continue;
	}
	$start = new DateTimeImmutable($case['start'], new DateTimeZone($case['tz']));
	$ours = [];
	foreach (new Expander(Rule::fromString($case['rule']), $start) as $timestamp) {
		$ours[] = $timestamp;
		if (count($ours) >= 151) {
			break;
		}
	}
	$theirs = $expected[$index];
	// RFC 5545: DTSTART is always the first instance, dateutil returns it only when it matches
	if ($theirs === [] || $theirs[0] !== $ours[0]) {
		array_shift($ours);
	}
	$ours = array_slice($ours, 0, 150);
	$compared++;
	if ($ours !== $theirs) {
		$mismatches[] = sprintf('%s from %s %s', $case['rule'], $case['start'], $case['tz']);
	}
}
Assert::same([], array_slice($mismatches, 0, 20), sprintf('%d of %d rules differ from dateutil', count($mismatches), $compared));
Assert::true($compared > 1400, "$compared rules compared, dateutil failed on $oracleErrors");
echo "$compared rules match dateutil, dateutil failed on $oracleErrors\n";
