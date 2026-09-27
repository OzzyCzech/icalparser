<?php
declare(strict_types=1);

/**
 * Property-based tests: random (seeded) rules must keep the invariants of a recurrence set.
 *
 * - occurrences are sorted and unique
 * - no occurrence is after UNTIL, COUNT is respected
 * - DTSTART is the first occurrence
 * - EXDATE removes the matching occurrence and nothing else
 * - a rule survives serialization and parsing
 */

use om\RRule\Expander;
use om\RRule\RecurrenceSet;
use om\RRule\Rule;
use Tester\Assert;

require_once __DIR__ . '/../../bootstrap.php';

mt_srand(5545);
$zones = ['UTC', 'Europe/Prague', 'America/New_York', 'Australia/Lord_Howe', 'America/Santiago', 'Asia/Kolkata'];
$frequencies = ['YEARLY', 'MONTHLY', 'WEEKLY', 'DAILY', 'HOURLY', 'MINUTELY'];
$pick = static fn(array $values): mixed => $values[array_rand($values)];
$list = static function (int $min, int $max, int $count, bool $noZero = false) use ($pick): string {
	$values = [];
	for ($i = 0; $i < $count; $i++) {
		do {
			$value = mt_rand($min, $max);
		} while ($noZero && $value === 0);
		$values[] = $value;
	}
	return implode(',', array_unique($values));
};

$checked = 0;
for ($i = 0; $i < 400; $i++) {
	$parts = ['FREQ' => $pick($frequencies)];
	if (mt_rand(0, 2) === 0) {
		$parts['INTERVAL'] = (string) mt_rand(1, 4);
	}
	$byParts = [
		'BYMONTH' => fn() => $list(1, 12, mt_rand(1, 3)),
		'BYMONTHDAY' => fn() => $list(-31, 31, mt_rand(1, 3), true),
		'BYDAY' => fn() => implode(',', array_unique(array_map(fn() => (mt_rand(0, 2) === 0 && in_array($parts['FREQ'], ['MONTHLY', 'YEARLY'], true) ? (string) $pick([1, 2, -1, -2]) : '') . $pick(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']), range(1, mt_rand(1, 3))))),
		'BYHOUR' => fn() => $list(0, 23, mt_rand(1, 3)),
		'BYMINUTE' => fn() => $list(0, 59, mt_rand(1, 2)),
		'BYSETPOS' => fn() => $list(-3, 3, 1, true),
		'BYYEARDAY' => fn() => $list(-366, 366, 2, true),
		'BYWEEKNO' => fn() => $list(-53, 53, 2, true),
	];
	foreach ($byParts as $name => $generate) {
		if (mt_rand(0, 4) === 0) {
			$parts[$name] = $generate();
		}
	}
	if (mt_rand(0, 3) === 0) {
		$parts['WKST'] = $pick(['MO', 'SU', 'SA']);
	}
	$zone = new DateTimeZone($pick($zones));
	$start = (new DateTimeImmutable('@' . mt_rand(946684800, 1893456000)))->setTimezone($zone)->setTime(mt_rand(0, 23), mt_rand(0, 3) * 15);
	$useCount = mt_rand(0, 1) === 0;
	$days = match ($parts['FREQ']) {
		'MINUTELY' => mt_rand(1, 3),
		'HOURLY' => mt_rand(1, 60),
		default => mt_rand(1, 400),
	};
	$until = $start->modify("+$days days");
	if ($useCount) {
		$parts['COUNT'] = (string) mt_rand(1, 30);
	} else {
		$parts['UNTIL'] = $until->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
	}

	$text = implode(';', array_map(fn($name, $value) => "$name=$value", array_keys($parts), $parts));
	$rule = Rule::fromString($text);
	$occurrences = [];
	foreach (new Expander($rule, $start, maxIterations: 200000) as $timestamp) {
		$occurrences[] = $timestamp;
		if (count($occurrences) > 500) {
			break;
		}
	}

	Assert::same($start->getTimestamp(), $occurrences[0], "DTSTART first: $text");
	$sorted = $occurrences;
	sort($sorted);
	Assert::same($sorted, $occurrences, "sorted: $text");
	Assert::same(array_values(array_unique($occurrences)), $occurrences, "unique: $text");
	if ($useCount) {
		Assert::true(count($occurrences) <= (int) $parts['COUNT'], "COUNT: $text");
	} else {
		Assert::true(end($occurrences) <= max($start->getTimestamp(), $until->getTimestamp()), "UNTIL: $text");
	}
	Assert::same(Rule::fromString($rule->toString())->toString(), $rule->toString(), "serialization: $text");

	if (count($occurrences) > 2) {
		$excluded = $occurrences[1];
		$set = iterator_to_array(new RecurrenceSet($start, $rule, exdates: [$excluded]), false);
		$expected = array_values(array_diff($occurrences, [$excluded]));
		Assert::same($expected, array_slice($set, 0, count($expected)), "EXDATE: $text");
	}
	$checked++;
}
Assert::same(400, $checked);
