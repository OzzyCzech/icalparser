<?php declare(strict_types=1);
/**
 * @author PC Drew <pc@schoolblocks.com>
 * @author Roman Ožana <roman@ozana.cz>
 */

use om\IcalParser;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../bootstrap.php';

test('Recurring instances finite', function () {
	$cal = new IcalParser();

	$cal->parseFile(__DIR__ . '/../Fixtures/Samples/recur_instances_finite.ics');
	$events = $cal->getEvents()->sorted();

	// DTSTART;TZID=America/Los_Angeles:20121002T100000
	// DTEND;TZID=America/Los_Angeles:20121002T103000
	// RRULE:FREQ=MONTHLY;INTERVAL=1;BYDAY=1TU;UNTIL=20121231T100000
	// RDATE;TZID=America/Los_Angeles:20121110T100000
	// RDATE;TZID=America/Los_Angeles:20121105T100000
	Assert::equal(5, $events->count());
	Assert::equal('2.10.2012 10:00:00', $events[0]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('5.11.2012 10:00:00', $events[1]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('6.11.2012 10:00:00', $events[2]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('10.11.2012 10:00:00', $events[3]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('4.12.2012 10:00:00', $events[4]['DTSTART']->format('j.n.Y H:i:s'));
});

test('Recurring instance check with a fixed horizon', function () {
	$cal = new IcalParser();
	$source = file_get_contents(__DIR__ . '/../Fixtures/Samples/recur_instances.ics');
	$source = str_replace('RRULE:FREQ=MONTHLY;INTERVAL=1;BYDAY=1TU', 'RRULE:FREQ=MONTHLY;INTERVAL=1;BYDAY=1TU;UNTIL=20151104T000000Z', $source);
	$cal->parseString($source);
	$events = $cal->getEvents()->sorted()->getArrayCopy();

	// The November 5 addition is moved to November 6 at 20:00.
	// The regular November 6 occurrence at 10:00 must also remain.
	Assert::same([
		'2012-10-02 15:00',
		'2012-11-06 10:00',
		'2012-11-06 20:00',
		'2012-11-10 10:00',
		'2012-11-30 10:00',
		'2013-01-01 10:00',
		'2013-03-05 10:00',
		'2013-05-07 10:00',
		'2013-06-04 10:00',
		'2013-07-02 10:00',
		'2013-08-06 10:00',
		'2013-09-03 10:00',
		'2013-10-01 10:00',
		'2013-11-05 10:00',
		'2013-12-03 10:00',
		'2014-01-07 10:00',
		'2014-02-04 10:00',
		'2014-03-04 10:00',
		'2014-04-01 10:00',
		'2014-05-06 10:00',
		'2014-06-03 10:00',
		'2014-07-01 10:00',
		'2014-08-05 10:00',
		'2014-09-02 10:00',
		'2014-10-07 10:00',
		'2014-11-04 10:00',
		'2014-12-02 10:00',
		'2015-01-06 10:00',
		'2015-02-03 10:00',
		'2015-03-03 10:00',
		'2015-04-07 10:00',
		'2015-05-05 10:00',
		'2015-06-02 10:00',
		'2015-07-07 10:00',
		'2015-08-04 10:00',
		'2015-09-01 10:00',
		'2015-10-06 10:00',
		'2015-11-03 10:00',
	], array_map(fn($event) => $event['DTSTART']->format('Y-m-d H:i'), $events));
});

test('Recurrent event with modifications at single date', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/../Fixtures/Samples/recur_instances_with_modifications.ics');
	$events = $cal->getEvents()->sorted();

	// There should be 36 total events because of the modified event + 35 recurrences
	Assert::count(36, $events); // 36 events

	// There should be 35 total recurrences because the modified event should've removed 1 recurrence
	Assert::hasKey('RECURRENCES', $events->offsetGet(1));
	$recurrences = $events->getIterator()->current()['RECURRENCES'];
	Assert::count(35, $recurrences);

	// reccurent event don't have RECURRENCES
	foreach (range(2, 35) as $index) {
		Assert::hasNotKey('RECURRENCES', $events->offsetGet($index));
	}

	// the date 8.8.2016 should be modified
	$modifiedEvent = $events->offsetGet(0);
	Assert::hasNotKey('RECURRENCES', $modifiedEvent);
	// the 12th entry is the modified event, related to the remaining recurring events
	Assert::same('8.8.2016', $modifiedEvent['DTSTART']->format('j.n.Y'));
	Assert::notContains($modifiedEvent['DTSTART'], $recurrences);
});

test('Recuring instances with modifications and interval', function () {
	$cal = new IcalParser();
	$results = $cal->parseFile(__DIR__ . '/../Fixtures/Samples/recur_instances_with_modifications_and_interval.ics');

	// Build the cache of RECURRENCE-IDs and EXDATES first, so that we can properly determine the interval
	$eventCache = [];
	foreach ($results['VEVENT'] as $event) {
		$eventSequence = empty($event['SEQUENCE']) ? '0' : $event['SEQUENCE'];
		$eventRecurrenceID = empty($event['RECURRENCE-ID']) ? '0' : $event['RECURRENCE-ID'];
		$eventCache[$event['UID']][$eventRecurrenceID][$eventSequence] = $event;
	}
	$trueEvents = [];
	foreach ($results['VEVENT'] as $event) {
		if (empty($event['RECURRENCES'])) {
			$trueEvents[] = $event;
		} else {
			$eventUID = $event['UID'];
			foreach ($event['RECURRENCES'] as $recurrence) {
				$eventRecurrenceID = $recurrence->format('Ymd');
				if (empty($eventCache[$eventUID][$eventRecurrenceID])) {
					$trueEvents[$eventRecurrenceID] = ['DTSTART' => $recurrence];
				} else {
					krsort($eventCache[$eventUID][$eventRecurrenceID]);
					$keys = array_keys($eventCache[$eventUID][$eventRecurrenceID]);
					$trueEvents[$eventRecurrenceID] = $eventCache[$eventUID][$eventRecurrenceID][$keys[0]];
				}
			}
		}
	}

	usort(
		$trueEvents,
		static function ($a, $b): int {
			return ($a['DTSTART'] > $b['DTSTART']) ? 1 : -1;
		},
	);

	$events = $cal->getEvents()->sorted()->getArrayCopy();

	Assert::false(empty($events[0]['RECURRENCES']));
	Assert::equal(count($trueEvents), count($events));
	foreach ($trueEvents as $index => $trueEvent) {
		Assert::equal($trueEvent['DTSTART']->format('Ymd'), $events[$index]['DTSTART']->format('Ymd'));
	}

});

test('Modifications to first recurrence handled correctly', function () {
	$cal = new IcalParser();
	// There is still an issue that needs to be resolved when modifications are made to the initial event that is the
	// base of the recurrences.  The below ICS file has a great edge case example: one event, no recurrences in the
	// recurring ruleset, and a modification to the initial event.
	$results = $cal->parseFile(__DIR__ . '/../Fixtures/Samples/recur_instances_with_modifications_to_first_day.ics');
	$events = $cal->getEvents()->sorted()->getArrayCopy();
	Assert::true(empty($events[0]['RECURRENCES'])); // edited event
	Assert::true(empty($events[1]['RECURRENCES'])); // recurring event base with no recurrences
	Assert::equal(1, count($events));
});

test('Daily recurring period matches expected days', function () {
	$cal = new IcalParser();
	$results = $cal->parseFile(__DIR__ . '/../Fixtures/Samples/daily_recur.ics');
	$events = $cal->getEvents()->sorted()->getArrayCopy();
	$period = new DatePeriod(new DateTime('20120801T050000'), new DateInterval('P1D'), new DateTime('20150801T050000'));
	foreach ($period as $i => $day) {
		Assert::equal($day->format('j.n.Y H:i:s'), $events[$i]['DTSTART']->format('j.n.Y H:i:s'));
	}
});

test('Daily recurring with count', function () {
	$cal = new IcalParser();
	$results = $cal->parseFile(__DIR__ . '/../Fixtures/Samples/daily_recur2.ics');
	$events = $cal->getEvents()->sorted()->getArrayCopy();

	Assert::equal(4, count($events));
	Assert::equal('21.8.2017 00:00:00', $events[0]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('28.8.2017 00:00:00', $events[1]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('4.9.2017 00:00:00', $events[2]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('11.9.2017 00:00:00', $events[3]['DTSTART']->format('j.n.Y H:i:s'));
});

/**
 * https://github.com/OzzyCzech/icalparser/issues/75
 */
test('Two times weekly events', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/../Fixtures/Samples/twice_weekly.ics');
	$events = $cal->getEvents()->sorted()->getArrayCopy();

	// repeat 9 times (thu + tue) within january 2026
	Assert::equal(9, count($events));

	// First event (thursday)
	Assert::equal('1.1.2026 10:00:00', $events[0]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('1.1.2026 11:00:00', $events[0]['DTEND']->format('j.n.Y H:i:s'));

	// Second event (tuesday)
	Assert::equal('6.1.2026 10:00:00', $events[1]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('6.1.2026 11:00:00', $events[1]['DTEND']->format('j.n.Y H:i:s'));

	// Third event (thursday)
	Assert::equal('8.1.2026 10:00:00', $events[2]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('8.1.2026 11:00:00', $events[2]['DTEND']->format('j.n.Y H:i:s'));

	// Remaining events
	Assert::equal('13.1.2026 10:00:00', $events[3]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('13.1.2026 11:00:00', $events[3]['DTEND']->format('j.n.Y H:i:s'));

	Assert::equal('15.1.2026 10:00:00', $events[4]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('15.1.2026 11:00:00', $events[4]['DTEND']->format('j.n.Y H:i:s'));

	Assert::equal('20.1.2026 10:00:00', $events[5]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('20.1.2026 11:00:00', $events[5]['DTEND']->format('j.n.Y H:i:s'));

	Assert::equal('22.1.2026 10:00:00', $events[6]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('22.1.2026 11:00:00', $events[6]['DTEND']->format('j.n.Y H:i:s'));

	Assert::equal('27.1.2026 10:00:00', $events[7]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('27.1.2026 11:00:00', $events[7]['DTEND']->format('j.n.Y H:i:s'));

	Assert::equal('29.1.2026 10:00:00', $events[8]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('29.1.2026 11:00:00', $events[8]['DTEND']->format('j.n.Y H:i:s'));
});

test('recurring ical feed does not leak timezone (issue #84', function () {
	date_default_timezone_set('UTC');

	$calendar = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//DenverWeekly//EN
BEGIN:VEVENT
UID:denver-weekly@example.com
DTSTAMP:20260601T120000Z
SUMMARY:Saturday in Denver
DTSTART;TZID=America/Denver:20260613T000000
DTEND;TZID=America/Denver:20260614T000000
RRULE:FREQ=WEEKLY;COUNT=3;BYDAY=SA
END:VEVENT
END:VCALENDAR
ICS;

	$parser = new IcalParser();
	$parser->parseString($calendar);
	$first = $parser->getEvents()->sorted()[0]['DTSTART'];

	// echoe event time in the server timezone (UTC).
	Assert::same('0600', (clone $first)->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Hi'));
});

/**
 * https://github.com/OzzyCzech/icalparser/issues/38
 */
test('Weekly recurring event missing day (issue #38)', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/../Fixtures/Samples/38_weekly_recurring_event_missing_day.ics');
	$events = $cal->getEvents()->sorted()->getArrayCopy();

	//first monday
	Assert::equal('25.2.2019 09:00:00', $events[0]['DTSTART']->format('j.n.Y H:i:s'));
	//rest of week
	Assert::equal('26.2.2019 09:00:00', $events[1]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('27.2.2019 09:00:00', $events[2]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('28.2.2019 09:00:00', $events[3]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('1.3.2019 09:00:00', $events[4]['DTSTART']->format('j.n.Y H:i:s'));
	//now check the next 4 mondays to make sure they exist as well
	Assert::equal('4.3.2019 09:00:00', $events[5]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('11.3.2019 09:00:00', $events[10]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('18.3.2019 09:00:00', $events[15]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('25.3.2019 09:00:00', $events[20]['DTSTART']->format('j.n.Y H:i:s'));

	//Last week that works correctly
	Assert::equal('1.4.2019 09:00:00', $events[25]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('2.4.2019 09:00:00', $events[26]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('3.4.2019 09:00:00', $events[27]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('4.4.2019 09:00:00', $events[28]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('5.4.2019 09:00:00', $events[29]['DTSTART']->format('j.n.Y H:i:s'));

	//This week starts failing
	Assert::equal('8.4.2019 09:00:00', $events[30]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('9.4.2019 09:00:00', $events[31]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('10.4.2019 09:00:00', $events[32]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('11.4.2019 09:00:00', $events[33]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('12.4.2019 09:00:00', $events[34]['DTSTART']->format('j.n.Y H:i:s'));

	Assert::equal('15.4.2019 09:00:00', $events[35]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('16.4.2019 09:00:00', $events[36]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('17.4.2019 09:00:00', $events[37]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('18.4.2019 09:00:00', $events[38]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('19.4.2019 09:00:00', $events[39]['DTSTART']->format('j.n.Y H:i:s'));
});

test('Recurring instances bi-weekly', function () {
	// https://github.com/OzzyCzech/icalparser/issues/61
	$cal = new IcalParser();

	$cal->parseFile(__DIR__ . '/../Fixtures/Samples/rrule_interval.ics');
	$events = $cal->getEvents()->sorted();

	var_dump($events[0]['RECURRENCES']);

	// DTSTART;TZID=America/Los_Angeles:20230131T050000
	// DTEND;TZID=America/Los_Angeles:20230131T060000
	// RRULE:FREQ=WEEKLY;WKST=MO;UNTIL=20230228T090000;INTERVAL=2;BYDAY=TU
	Assert::equal(3, count($events[0]['RECURRENCES']));
	Assert::equal(3, $events->count());
	Assert::equal('31.1.2023 05:00:00', $events[0]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('14.2.2023 05:00:00', $events[1]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::equal('28.2.2023 05:00:00', $events[2]['DTSTART']->format('j.n.Y H:i:s'));
});
