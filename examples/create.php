<?php
declare(strict_types=1);

/**
 * Create a calendar with a recurring event and write it as iCalendar data.
 *
 * Usage: php examples/create.php > team.ics
 */

use om\ICal\Calendar;
use om\ICal\Component;
use om\ICal\Property;
use om\ICal\Value\Text;
use om\RRule\Rule;

require_once __DIR__ . '/../vendor/autoload.php';

$event = new Component('VEVENT', [
	Property::create('UID', 'standup-' . bin2hex(random_bytes(8)) . '@example.org'),
	Property::create('DTSTAMP', gmdate('Ymd\THis\Z')),
	Property::create('DTSTART', '20260105T093000', ['TZID' => 'Europe/Prague']),
	Property::create('DURATION', 'PT15M'),
	Property::create('RRULE', Rule::fromString('FREQ=WEEKLY;BYDAY=MO,WE,FR')->toString()),
	Property::create('SUMMARY', Text::escape('Standup, team A')),
	Property::create('X-EXAMPLE', 'custom properties are kept'),
]);

echo Calendar::create('-//example//standup//EN')->withComponent($event)->serialize();
