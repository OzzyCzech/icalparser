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
