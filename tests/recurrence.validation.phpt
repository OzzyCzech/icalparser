<?php
declare(strict_types=1);

use om\Freq;
use om\IcalParser;
use om\Recurrence;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('UTC');

test('String and array recurrence rules produce the same finite series', function () {
	$start = strtotime('2026-01-01T10:00:00Z');
	$expected = [$start, $start + 86400, $start + 172800];
	Assert::same($expected, (new Freq('FREQ=DAILY;COUNT=3', $start))->getAllOccurrences());
	Assert::same($expected, (new Freq('FREQ=DAILY;COUNT=03;INTERVAL=01', $start))->getAllOccurrences());
	Assert::same($expected, (new Freq(['FREQ' => 'DAILY', 'COUNT' => 3], $start))->getAllOccurrences());
	Assert::same($expected, (new Freq(['FREQ' => 'DAILY', 'UNTIL' => new DateTimeImmutable('2026-01-03T10:00:00Z')], $start))->getAllOccurrences());
});

test('Invalid recurrence inputs fail before expansion', function () {
	foreach (['FREQ', 'FREQ=', 'FREQ=UNKNOWN', 'FREQ=DAILY;INTERVAL=0', 'FREQ=DAILY;INTERVAL=-1', 'FREQ=DAILY;COUNT=0', 'FREQ=DAILY;COUNT=1.5'] as $rule) {
		Assert::exception(fn() => new Freq($rule, 0), InvalidArgumentException::class);
	}
});

test('Recurrence extensions are retained without dynamic properties', function () {
	$rule = ['FREQ' => 'DAILY', 'X-CUSTOM' => 'value', 'LISTPROPERTIES' => 'extension'];
	$recurrence = new Recurrence($rule);
	Assert::same($rule, $recurrence->rrule);
	Assert::same('DAILY', $recurrence->getFreq());
	Assert::false(property_exists($recurrence, 'x-custom'));
	Assert::false($recurrence->getCount());
});

test('A recurrence exception restores the process timezone', function () {
	$parser = new IcalParser();
	Assert::exception(fn() => $parser->parseString(implode("\n", [
		'BEGIN:VCALENDAR',
		'BEGIN:VEVENT',
		'DTSTART;TZID=America/Denver:20260101T100000',
		'RRULE:FREQ=DAILY;INTERVAL=0',
		'END:VEVENT',
		'END:VCALENDAR',
	])), InvalidArgumentException::class);
	Assert::same('UTC', date_default_timezone_get());
});
