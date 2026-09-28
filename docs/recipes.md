# Recipes

Short solutions of common tasks. Each one uses only the public API described in the guides.

## Read a public calendar from a URL

Download the feed yourself, with a timeout and a size limit, and parse the content:

```php
use om\ICal;
use om\ICal\Parser\ParseLimits;

$url = 'https://calendar.google.com/calendar/ical/cs.czech%23holiday%40group.v.calendar.google.com/public/basic.ics';
$context = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'my-app']]);
$content = file_get_contents($url, false, $context, 0, 10_000_000);
if ($content === false) {
	throw new RuntimeException("Cannot download $url");
}

$result = ICal::parser()->limits(new ParseLimits(maxFileSize: 10_000_000))->parse($content);
$calendar = $result->calendar();
```

Cache the content (feeds change rarely; `$calendar->refreshInterval()` is the interval the publisher
suggests). Only fetch URLs you trust: `file_get_contents()` and `parseFile()` read any path or stream
wrapper, so a URL entered by a user must be checked first (scheme `https`, no internal hosts).

## Upcoming events in the viewer's timezone

```php
$timezone = new DateTimeZone('Europe/Prague');   // the timezone of the viewer
$from = new DateTimeImmutable('today', $timezone);
$to = $from->modify('+30 days');

foreach ($calendar->occurrencesBetween($from, $to) as $occurrence) {
	if ($occurrence->isAllDay()) {
		// a date is the same day everywhere, do not convert it
		echo $occurrence->start->format('j. n. Y'), ' ', $occurrence->summary(), PHP_EOL;
	} else {
		echo $occurrence->startTime($timezone)->format('j. n. Y H:i'), ' ', $occurrence->summary(), PHP_EOL;
	}
}
```

`startTime($timezone)` converts UTC and zoned times to the timezone; floating times (the same local
time everywhere) are read in it. `$occurrence->item->location()`, `description()` and the other getters
give the details, also of a moved instance.

## The next occurrence of each event

```php
$now = new DateTimeImmutable();
foreach ($calendar->events() as $event) {
	foreach ($event->occurrences(limit: 1, from: $now) as $next) {
		echo $event->summary(), ': ', $next->startTime()->format(DATE_ATOM), PHP_EOL;
	}
}
```

`occurrences()` stops after the limit, so even a daily series without an end is cheap.

## Occurrences as JSON for a web calendar

The shape of [FullCalendar](https://fullcalendar.io/docs/event-object) events; all-day events get dates,
the end of an all-day event is exclusive, as in iCalendar:

```php
$events = [];
foreach ($calendar->occurrencesBetween($from, $to) as $occurrence) {
	$allDay = $occurrence->isAllDay();
	$events[] = [
		'id' => $occurrence->item->uid() . '/' . ($occurrence->recurrenceId ?? $occurrence->start),
		'title' => $occurrence->summary(),
		'start' => $allDay ? $occurrence->start->format('Y-m-d') : $occurrence->startTime($timezone)->format(DATE_ATOM),
		'end' => $allDay ? $occurrence->end->format('Y-m-d') : $occurrence->endTime($timezone)->format(DATE_ATOM),
		'allDay' => $allDay,
	];
}
header('Content-Type: application/json');
echo json_encode($events);
```

## Publish a filtered copy of a calendar

The model is immutable, so a filtered copy keeps everything else unchanged: timezones, overrides of
recurring events and unknown properties:

```php
use om\ICal\Calendar;
use om\ICal\Component;

$public = array_filter(
	$calendar->component->components,
	fn(Component $component) => strtoupper($component->property('CLASS')?->value ?? 'PUBLIC') === 'PUBLIC',
);
$copy = new Calendar($calendar->component->withComponents(array_values($public)));
echo $copy->serialize();
```

## Serve a calendar feed

A URL that calendar applications subscribe to:

```php
use om\ICal\Calendar;
use om\ICal\Event;
use om\ICal\Property;

$calendar = Calendar::create('-//example//releases//EN', name: 'Releases', properties: [
	Property::create('REFRESH-INTERVAL', 'PT12H', ['VALUE' => 'DURATION']),  // RFC 7986, a hint for clients
], events: array_map(fn(array $release) => Event::new(
	uid: "release-{$release['id']}@example.org",                            // a stable UID: updates replace the event
	summary: $release['name'],
	start: (new DateTimeImmutable($release['date']))->format('Ymd'),        // a date: an all-day event
	sequence: $release['revision'],
), $releases));

header('Content-Type: text/calendar; charset=utf-8');
echo $calendar->serialize();
```

Keep the UID of an event stable and increase `sequence` when it changes; clients then update the event
instead of adding a copy. Google Calendar refreshes subscribed calendars only every few hours.

## Send an invitation by e-mail

An iTIP request (RFC 5546): `METHOD:REQUEST`, an organizer and the attendees. The media type of the
message carries the method:

```php
use om\ICal\Calendar;
use om\ICal\Event;
use om\ICal\Value\CalAddress;

$invitation = Calendar::create('-//example//meetings//EN', method: 'REQUEST', events: [
	Event::new(
		uid: 'meeting-42@example.org',
		summary: 'Project kickoff',
		start: new DateTimeImmutable('2026-02-02 10:00', new DateTimeZone('Europe/Prague')),
		duration: new DateInterval('PT1H'),
		location: 'Room 1',
		organizer: CalAddress::create('boss@example.org', name: 'Boss'),
		attendees: [CalAddress::create('a@example.org', name: 'A', role: 'REQ-PARTICIPANT', status: 'NEEDS-ACTION', rsvp: true)],
		sequence: 0,
	),
]);

mail('a@example.org', 'Invitation: Project kickoff', $invitation->serialize(), [
	'From' => 'boss@example.org',
	'Content-Type' => 'text/calendar; charset=utf-8; method=REQUEST',
]);
```

To change or cancel the meeting, send the same UID with a higher `sequence`, and `method: 'CANCEL'` with
`status: 'CANCELLED'` for a cancellation. A mail library (Symfony Mailer, PHPMailer) can attach the
calendar next to a text part; keep the `method` parameter of the media type.

## Check an uploaded file

Reject broken files instead of importing a repaired version, and report RFC violations:

```php
use om\ICal;
use om\ICal\Exception\ICalException;
use om\ICal\Parser\ParseLimits;
use om\ICal\Parser\ParserMode;
use om\ICal\Validation\Severity;
use om\ICal\Validation\Validator;

try {
	$calendar = ICal::parser()
		->mode(ParserMode::Strict)
		->limits(new ParseLimits(maxFileSize: 2_000_000, maxComponents: 10_000))
		->parseFile($_FILES['calendar']['tmp_name'])
		->calendar();
} catch (ICalException $e) {
	exit("Invalid calendar: {$e->getMessage()} (line {$e->line()})");
}

foreach ((new Validator())->validate($calendar) as $issue) {
	if ($issue->severity === Severity::Error) {
		echo $issue, PHP_EOL;
	}
}
```

In permissive mode (the default) the same file is repaired and every repair is a warning of
`$result->warnings()`; see [parsing](parsing.md) and [validation](validation.md).
