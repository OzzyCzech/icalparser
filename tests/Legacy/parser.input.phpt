<?php
declare(strict_types=1);

use om\IcalParser;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../bootstrap.php';

test('Unfold spaces and tabs, ignore blank lines, preserve component parents', function () {
	$parser = new IcalParser();
	$data = $parser->parseString(implode("\r\n", [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'BEGIN:VEVENT',
		'UID:one',
		'DTSTART:20260101T100000Z',
		'DESCRIPTION:First',
		"\t second",
		'  third',
		'',
		'BEGIN:VALARM',
		'ACTION:DISPLAY',
		'DESCRIPTION:Reminder',
		'END:VALARM',
		'SUMMARY:Event after alarm',
		'ORGANIZER:mailto:owner@example.org',
		'END:VEVENT',
		'X-WR-CALNAME:Calendar after event',
		'END:VCALENDAR',
		'',
	]));
	Assert::same('First second third', $data['VEVENT'][0]['DESCRIPTION']);
	Assert::same('Event after alarm', $data['VEVENT'][0]['SUMMARY']);
	Assert::same('mailto:owner@example.org', $data['VEVENT'][0]['ORGANIZER']);
	Assert::same('Calendar after event', $data['X-WR-CALNAME']);
	Assert::same('Reminder', $parser->getAlarms()[0]['DESCRIPTION']);
	Assert::hasNotKey('SUMMARY', $parser->getAlarms()[0]);
	Assert::hasNotKey(0, $data);
	Assert::hasNotKey('BEGIN', $data);
});

test('Callback receives property rows with their parent and a defined counter', function () {
	$parser = new IcalParser();
	$rows = [];
	$result = $parser->parseString("BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:one\nEND:VEVENT\n\nEND:VCALENDAR\n",
		static function ($row, $key, $middle, $value, $section, $counter) use (&$rows): void {
			$rows[] = [$key, $section, $counter];
		});
	Assert::null($result);
	Assert::same([['VERSION', 'VCALENDAR', 0], ['UID', 'VEVENT', 0]], $rows);
	Assert::same([], $parser->data);
});

test('Invalid input preserves previously parsed data', function () {
	$parser = new IcalParser();
	$data = $parser->parseString("BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR");
	Assert::exception(fn() => $parser->parseString('invalid'), InvalidArgumentException::class);
	Assert::same($data, $parser->data);
});

test('Unreadable files produce a RuntimeException', function () {
	Assert::exception(fn() => (new IcalParser())->parseFile(__DIR__ . '/../Fixtures/Samples/not-present.ics'), RuntimeException::class);
});

test('Append mode retains previous events and advances counters', function () {
	$parser = new IcalParser();
	$template = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:%s\nEND:VEVENT\nEND:VCALENDAR";
	$parser->parseString(sprintf($template, 'one'));
	$parser->parseString(sprintf($template, 'two'), add: true);
	Assert::same(['one', 'two'], array_column($parser->getEvents()->getArrayCopy(), 'UID'));
});
