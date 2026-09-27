<?php declare(strict_types=1);
/**
 * @dataProvider numeric-recurrence.php
 */

use om\IcalParser;
use Tester\Assert;
use Tester\Environment;

require_once __DIR__ . '/../bootstrap.php';

[$start, $rule, $expected, $excluded] = Environment::loadData();
$end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+1 hour')->format('Ymd\THis');
$cal = new IcalParser();
$cal->parseString(implode("\r\n", [
	'BEGIN:VCALENDAR',
	'VERSION:2.0',
	'X-WR-TIMEZONE:UTC',
	'BEGIN:VEVENT',
	'UID:numeric-recurrence',
	'DTSTART:' . $start . 'Z',
	'DTEND:' . $end . 'Z',
	'RRULE:' . $rule,
	$excluded,
	'END:VEVENT',
	'END:VCALENDAR',
]));

$actual = [];
foreach ($cal->getEvents()->sorted() as $event) {
	$actual[] = $event['DTSTART']->format('Ymd\THis');
	Assert::same(3600, $event['DTEND']->getTimestamp() - $event['DTSTART']->getTimestamp());
}
Assert::same($expected, $actual);
