<?php
declare(strict_types=1);

use om\IcalParser;
use om\ParserOptions;

require_once __DIR__ . '/../vendor/autoload.php';

// A local file or a URL, e.g. a public Google calendar:
// https://calendar.google.com/calendar/ical/cs.czech%23holiday%40group.v.calendar.google.com/public/basic.ics
// Never pass a user-supplied value here: parseFile() reads any path or stream wrapper.
$source = __DIR__ . '/calendar.ics';

$from = new DateTimeImmutable('today');
$to = $from->modify('+3 months');

$title = 'Calendar';
$error = null;
$events = [];
try {
	// unbounded recurring events are expanded only until the end of the shown period
	$calendar = new IcalParser(new ParserOptions(untilInterval: new DateInterval('P3M')));
	$calendar->parseFile($source);
	$title = $calendar->data['X-WR-CALNAME'] ?? $title;

	foreach ($calendar->getEvents()->sorted() as $event) {
		$start = $event['DTSTART'] ?? null;
		$end = $event['DTEND'] ?? $start;
		if ($start instanceof DateTimeInterface && $end >= $from && $start < $to) {
			$events[] = $event;
		}
	}
} catch (Throwable $e) {
	$error = $e->getMessage();
}

function e(mixed $value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES);
}

function when(array $event): string {
	$start = DateTimeImmutable::createFromInterface($event['DTSTART']);
	$end = DateTimeImmutable::createFromInterface($event['DTEND'] ?? $start);
	if ($start->format('His') === '000000' && $end->format('His') === '000000') { // all-day event, DTEND is exclusive
		$last = $end->modify('-1 day');
		return $last > $start ? $start->format('j M') . ' – ' . $last->format('j M Y') : $start->format('j M Y');
	}
	return $start->format('j M Y, H:i') . ' – ' . $end->format($end->format('Ymd') === $start->format('Ymd') ? 'H:i' : 'j M Y, H:i');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>iCal parser example</title>
	<style>
		body { font: 16px/1.5 system-ui, sans-serif; max-width: 48rem; margin: 2rem auto; padding: 0 1rem; color: #222; }
		table { width: 100%; border-collapse: collapse; }
		th, td { text-align: left; padding: .5rem; border-bottom: 1px solid #ddd; vertical-align: top; }
		th { font-size: .875rem; color: #666; }
		.muted { color: #666; font-size: .875rem; }
		.error { padding: 1rem; background: #fdecea; color: #611a15; border-radius: .25rem; }
	</style>
</head>
<body>
<h1><?= e($title) ?></h1>
<p class="muted">Events from <?= $from->format('j M Y') ?> to <?= $to->format('j M Y') ?></p>

<?php if ($error !== null): ?>
	<p class="error">The calendar cannot be read: <?= e($error) ?></p>
<?php elseif ($events === []): ?>
	<p>No events in this period.</p>
<?php else: ?>
	<table>
		<thead>
		<tr><th>When</th><th>Event</th></tr>
		</thead>
		<tbody>
		<?php foreach ($events as $event): ?>
			<tr>
				<td><?= e(when($event)) ?></td>
				<td>
					<?= e($event['SUMMARY'] ?? '(no title)') ?>
					<?php if (!empty($event['RECURRING'])): ?><span class="muted">↻</span><?php endif ?>
					<?php if (!empty($event['LOCATION'])): ?><br><span class="muted"><?= e($event['LOCATION']) ?></span><?php endif ?>
				</td>
			</tr>
		<?php endforeach ?>
		</tbody>
	</table>
<?php endif ?>
</body>
</html>
