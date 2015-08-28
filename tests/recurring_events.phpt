<?php
/**
 * @author PC Drew <pc@schoolblocks.com>
 */
use Tester\Assert;

require_once __DIR__ . '/../vendor/autoload.php';
\Tester\Environment::setup();

$cal = new \om\IcalParser();


$results = $cal->parseFile(__DIR__ . '/cal/recur_instances_finite.ics');
$events = $cal->getSortedEvents();

Assert::false(empty($events[0]['RECURRENCES']));

// DTSTART;TZID=America/Los_Angeles:20121002T100000
// DTEND;TZID=America/Los_Angeles:20121002T103000
// RRULE:FREQ=MONTHLY;INTERVAL=1;BYDAY=1TU;UNTIL=20121231T100000
$recurrences = $events[0]['RECURRENCES'];
Assert::equal(3, sizeof($recurrences));
Assert::equal($events[0]['DTSTART'], $recurrences[0]);
Assert::equal('6.11.2012 10:00:00', $recurrences[1]->format('j.n.Y H:i:s'));
Assert::equal('4.12.2012 10:00:00', $recurrences[2]->format('j.n.Y H:i:s'));

$results = $cal->parseFile(__DIR__ . '/cal/recur_instances.ics');
$events = $cal->getSortedEvents();

Assert::false(empty($events[0]['RECURRENCES']));

// DTSTART;TZID=America/Los_Angeles:20121002T100000
// DTEND;TZID=America/Los_Angeles:20121002T103000
// RRULE:FREQ=MONTHLY;INTERVAL=1;BYDAY=1TU
// RDATE;TZID=America/Los_Angeles:20121105T100000
// RDATE;TZID=America/Los_Angeles:20121110T100000,20121130T100000
// EXDATE;TZID=America/Los_Angeles:20130402T100000
// EXDATE;TZID=America/Los_Angeles:20121204T100000
// EXDATE;TZID=America/Los_Angeles:20130205T100000
$recurrences = $events[0]['RECURRENCES'];
Assert::equal(3, sizeof($recurrences));

Assert: true(false);
