<?php
declare(strict_types=1);

/**
 * Non-Gregorian recurrence rules (RFC 7529): RSCALE, SKIP and leap months.
 */

use om\ICal;
use om\ICal\Exception\InvalidRecurrenceRuleException;
use om\ICal\Parser\ParserMode;
use om\ICal\Parser\ParseWarning;
use om\ICal\Validation\Issue;
use om\ICal\Validation\Validator;
use om\RRule\Expander;
use om\RRule\Frequency;
use om\RRule\Rule;
use om\RRule\Skip;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';
date_default_timezone_set('UTC');

function calendar(string $rrule, string $start = 'DTSTART;VALUE=DATE:20260131', string ...$more): string {
	return implode("\r\n", [
		'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN',
		'BEGIN:VEVENT', 'UID:1', 'DTSTAMP:20260101T000000Z', $start, "RRULE:$rrule", ...$more, 'END:VEVENT',
		'END:VCALENDAR',
	]) . "\r\n";
}

/**
 * @return list<string> "code@line"
 */
function warnings(string $content): array {
	return array_map(fn(ParseWarning $warning) => $warning->code . '@' . $warning->line, ICal::parser()->parse($content)->warnings());
}

/**
 * @return list<string> "SEVERITY code"
 */
function issues(string $content): array {
	return array_map(fn(Issue $issue) => strtoupper($issue->severity->name) . ' ' . $issue->code, (new Validator())->validate(ICal::parse($content)));
}

test('RSCALE and SKIP are parsed', function () {
	$rule = Rule::fromString('rscale=gregorian;freq=monthly;skip=backward;count=3');
	Assert::same(Frequency::Monthly, $rule->freq);
	Assert::same('GREGORIAN', $rule->rscale);
	Assert::same(Skip::Backward, $rule->skip);
	Assert::same(Skip::Forward, Rule::fromString('FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=FORWARD')->skip);
	Assert::same(Skip::Omit, Rule::fromString('FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=OMIT')->skip);
	Assert::null(Rule::fromString('FREQ=YEARLY;RSCALE=GREGORIAN')->skip, 'the default OMIT');
	Assert::same('CHINESE', Rule::fromString('FREQ=YEARLY;RSCALE=Chinese')->rscale);
	Assert::same('ISLAMIC-CIVIL', Rule::fromArray(['FREQ' => 'YEARLY', 'rscale' => 'islamic-civil'])->rscale);

	$plain = Rule::fromString('FREQ=MONTHLY;BYMONTH=1,2');
	Assert::null($plain->rscale);
	Assert::null($plain->skip);
	Assert::same([1, 2], $plain->byMonth);
	Assert::same([], $plain->byLeapMonth);
});

test('Leap months are kept when RSCALE is present', function () {
	$rule = Rule::fromString('RSCALE=HEBREW;FREQ=YEARLY;BYMONTH=6,5L,5;BYMONTHDAY=8;SKIP=FORWARD');
	Assert::same([5, 6], $rule->byMonth);
	Assert::same([5], $rule->byLeapMonth);
	Assert::same([5], Rule::fromString('RSCALE=CHINESE;FREQ=YEARLY;BYMONTH=5l')->byLeapMonth);
	Assert::same([13], Rule::fromString('RSCALE=ETHIOPIC;FREQ=MONTHLY;BYMONTH=13')->byMonth, 'other calendar systems have other limits');
});

test('Invalid RSCALE, SKIP and leap months', function () {
	foreach ([
		'FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=LATER', 'FREQ=YEARLY;RSCALE=GREG_ORIAN', 'FREQ=YEARLY;RSCALE=GREGORIAN;BYMONTH=13',
		'FREQ=YEARLY;RSCALE=GREGORIAN;BYMONTH=0L', 'FREQ=YEARLY;RSCALE=GREGORIAN;BYMONTH=5X', 'FREQ=YEARLY;BYMONTH=5L',
		'FREQ=YEARLY;RSCALE=GREGORIAN;BYMONTHDAY=32',
	] as $rule) {
		$exception = Assert::exception(fn() => Rule::fromString($rule), InvalidRecurrenceRuleException::class);
		Assert::same('recurrence.invalid-rule', $exception->errorCode(), $rule);
	}
	foreach (['FREQ=MONTHLY;SKIP=BACKWARD', 'FREQ=MONTHLY;SKIP=OMIT'] as $rule) {
		$exception = Assert::exception(fn() => Rule::fromString($rule), InvalidRecurrenceRuleException::class);
		Assert::same('recurrence.skip-without-rscale', $exception->errorCode(), $rule);
	}
	Assert::exception(fn() => new Rule(Frequency::Monthly, skip: Skip::Forward), InvalidRecurrenceRuleException::class);
	Assert::exception(fn() => new Rule(Frequency::Yearly, byLeapMonth: [5]), InvalidRecurrenceRuleException::class);
});

test('RSCALE, SKIP and leap months are serialized', function () {
	foreach ([
		'FREQ=MONTHLY;RSCALE=GREGORIAN;COUNT=3;SKIP=BACKWARD',
		'FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=OMIT',
		'FREQ=YEARLY;RSCALE=HEBREW;BYMONTHDAY=8;BYMONTH=5,5L,6;SKIP=FORWARD',
		'FREQ=YEARLY;RSCALE=CHINESE',
	] as $rule) {
		Assert::same($rule, Rule::fromString($rule)->toString());
		Assert::same($rule, Rule::fromString(Rule::fromString($rule)->toString())->toString());
	}
	Assert::same('FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=FORWARD', Rule::fromString('RSCALE=GREGORIAN;FREQ=YEARLY;SKIP=FORWARD')->toString());
});

test('SKIP without RSCALE: a warning in permissive mode, the rule is kept without SKIP', function () {
	$content = calendar('FREQ=MONTHLY;COUNT=3;SKIP=BACKWARD');
	Assert::same(['value.invalid@8'], warnings($content));
	$rule = ICal::parse($content)->events()[0]->recurrenceRule();
	Assert::notNull($rule);
	Assert::null($rule->skip);
	Assert::same(['ERROR recurrence.skip-without-rscale'], issues($content));

	$exception = Assert::exception(fn() => ICal::parser()->mode(ParserMode::Strict)->parse($content), InvalidRecurrenceRuleException::class);
	Assert::same('recurrence.skip-without-rscale', $exception->errorCode());
	Assert::same(8, $exception->line());
});

/**
 * @return list<string>
 */
function expand(string $rule, string $start, int $take = 100, string $format = 'Y-m-d'): array {
	$result = [];
	foreach (new Expander(Rule::fromString($rule), new DateTimeImmutable($start, new DateTimeZone('UTC'))) as $timestamp) {
		$result[] = gmdate($format, $timestamp);
		if (count($result) >= $take) {
			break;
		}
	}
	return $result;
}

test('SKIP of monthly rules', function () {
	Assert::same(['2026-01-31', '2026-02-28', '2026-03-31'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=BACKWARD;COUNT=3', '2026-01-31'));
	Assert::same(['2026-01-31', '2026-03-01', '2026-03-31'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=FORWARD;COUNT=3', '2026-01-31'));
	Assert::same(['2026-01-31', '2026-03-31', '2026-05-31'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=OMIT;COUNT=3', '2026-01-31'));
	Assert::same(expand('FREQ=MONTHLY;COUNT=3', '2026-01-31'), expand('FREQ=MONTHLY;RSCALE=GREGORIAN;COUNT=3', '2026-01-31'), 'OMIT is the default');

	Assert::same(
		['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=BACKWARD;COUNT=6', '2026-01-31'),
	);
	Assert::same(
		['2026-01-31', '2026-03-01', '2026-03-31', '2026-05-01', '2026-05-31', '2026-07-01', '2026-07-31', '2026-08-31'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=FORWARD;COUNT=8', '2026-01-31'),
	);
	Assert::same(['2024-01-30', '2024-02-29', '2024-03-30'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=BACKWARD;COUNT=3', '2024-01-30'), 'February of a leap year');
	Assert::same(['2024-01-30', '2024-03-01', '2024-03-30'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=FORWARD;COUNT=3', '2024-01-30'));
	Assert::same(['2025-12-31', '2026-03-01', '2026-05-01', '2026-07-01', '2026-08-31'], expand('FREQ=MONTHLY;INTERVAL=2;RSCALE=GREGORIAN;SKIP=FORWARD;COUNT=5', '2025-12-31'));
});

test('SKIP of yearly rules', function () {
	Assert::same(['2028-02-29', '2029-02-28', '2030-02-28'], expand('FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=BACKWARD;COUNT=3', '2028-02-29'));
	Assert::same(['2028-02-29', '2029-03-01', '2030-03-01'], expand('FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=FORWARD;COUNT=3', '2028-02-29'));
	Assert::same(['2028-02-29', '2032-02-29', '2036-02-29'], expand('FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=OMIT;COUNT=3', '2028-02-29'));
	Assert::same(['2027-02-28', '2028-02-29', '2029-02-28'], expand('FREQ=YEARLY;RSCALE=GREGORIAN;BYMONTH=2;BYMONTHDAY=29;SKIP=BACKWARD;COUNT=3', '2027-02-28'));
	Assert::same(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], expand('FREQ=YEARLY;RSCALE=GREGORIAN;BYMONTHDAY=31;SKIP=BACKWARD;COUNT=4', '2026-01-31'), 'every month of the year');
	Assert::same(['2026-01-31', '2026-03-01', '2026-03-31', '2026-05-01'], expand('FREQ=YEARLY;RSCALE=GREGORIAN;BYMONTHDAY=31;SKIP=FORWARD;COUNT=4', '2026-01-31'));
});

test('RFC 7529, section 4.3.4: Gregorian leap day with SKIP', function () {
	Assert::same(['2012-02-29', '2016-02-29'], expand('FREQ=YEARLY', '2012-02-29', 2));
	Assert::same(
		['2012-02-29', '2013-03-01', '2014-03-01', '2015-03-01', '2016-02-29', '2017-03-01'],
		expand('RSCALE=GREGORIAN;FREQ=YEARLY;SKIP=FORWARD', '2012-02-29', 6),
	);
});

test('SKIP with UNTIL', function () {
	Assert::same(['2026-01-31', '2026-03-01'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=FORWARD;UNTIL=20260301', '2026-01-31'));
	Assert::same(['2026-01-31'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=FORWARD;UNTIL=20260228', '2026-01-31'), 'the moved instance is after UNTIL');
	Assert::same(['2026-01-31', '2026-02-28'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=BACKWARD;UNTIL=20260330', '2026-01-31'));
	Assert::same(['2028-02-29', '2029-02-28', '2030-02-28'], expand('FREQ=YEARLY;RSCALE=GREGORIAN;SKIP=BACKWARD;UNTIL=20300228', '2028-02-29'));
});

test('SKIP with BYMONTHDAY, BYMONTH and BYDAY', function () {
	Assert::same(
		['2026-01-28', '2026-01-30', '2026-02-28', '2026-03-28', '2026-03-30'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=28,30;SKIP=BACKWARD;COUNT=5', '2026-01-28'),
		'February 30 moved to February 28 is one instance',
	);
	Assert::same(
		['2026-01-31', '2026-02-01', '2026-03-01', '2026-03-31', '2026-04-01'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=1,31;SKIP=FORWARD;COUNT=5', '2026-01-31'),
		'February 31 moved to March 1 is one instance',
	);
	Assert::same(
		['2026-01-31', '2026-02-28', '2026-10-31', '2027-07-31'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=31;BYDAY=SA;SKIP=BACKWARD;COUNT=4', '2026-01-31'),
		'BYDAY applies to the moved instance',
	);
	Assert::same(['2026-01-31', '2026-03-01', '2027-01-31'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTH=1,2;BYMONTHDAY=31;SKIP=FORWARD', '2026-01-31', 3), 'BYMONTH applies before SKIP');
	Assert::same(['2026-01-31', '2026-03-03', '2026-04-02'], expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=-29;SKIP=BACKWARD;COUNT=3', '2026-01-31'), 'negative days are omitted');
});

test('SKIP applies before BYSETPOS and COUNT', function () {
	Assert::same(
		['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=29,30,31;BYSETPOS=-1;SKIP=BACKWARD;COUNT=4', '2026-01-31'),
		'February 29, 30 and 31 are one instance on February 28',
	);
	Assert::same(
		['2026-01-31', '2026-03-01', '2026-03-31', '2026-05-01', '2026-05-31'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=30,31;BYSETPOS=-1;SKIP=FORWARD;COUNT=5', '2026-01-31'),
		'the last instance of February is March 1, the last of April is May 1',
	);
	Assert::same(
		['2026-01-01', '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=1,31;BYSETPOS=2;SKIP=BACKWARD;COUNT=5', '2026-01-01'),
		'February has a second instance',
	);
	Assert::same(
		['2026-01-01', '2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=1,31;BYSETPOS=2;COUNT=5', '2026-01-01'),
		'without SKIP it has not',
	);
	Assert::same(
		['2026-01-01 09:00', '2026-01-31 17:00', '2026-02-01 09:00', '2026-03-01 09:00', '2026-03-01 17:00', '2026-03-31 17:00'],
		expand('FREQ=MONTHLY;RSCALE=GREGORIAN;BYMONTHDAY=1,31;BYHOUR=9,17;BYSETPOS=1,-1;SKIP=FORWARD;COUNT=6', '2026-01-01 09:00', format: 'Y-m-d H:i'),
		'an instance moved into March is sorted with the instances of March',
	);
});

test('SKIP of an event', function () {
	$content = calendar('FREQ=MONTHLY;RSCALE=GREGORIAN;SKIP=BACKWARD;COUNT=3');
	$occurrences = iterator_to_array(ICal::parse($content)->events()[0]->occurrences(), false);
	Assert::same(['2026-01-31', '2026-02-28', '2026-03-31'], array_map(fn($occurrence) => $occurrence->start->format('Y-m-d'), $occurrences));
	Assert::same([], warnings($content));
	Assert::same([], issues($content));
});
