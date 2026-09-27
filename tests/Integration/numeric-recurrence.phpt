<?php
declare(strict_types=1);

/**
 * Numbers in BY rule parts, padded or not (PR 88).
 *
 * @dataProvider numeric-recurrence.php
 */

use om\ICal;
use Tester\Assert;
use Tester\Environment;

require_once __DIR__ . '/../bootstrap.php';

[$start, $rule, $expected, $excluded] = Environment::loadData();

$end = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify('+1 hour')->format('Ymd\THis');
$calendar = ICal::parse(implode("\r\n", array_filter([
	'BEGIN:VCALENDAR',
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
foreach ($calendar->events()[0]->occurrences(100) as $occurrence) {
	$actual[] = $occurrence->start->format('Ymd\THis');
	Assert::same(3600, $occurrence->endTime()->getTimestamp() - $occurrence->startTime()->getTimestamp());
}
Assert::same($expected, $actual);
