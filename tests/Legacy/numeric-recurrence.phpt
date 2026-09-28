<?php
declare(strict_types=1);

/**
 * Numbers in BY rule parts, padded or not, with the deprecated IcalParser (PR 88, fixed in 4.1.4).
 *
 * @dataProvider ../Integration/numeric-recurrence.php
 */

use om\IcalParser;
use Tester\Assert;
use Tester\Environment;

require_once __DIR__ . '/../bootstrap.php';

[$start, $rule, $expected, $excluded] = Environment::loadData();

$end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+1 hour')->format('Ymd\THis');
$parser = new IcalParser();
$parser->parseString(implode("\r\n", array_filter([
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
])));

$actual = [];
foreach ($parser->getEvents()->sorted() as $event) {
	$actual[] = $event['DTSTART']->format('Ymd\THis');
	Assert::same(3600, $event['DTEND']->getTimestamp() - $event['DTSTART']->getTimestamp());
}
Assert::same($expected, $actual);
