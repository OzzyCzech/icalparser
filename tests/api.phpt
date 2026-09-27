<?php
declare(strict_types=1);

/**
 * Public API contracts and edge cases.
 */

use om\EventsList;
use om\Freq;
use om\IcalParser;
use om\Recurrence;
use om\RRule\Expander;
use om\RRule\Frequency;
use om\RRule\Rule;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('UTC');

/**
 * @return list<string>
 */
function expand(string $rule, string $start = '2026-01-01T09:00:00Z', int $take = 100): array {
	$result = [];
	foreach (new Expander(Rule::fromString($rule), new DateTimeImmutable($start)) as $timestamp) {
		$result[] = gmdate('Y-m-d H:i:s', $timestamp);
		if (count($result) >= $take) {
			break;
		}
	}
	return $result;
}

test('Recurrence exposes the rule parts', function () {
	$recurrence = new Recurrence([
		'FREQ' => 'MONTHLY', 'UNTIL' => '20261231', 'COUNT' => '5', 'INTERVAL' => '2', 'BYSECOND' => '0', 'BYMINUTE' => '30',
		'BYHOUR' => '9,10', 'BYDAY' => 'MO,TU', 'BYMONTHDAY' => '1', 'BYYEARDAY' => '100', 'BYWEEKNO' => '20',
		'BYMONTH' => '1,2', 'BYSETPOS' => '-1', 'WKST' => 'SU',
	]);
	Assert::same('MONTHLY', $recurrence->getFreq());
	Assert::same('20261231', $recurrence->getUntil());
	Assert::same('5', $recurrence->getCount());
	Assert::same('2', $recurrence->getInterval());
	Assert::same(['0'], $recurrence->getBySecond());
	Assert::same(['30'], $recurrence->getByMinute());
	Assert::same(['9', '10'], $recurrence->getByHour());
	Assert::same(['MO', 'TU'], $recurrence->getByDay());
	Assert::same(['1'], $recurrence->getByMonthDay());
	Assert::same(['100'], $recurrence->getByYearDay());
	Assert::same(['20'], $recurrence->getByWeekNo());
	Assert::same(['1', '2'], $recurrence->getByMonth());
	Assert::same(['-1'], $recurrence->getBySetPos());
	Assert::same('SU', $recurrence->getWkst());

	$recurrence->setUntil(new DateTimeImmutable('2027-01-01T00:00:00Z'));
	Assert::same('20270101T000000+0000', $recurrence->getUntil());
	$recurrence->setUntil(0);
	Assert::same('19700101T000000+0000', $recurrence->getUntil());
	$recurrence->setUntil('2027-02-03 04:05:06 UTC');
	Assert::same('20270203T040506+0000', $recurrence->getUntil());
});

test('EventsList sorts oldest or newest first and puts events without a date last', function () {
	$list = new EventsList([
		['ID' => 'none'],
		['ID' => 'b', 'DTSTART' => new DateTime('2026-02-01')],
		['ID' => 'string', 'DTSTART' => '2026-01-15'],
		['ID' => 'a', 'DTSTART' => new DateTime('2026-01-01')],
		['ID' => 'number', 'DTSTART' => strtotime('2026-03-01')],
		['ID' => 'invalid', 'DTSTART' => 'not a date'],
	]);
	Assert::same(['invalid', 'a', 'string', 'b', 'number', 'none'], array_column($list->sorted()->getArrayCopy(), 'ID'));
	Assert::same(['number', 'b', 'string', 'a', 'invalid', 'none'], array_column($list->reversed()->getArrayCopy(), 'ID'));
});

test('Deprecated accessors return the same results', function () {
	$parser = new IcalParser();
	$parser->parseFile(__DIR__ . '/cal/recur_instances_finite.ics');
	Assert::equal($parser->getEvents()->sorted()->getArrayCopy(), $parser->getSortedEvents()->getArrayCopy());
	Assert::equal($parser->getEvents()->reversed()->getArrayCopy(), $parser->getReverseSortedEvents()->getArrayCopy());
	Assert::same($parser->getTimezones(), $parser->getTimezone());
	Assert::count(1, $parser->getTimezones());
});

test('Quoted-printable values are decoded and malformed lines are ignored', function () {
	$parser = new IcalParser();
	$data = $parser->parseString(implode("\n", [
		'BEGIN:VCALENDAR',
		'this line has no separator',
		';NOVALUE:x',
		'BEGIN:VEVENT',
		'SUMMARY;ENCODING=QUOTED-PRINTABLE:Caf=C3=A9',
		'DESCRIPTION;LANGUAGE="en":Plain',
		'X-BROKEN;PARAM="unterminated:value',
		'RECURRENCE-ID:garbage',
		'UID:qp',
		'X-NO-VALUE;PARAM=1',
		'END:VEVENT',
		'BEGIN:VEVENT',
		'UID:qp',
		'DTSTART:20260101T090000Z',
		'RRULE:FREQ=DAILY;COUNT=2',
		'END:VEVENT',
		'END:VCALENDAR',
	]));
	Assert::same('Café', $data['VEVENT'][0]['SUMMARY']);
	Assert::same('Plain', $data['VEVENT'][0]['DESCRIPTION']);
	Assert::hasNotKey('X-BROKEN', $data['VEVENT'][0]);
	Assert::hasNotKey('X-NO-VALUE', $data['VEVENT'][0]);
	Assert::count(2, $data['VEVENT'][1]['RECURRENCES'], 'an invalid RECURRENCE-ID overrides nothing');
	Assert::same(['VEVENT', '_RECURRENCE_IDS', '_RECURRENCE_COUNTERS_BY_UID'], array_keys($data));
});

test('Freq periods and lookups', function () {
	$start = strtotime('2026-01-31T10:00:00Z');
	$frequency = new Freq('FREQ=MONTHLY;COUNT=3', $start);
	Assert::same(['2026-01-31', '2026-03-31', '2026-05-31'], array_map(fn($ts) => gmdate('Y-m-d', $ts), $frequency->getAllOccurrences()));
	Assert::same('2026-03-03', gmdate('Y-m-d', $frequency->findEndOfPeriod($start)));
	foreach (['YEARLY' => '2027-01-31 10:00', 'WEEKLY' => '2026-02-07 10:00', 'DAILY' => '2026-02-01 10:00', 'HOURLY' => '2026-01-31 11:00', 'MINUTELY' => '2026-01-31 10:01', 'SECONDLY' => '2026-01-31 10:00'] as $freq => $expected) {
		Assert::same($expected, gmdate('Y-m-d H:i', (new Freq("FREQ=$freq", $start))->findEndOfPeriod($start)), $freq);
	}
	$infinite = new Freq('FREQ=WEEKLY', $start);
	Assert::same($start, $infinite->firstOccurrence());
	Assert::same($start + 7 * 86400, $infinite->nextOccurrence($start));
	Assert::same($start, $infinite->previousOccurrence($start + 86400));
	Assert::same($start, $infinite->nextOccurrence($start - 1));
});

test('Rule validation', function () {
	foreach ([
		'FREQ=DAILY;WKST=XX', 'FREQ=DAILY;BYHOUR=24', 'FREQ=DAILY;BYHOUR=a', 'FREQ=DAILY;BYMONTHDAY=0', 'FREQ=DAILY;BYDAY=1XX',
		'FREQ=YEARLY;BYDAY=54MO', 'FREQ=DAILY;BYMONTH=13', 'FREQ=DAILY;BYSETPOS=0', 'FREQ=DAILY;UNTIL=soon', 'FREQ=DAILY;COUNT=x',
		'FREQ=DAILY;UNTIL=55555555T555555Z', 'FREQ=DAILY;UNTIL=20261350T000000', 'FREQ=DAILY;UNTIL=20269999',
	] as $rule) {
		Assert::exception(fn() => Rule::fromString($rule), InvalidArgumentException::class);
	}
	Assert::exception(fn() => Rule::fromArray(['FREQ' => ['DAILY']]), InvalidArgumentException::class);
	Assert::exception(fn() => new Rule(Frequency::Daily, interval: 0), InvalidArgumentException::class);
	Assert::exception(fn() => new Rule(Frequency::Daily, count: 0), InvalidArgumentException::class);

	$rule = Rule::fromString('freq=weekly;byday=mo,,tu;byhour=9,;wkst=su;;');
	Assert::same(Frequency::Weekly, $rule->freq);
	Assert::same([[0, 1], [0, 2]], $rule->byDay);
	Assert::same([9], $rule->byHour);
	Assert::same(7, $rule->wkst);
	Assert::same(1, Rule::fromString('FREQ=DAILY;UNTIL=2026-01-01T00:00:00Z')->untilTimestamp(new DateTimeZone('UTC')) <=> 0);
	Assert::null(Rule::fromString('FREQ=DAILY')->untilTimestamp(new DateTimeZone('UTC')));
});

test('Floating and date-only UNTIL use the timezone of DTSTART', function () {
	$zone = new DateTimeZone('Europe/Prague');
	Assert::same('2026-01-02 22:59:59', gmdate('Y-m-d H:i:s', Rule::fromString('FREQ=DAILY;UNTIL=20260102')->untilTimestamp($zone)));
	Assert::same('2026-01-02 09:00:00', gmdate('Y-m-d H:i:s', Rule::fromString('FREQ=DAILY;UNTIL=20260102T100000')->untilTimestamp($zone)));
	Assert::same(['2026-01-01 09:00:00', '2026-01-02 09:00:00'], expand('FREQ=DAILY;UNTIL=20260102'));
});

test('Expander edge cases', function () {
	Assert::same([], expand('FREQ=DAILY;UNTIL=20251231T000000Z'), 'UNTIL before DTSTART');
	Assert::same(['2026-01-01 09:00:00'], expand('FREQ=DAILY;COUNT=1'));
	Assert::same(['2026-01-01 09:00:00'], expand('FREQ=MINUTELY;BYSECOND=60'), 'a leap second cannot be represented');
	Assert::same(['2026-01-01 09:00:00', '2026-02-01 09:00:00'], expand('FREQ=MONTHLY;COUNT=2'));
	Assert::same(['2026-01-01 09:00:00', '2026-01-01 09:00:30', '2026-01-01 09:01:30'], expand('FREQ=SECONDLY;BYSECOND=30;BYMINUTE=0,1;COUNT=3'));
	Assert::same(['2026-01-01 09:00:00', '2026-01-01 10:15:00', '2026-01-01 11:15:00'], expand('FREQ=HOURLY;BYMINUTE=15;BYHOUR=10,11;COUNT=3'));
	Assert::same(['2026-01-01 09:00:00', '2027-01-01 09:00:00'], expand('FREQ=YEARLY;BYWEEKNO=53;BYYEARDAY=-365;COUNT=2', take: 2));
	Assert::same(['9998-12-31 09:00:00'], expand('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30', '9998-12-31T09:00:00Z'), 'the search ends at year 9999');
	Assert::same(['2026-01-01 09:00:00'], expand('FREQ=DAILY;BYMONTH=2;BYMONTHDAY=30'), 'the search ends after too many empty periods');
	Assert::same(['2026-01-01 09:00:00', '2026-01-01 09:05:00', '2026-01-01 10:05:00'], expand('FREQ=MINUTELY;BYMINUTE=5;COUNT=3'));
	Assert::exception(function () {
		iterator_to_array(new Expander(Rule::fromString('FREQ=DAILY'), new DateTimeImmutable('@0'), limit: 3));
	}, RuntimeException::class, 'Recurrence occurrence limit exceeded.');
});

test('Expander calendar helpers', function () {
	foreach (['1970-01-01', '2000-02-29', '1900-03-01', '1600-12-31', '2400-02-29', '0001-01-01'] as $date) {
		[$year, $month, $day] = array_map('intval', explode('-', $date));
		$days = Expander::daysFromCivil($year, $month, $day);
		Assert::same([$year, $month, $day], Expander::civilFromDays($days), $date);
		Assert::same((int) (new DateTimeImmutable($date . 'T00:00:00Z'))->format('N'), Expander::weekday($days), $date);
	}
});
