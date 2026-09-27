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
	Assert::same(['value.invalid@3'], warnings($event('SEQUENCE:first')), 'permissive parsing reports invalid values');
	Assert::same(['value.nonstandard@3'], warnings($event('RDATE:20261010Z')), 'and values accepted although they break the RFC');
	Assert::same(['value.leap-second@3'], warnings($event('DTSTART:20261231T235960Z')), 'allowed by the RFC, but read as second 59');
	foreach (['DTSTART:20260101', 'DTSTART;VALUE=DATE-TIME:20260101', 'DTSTART;TZID=Europe/Prague:20260101T100000Z', 'DTSTART:20260101T100000Z/PT1H', 'RDATE:20260101T100000Z/PT1H'] as $line) {
		Assert::same(['value.nonstandard@3'], warnings($event($line)), $line);
		Assert::same('value.nonstandard', strict($event($line))->errorCode(), $line);
	}
	Assert::same([], warnings($event('RDATE;VALUE=PERIOD:20260101T100000Z/PT1H')), 'a PERIOD is allowed here');
	Assert::same([], array_map(fn($w) => $w->code, ICal::parser()->checkValues(false)->parse($event('SEQUENCE:first'))->warnings()), 'unless turned off');
	Assert::same(0, ICal::parse($event('SEQUENCE:first'))->events()[0]->sequence(), 'an invalid value is ignored');
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

test('Regressions of the review: nested calendars, streams, BOM, orphan continuation lines', function () {
	$result = ICal::parser()->parse(ics('BEGIN:VCALENDAR', 'X-WR-CALNAME:one', 'BEGIN:VEVENT', 'UID:a', 'BEGIN:VCALENDAR', 'X-WR-CALNAME:two', 'BEGIN:VEVENT', 'UID:b', 'END:VEVENT', 'END:VCALENDAR'));
	Assert::same(['one', 'two'], array_map(fn($calendar) => $calendar->name(), $result->calendars()), 'BEGIN:VCALENDAR closes an open calendar');
	Assert::same([['a'], ['b']], array_map(fn($calendar) => array_map(fn($event) => $event->uid(), $calendar->events()), $result->calendars()));

	$stream = fopen('php://memory', 'r+b');
	fwrite($stream, ics('BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'UID:x', 'DTSTART;TZID=Nowhere/Land:20260101T100000', 'DURATION:garbage', 'END:VEVENT', 'END:VCALENDAR'));
	rewind($stream);
	$warnings = [];
	iterator_to_array(ICal::parser()->stream($stream, function ($warning) use (&$warnings) {
		$warnings[] = $warning->code;
	}));
	Assert::same(['timezone.unresolved', 'value.invalid'], $warnings, 'streams check values like parse()');
	rewind($stream);
	Assert::exception(fn() => iterator_to_array(ICal::parser()->mode(ParserMode::Strict)->stream($stream)), TimezoneResolutionException::class);

	// a stream delivering one byte per read still recognizes the byte order mark
	$slow = new class {
		public mixed $context = null;
		private string $data = "\u{FEFF}BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
		private int $position = 0;

		public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool {
			return true;
		}

		public function stream_read(int $count): string|false {
			return $this->position < strlen($this->data) ? $this->data[$this->position++] : '';
		}

		public function stream_eof(): bool {
			return $this->position >= strlen($this->data);
		}
	};
	stream_wrapper_register('slowics', $slow::class);
	Assert::same('2.0', ICal::parser()->mode(ParserMode::Strict)->parseStream(fopen('slowics://x', 'rb'))->calendar()->version());
	stream_wrapper_unregister('slowics');

	Assert::same(['syntax.invalid-line@1'], warnings(" orphan\r\nBEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"));
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
