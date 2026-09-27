<?php
declare(strict_types=1);

/**
 * Recurrence sets: DTSTART + RRULE + RDATE - EXDATE (RFC 5545, section 3.8.5).
 */

use om\ICal\Exception\InvalidRecurrenceRuleException;
use om\ICal\Exception\ResourceLimitException;
use om\RRule\Expander;
use om\RRule\RecurrenceSet;
use om\RRule\Rule;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

/**
 * @param iterable<int> $timestamps
 * @return list<string>
 */
function days(iterable $timestamps): array {
	$result = [];
	foreach ($timestamps as $timestamp) {
		$result[] = gmdate('m-d H:i', $timestamp);
	}
	return $result;
}

function utc(string $date): int {
	return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
}

$start = new DateTimeImmutable('2026-01-01 09:00', new DateTimeZone('UTC'));

test('RDATE is merged, duplicates removed, EXDATE wins', function () use ($start) {
	$set = new RecurrenceSet($start, Rule::fromString('FREQ=DAILY;COUNT=3'), rdates: [utc('2026-01-02 09:00'), utc('2026-01-02 12:00'), utc('2025-12-31 09:00')], exdates: [utc('2026-01-03 09:00')]);
	Assert::same(['12-31 09:00', '01-01 09:00', '01-02 09:00', '01-02 12:00'], days($set));
});

test('Without RRULE the set is DTSTART and RDATE', function () use ($start) {
	Assert::same(['01-01 09:00', '01-05 09:00'], days(new RecurrenceSet($start, rdates: [utc('2026-01-05 09:00')])));
	Assert::same([], days(new RecurrenceSet($start, exdates: [$start->getTimestamp()])));
});

test('A date-only EXDATE removes the occurrences of that day', function () use ($start) {
	$set = new RecurrenceSet($start, Rule::fromString('FREQ=HOURLY;COUNT=4'), exdays: ['20260101']);
	Assert::same([], days($set));
	$prague = new DateTimeImmutable('2026-01-01 23:30', new DateTimeZone('Europe/Prague'));
	Assert::same(['01-01 22:30'], days(new RecurrenceSet($prague, Rule::fromString('FREQ=HOURLY;COUNT=2'), exdays: ['20260102'])), 'days are local to DTSTART');
});

test('Several rules are combined', function () use ($start) {
	$set = new RecurrenceSet($start, [Rule::fromString('FREQ=WEEKLY;COUNT=2'), Rule::fromString('FREQ=DAILY;INTERVAL=3;COUNT=3')]);
	Assert::same(['01-01 09:00', '01-04 09:00', '01-07 09:00', '01-08 09:00'], days($set));
});

test('The RRULE window and the limit', function () use ($start) {
	$set = new RecurrenceSet($start, Rule::fromString('FREQ=DAILY'), rdates: [utc('2026-03-01 09:00')], until: utc('2026-01-03 09:00'), from: utc('2026-01-02 00:00'));
	Assert::same(['01-02 09:00', '01-03 09:00', '03-01 09:00'], days($set), 'RDATE is outside the RRULE window');
	Assert::count(5, days(new RecurrenceSet($start, Rule::fromString('FREQ=DAILY'), limit: 5)));
	Assert::exception(fn() => days(new RecurrenceSet($start, Rule::fromString('FREQ=DAILY'), limit: 5, strict: true)), ResourceLimitException::class);
	$exception = Assert::exception(fn() => days(new RecurrenceSet($start, Rule::fromString('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30'), maxIterations: 10)), ResourceLimitException::class);
	Assert::same('recurrence.iterations', $exception->errorCode());
});

test('Invalid rules throw InvalidRecurrenceRuleException', function () {
	$exception = Assert::exception(fn() => Rule::fromString('FREQ=DAILY;BYHOUR=24'), InvalidRecurrenceRuleException::class);
	Assert::same('recurrence.invalid-rule', $exception->errorCode());
	Assert::type(InvalidArgumentException::class, $exception, 'compatible with InvalidArgumentException');
});

test('Rules are serialized', function () {
	foreach (['FREQ=MONTHLY;COUNT=10;INTERVAL=2;BYDAY=1SU,-1SU', 'FREQ=WEEKLY;UNTIL=19971224T000000Z;BYDAY=TU,TH;WKST=SU', 'FREQ=DAILY;UNTIL=20260101', 'FREQ=YEARLY;BYSECOND=0,30;BYMINUTE=15;BYHOUR=9;BYMONTHDAY=-1;BYYEARDAY=100;BYWEEKNO=20;BYMONTH=6;BYSETPOS=-1'] as $rule) {
		Assert::same($rule, Rule::fromString($rule)->toString());
	}
	Assert::same('FREQ=DAILY;UNTIL=20260101T000000Z', Rule::fromArray(['FREQ' => 'DAILY', 'UNTIL' => new DateTime('2026-01-01 01:00', new DateTimeZone('Europe/Prague'))])->toString());
});

test('Every BY rule part on every frequency', function () use ($start) {
	$cases = [
		'FREQ=SECONDLY;COUNT=3' => ['01-01 09:00:00', '01-01 09:00:01', '01-01 09:00:02'],
		'FREQ=MINUTELY;COUNT=2;BYSECOND=15' => ['01-01 09:00:00', '01-01 09:00:15'],
		'FREQ=HOURLY;COUNT=3;BYMINUTE=0,30' => ['01-01 09:00:00', '01-01 09:30:00', '01-01 10:00:00'],
		'FREQ=DAILY;COUNT=3;BYHOUR=8,20' => ['01-01 09:00:00', '01-01 20:00:00', '01-02 08:00:00'],
		'FREQ=WEEKLY;COUNT=3;BYDAY=MO,WE' => ['01-01 09:00:00', '01-05 09:00:00', '01-07 09:00:00'],
		'FREQ=MONTHLY;COUNT=3;BYDAY=-1FR' => ['01-01 09:00:00', '01-30 09:00:00', '02-27 09:00:00'],
		'FREQ=MONTHLY;COUNT=3;BYDAY=1MO' => ['01-01 09:00:00', '01-05 09:00:00', '02-02 09:00:00'],
		'FREQ=MONTHLY;COUNT=3;BYMONTHDAY=-1' => ['01-01 09:00:00', '01-31 09:00:00', '02-28 09:00:00'],
		'FREQ=MONTHLY;COUNT=3;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1' => ['01-01 09:00:00', '01-30 09:00:00', '02-27 09:00:00'],
		'FREQ=YEARLY;COUNT=3;BYYEARDAY=-1' => ['01-01 09:00:00', '12-31 09:00:00', '12-31 09:00:00'],
		'FREQ=YEARLY;COUNT=2;BYWEEKNO=1;BYDAY=MO' => ['01-01 09:00:00', '01-04 09:00:00'],
		'FREQ=YEARLY;COUNT=2;BYMONTH=2;BYMONTHDAY=29' => ['01-01 09:00:00', '02-29 09:00:00'],
	];
	foreach ($cases as $rule => $expected) {
		$result = [];
		foreach (new Expander(Rule::fromString($rule), $start) as $timestamp) {
			$result[] = gmdate('m-d H:i:s', $timestamp);
		}
		Assert::same($expected, $result, $rule);
	}
});

test('Regression: BYSETPOS selecting nothing in every period ends the search', function () use ($start) {
	Assert::same(['01-01 09:00'], days(new Expander(Rule::fromString('FREQ=WEEKLY;BYSETPOS=-3;COUNT=15'), $start)));
});
