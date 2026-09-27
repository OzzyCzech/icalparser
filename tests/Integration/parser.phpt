<?php
declare(strict_types=1);

/**
 * Strict and permissive parsing, warnings and resource limits.
 */

use om\ICal;
use om\ICal\Exception\ICalException;
use om\ICal\Exception\InvalidRecurrenceRuleException;
use om\ICal\Exception\InvalidValueException;
use om\ICal\Exception\ResourceLimitException;
use om\ICal\Exception\SyntaxException;
use om\ICal\Exception\TimezoneResolutionException;
use om\ICal\Parser\ParseLimits;
use om\ICal\Parser\ParserMode;
use om\ICal\Parser\ParseWarning;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/../bootstrap.php';

function ics(string ...$lines): string {
	return implode("\r\n", $lines) . "\r\n";
}

/**
 * @return list<string> "code@line"
 */
function warnings(string $content): array {
	return array_map(fn(ParseWarning $warning) => $warning->code . '@' . $warning->line, ICal::parser()->parse($content)->warnings());
}

function strict(string $content): ICalException {
	return Assert::exception(fn() => ICal::parser()->mode(ParserMode::Strict)->parse($content), ICalException::class);
}

$valid = ics('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:x', 'BEGIN:VEVENT', 'UID:1', 'DTSTAMP:20260101T000000Z', 'DTSTART:20260101T100000Z', 'END:VEVENT', 'END:VCALENDAR');

test('A valid calendar has no warnings in either mode', function () use ($valid) {
	Assert::same([], warnings($valid));
	$result = ICal::parser()->mode(ParserMode::Strict)->parse($valid);
	Assert::false($result->hasWarnings());
	Assert::count(1, $result->calendar()->events());
});

test('Recoverable syntax problems are repaired with a warning, strict mode throws', function () {
	$cases = [
		'syntax.invalid-line@3' => ics('BEGIN:VCALENDAR', 'VERSION:2.0', 'this is not a content line', 'END:VCALENDAR'),
		'syntax.outside-calendar@1' => ics('X-BEFORE:1', 'BEGIN:VCALENDAR', 'END:VCALENDAR'),
		'syntax.unexpected-end@2' => ics('BEGIN:VCALENDAR', 'END:VEVENT', 'END:VCALENDAR'),
		'syntax.missing-end@4' => ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'BEGIN:VALARM', 'END:VEVENT', 'END:VCALENDAR'),
		'syntax.missing-calendar@1' => ics('BEGIN:VEVENT', 'UID:1', 'END:VEVENT'),
		'syntax.line-ending@2' => "BEGIN:VCALENDAR\r\nVERSION:2.0\nEND:VCALENDAR\n",
	];
	foreach ($cases as $expected => $content) {
		Assert::contains($expected, warnings($content), $expected);
		$exception = strict($content);
		Assert::type(SyntaxException::class, $exception, $expected);
		Assert::same(explode('@', $expected)[0], $exception->errorCode(), $expected);
	}
});

test('Repairs keep the data', function () {
	$calendar = ICal::parse(ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'UID:open', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'END:VEVENT', 'BEGIN:VEVENT', 'UID:second'));
	Assert::same(['open', 'second'], array_map(fn($event) => $event->uid(), $calendar->events()));
	Assert::same('DISPLAY', $calendar->events()[0]->alarms()[0]->action());

	$implicit = ICal::parse(ics('BEGIN:VEVENT', 'UID:bare', 'END:VEVENT'));
	Assert::same('bare', $implicit->events()[0]->uid());
});

test('Input without a calendar', function () {
	$result = ICal::parser()->parse("hello\r\n");
	Assert::same([], $result->calendars());
	Assert::contains('syntax.no-calendar@', array_map(fn($w) => $w->code . '@' . $w->line, $result->warnings()));
	Assert::exception(fn() => $result->calendar(), SyntaxException::class);
	Assert::exception(fn() => ICal::parse(''), SyntaxException::class);
	Assert::same('syntax.no-calendar', strict('')->errorCode());
});

test('Several calendars in one input', function () {
	$result = ICal::parser()->parse(ics('BEGIN:VCALENDAR', 'X-WR-CALNAME:A', 'END:VCALENDAR', 'BEGIN:VCALENDAR', 'X-WR-CALNAME:B', 'END:VCALENDAR'));
	Assert::same(['A', 'B'], array_map(fn($calendar) => $calendar->name(), $result->calendars()));
	Assert::same('A', $result->calendar()->name());
});

test('Unresolved timezones are warnings, strict mode throws', function () {
	$content = ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'DTSTART;TZID=Mars/Olympus:20260101T100000', 'DTEND;TZID=Mars/Olympus:20260101T110000', 'END:VEVENT', 'END:VCALENDAR');
	Assert::same(['timezone.unresolved@3'], warnings($content), 'reported once');
	$exception = strict($content);
	Assert::type(TimezoneResolutionException::class, $exception);
	Assert::same(['DTSTART', 3, '20260101T100000'], [$exception->property(), $exception->line(), $exception->rawValue()]);
	Assert::true(ICal::parse($content)->events()[0]->start()->isFloating());
});

test('Strict mode rejects invalid values and rules', function () {
	$event = fn(string $line) => ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', $line, 'END:VEVENT', 'END:VCALENDAR');
	$exception = strict($event('DTSTART:2026-01-01'));
	Assert::type(InvalidValueException::class, $exception);
	Assert::same(['DTSTART', 3], [$exception->property(), $exception->line()]);
	Assert::type(InvalidRecurrenceRuleException::class, strict($event('RRULE:FREQ=DAILY;BYHOUR=99')));
	Assert::type(InvalidValueException::class, strict($event('SEQUENCE:first')));
	Assert::same([], warnings($event('SEQUENCE:first')), 'permissive parsing does not convert values, see Validator');
	Assert::null(ICal::parse($event('SEQUENCE:first'))->events()[0]->priority());
});

test('Resource limits', function () {
	$deep = ics('BEGIN:VCALENDAR', ...array_fill(0, 40, 'BEGIN:X'));
	Assert::same('limit.nesting', Assert::exception(fn() => ICal::parser()->parse($deep), ResourceLimitException::class)->errorCode());
	$many = ics(...['BEGIN:VCALENDAR', ...array_merge(...array_fill(0, 20, ['BEGIN:VEVENT', 'UID:1', 'END:VEVENT'])), 'END:VCALENDAR']);
	Assert::same('limit.components', Assert::exception(fn() => ICal::parser()->limits(new ParseLimits(maxComponents: 10))->parse($many), ResourceLimitException::class)->errorCode());
	Assert::same('limit.properties', Assert::exception(fn() => ICal::parser()->limits(new ParseLimits(maxProperties: 10))->parse($many), ResourceLimitException::class)->errorCode());
	Assert::same('limit.file-size', Assert::exception(fn() => ICal::parser()->limits(new ParseLimits(maxFileSize: 100))->parse($many), ResourceLimitException::class)->errorCode());
	Assert::same('limit.line-length', Assert::exception(fn() => ICal::parser()->limits(new ParseLimits(maxLineLength: 10))->parse($many), ResourceLimitException::class)->errorCode());
	Assert::count(20, ICal::parser()->limits(ParseLimits::unlimited())->parse($many)->calendar()->events());
});

test('Files and streams', function () {
	$file = __DIR__ . '/../Fixtures/Samples/basic.ics';
	Assert::same(ICal::parseFile($file)->name(), ICal::parser()->parseStream(fopen($file, 'rb'))->calendar()->name());
	Assert::exception(fn() => ICal::parseFile(__DIR__ . '/missing.ics'), RuntimeException::class);
	Assert::same('line 3: text [code.x]', (string) new ParseWarning('code.x', 'text', 3));
	Assert::same('text [code.x]', (string) new ParseWarning('code.x', 'text'));
});

test('The parser configuration is immutable', function () {
	$parser = ICal::parser();
	$strict = $parser->mode(ParserMode::Strict);
	Assert::notSame($parser, $strict);
	Assert::noError(fn() => $parser->parse(ics('BEGIN:VCALENDAR', 'garbage', 'END:VCALENDAR')));
	Assert::exception(fn() => $strict->parse(ics('BEGIN:VCALENDAR', 'garbage', 'END:VCALENDAR')), SyntaxException::class);
	Assert::noError(fn() => $parser->timezoneResolver(ICal\Timezone\CompositeTimezoneResolver::default())->floatingTimezone(null)->recurrenceLimits(new om\RRule\RecurrenceLimits()));
});
