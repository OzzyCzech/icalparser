<?php
declare(strict_types=1);

/**
 * Syntax layer: content lines, parameters, unfolding and tokenizing (RFC 5545, section 3.1).
 */

use om\ICal\ContentLine;
use om\ICal\Exception\ResourceLimitException;
use om\ICal\Parameters;
use om\ICal\Parser\LineReader;
use om\ICal\Parser\Tokenizer;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

/**
 * @return array<int, string>
 */
function lines(string $content, mixed ...$options): array {
	return iterator_to_array(LineReader::fromString($content, ...$options));
}

test('A content line is split into name, parameters and value', function () {
	$line = ContentLine::parse('ATTENDEE;CN="Doe, John";ROLE=REQ-PARTICIPANT:mailto:john@example.com', 7);
	Assert::same('ATTENDEE', $line->name);
	Assert::same('CN="Doe, John";ROLE=REQ-PARTICIPANT', $line->rawParameters);
	Assert::same('mailto:john@example.com', $line->value);
	Assert::same(7, $line->line);
	Assert::same('Doe, John', $line->parameters->get('cn'));
	Assert::same('ATTENDEE;CN="Doe, John";ROLE=REQ-PARTICIPANT:mailto:john@example.com', (string) $line);
});

test('Names are case-insensitive, values keep their case and colons', function () {
	$line = ContentLine::parse('dtStart;tzid=Europe/Prague:20261010T100000');
	Assert::same('DTSTART', $line->name);
	Assert::same('Europe/Prague', $line->parameters->get('TZID'));
	Assert::same('http://example.org:8080/a', ContentLine::parse('URL:http://example.org:8080/a')->value);
	Assert::same('', ContentLine::parse('X-EMPTY:')->value);
});

test('Quoted parameter values may contain colons, semicolons and commas', function () {
	$line = ContentLine::parse('DESCRIPTION;ALTREP="http://example.org/a;b,c":Text');
	Assert::same('Text', $line->value);
	Assert::same('http://example.org/a;b,c', $line->parameters->get('ALTREP'));
});

test('BEGIN and END are content lines', function () {
	Assert::true(ContentLine::parse('BEGIN:VEVENT')->isBegin());
	Assert::true(ContentLine::parse('end:vevent')->isEnd());
	Assert::same('VEVENT', ContentLine::parse('end:vevent')->componentName());
	Assert::false(ContentLine::parse('BEGIN;X=1:VEVENT')->isBegin());
});

test('Invalid content lines', function () {
	foreach (['', 'no separator', ':value', 'NAME WITH SPACE:x', 'NAME;PARAM="unterminated:value', 'Ž:x'] as $line) {
		Assert::null(ContentLine::parse($line), $line);
	}
});

test('Parameters with several values, without "=" and serialization', function () {
	$parameters = Parameters::parse('MEMBER="mailto:a@example.org","mailto:b@example.org";RSVP=TRUE;BROKEN;X-EMPTY=');
	Assert::same(['mailto:a@example.org', 'mailto:b@example.org'], $parameters->values('member'));
	Assert::same('mailto:a@example.org,mailto:b@example.org', $parameters->get('MEMBER'));
	Assert::same('TRUE', $parameters->get('RSVP'));
	Assert::false($parameters->has('BROKEN'));
	Assert::same('', $parameters->get('X-EMPTY'));
	Assert::count(3, $parameters);
	Assert::same('MEMBER="mailto:a@example.org","mailto:b@example.org";RSVP=TRUE;X-EMPTY=', (string) $parameters);
	Assert::same('CN="Doe, John"', (string) Parameters::from(['cn' => 'Doe, John']));
	Assert::same([], $parameters->with('MEMBER', null)->with('RSVP', null)->with('X-EMPTY', null)->all());
	Assert::same(['A' => ['1']], iterator_to_array(Parameters::from(['A' => '1'])));
});

test('Line breaks are normalized and folded lines are joined', function () {
	Assert::same([1 => 'A:1', 2 => 'B:twoparts', 5 => 'C:3'], lines("A:1\r\nB:two\r\n parts\r\n\r\nC:3"));
	Assert::same([1 => 'A:1', 2 => 'B:tab', 4 => 'C:3'], lines("A:1\nB:t\n\tab\rC:3\r"));
	Assert::same([1 => 'A:1'], lines("\u{FEFF}A:1\r\n"));
	Assert::same([], lines(''));
	Assert::same([2 => 'A:1'], lines(" orphan continuation\r\nA:1"), 'a continuation without a line is dropped');
});

test('Folding keeps UTF-8 characters split over two lines', function () {
	Assert::same([1 => 'SUMMARY:Příliš žluťoučký kůň'], lines("SUMMARY:Příliš žlu\xC5\r\n \xA5oučký kůň"));
});

test('Lines are read in chunks, also across CRLF and folding at chunk boundaries', function () {
	$long = 'X:' . str_repeat('a', 65531); // the CR of CRLF is the last byte of the first chunk
	Assert::same([1 => $long . 'folded', 3 => 'Y:1'], lines("$long\r\n folded\r\nY:1\r\n"));
	$stream = fopen('php://memory', 'r+b');
	fwrite($stream, str_repeat("A:1\r\n", 30000));
	rewind($stream);
	Assert::count(30000, iterator_to_array(LineReader::fromStream($stream)));
});

test('Other line breaks than CRLF are reported once', function () {
	$warnings = [];
	$warn = function (string $code, string $message, int $line) use (&$warnings): void {
		$warnings[] = "$code:$line";
	};
	lines("A:1\r\nB:2\nC:3\n", warn: $warn);
	Assert::same(['syntax.line-ending:2'], $warnings);
	$warnings = [];
	lines("A:1\r\nB:2\r\n", warn: $warn);
	Assert::same([], $warnings);
});

test('Limits of the line length and the input size', function () {
	Assert::exception(fn() => lines('X:' . str_repeat('a', 100), maxLineLength: 50), ResourceLimitException::class, 'A line exceeds 50 bytes. (line 1)');
	Assert::exception(fn() => lines("X:aaa\r\n bbb\r\n ccc", maxLineLength: 8), ResourceLimitException::class);
	Assert::exception(fn() => lines(str_repeat("A:1\r\n", 100), maxSize: 100), ResourceLimitException::class, 'The input exceeds 100 bytes.');
	Assert::exception(fn() => iterator_to_array(LineReader::fromFile(__DIR__ . '/missing.ics')), RuntimeException::class);
	$exception = Assert::exception(fn() => lines('X:' . str_repeat('a', 100), maxLineLength: 50), ResourceLimitException::class);
	Assert::same('limit.line-length', $exception->errorCode());
	Assert::same(1, $exception->line());
});

test('The tokenizer reports invalid lines and yields content lines', function () {
	$invalid = [];
	$tokens = iterator_to_array(Tokenizer::fromString("BEGIN:VCALENDAR\r\ngarbage\r\nVERSION:2.0\r\nEND:VCALENDAR", function (string $line, int $number) use (&$invalid): void {
		$invalid[] = "$number:$line";
	}), false);
	Assert::same(['BEGIN', 'VERSION', 'END'], array_map(fn(ContentLine $line) => $line->name, $tokens));
	Assert::same(['2:garbage'], $invalid);
	$first = Tokenizer::fromFile(__DIR__ . '/../../Fixtures/Samples/minimal.ics')->current();
	Assert::true($first->isBegin());
	Assert::same('VCALENDAR', $first->componentName());
});
