<?php
declare(strict_types=1);

use om\ICal;
use om\ICal\Occurrence;
use om\ICal\Parser\ParserMode;

require_once __DIR__ . '/../vendor/autoload.php';

// Browse the examples with: php -S localhost:8000 -t examples
// A local file or a URL, e.g. a public Google calendar:
// https://calendar.google.com/calendar/ical/cs.czech%23holiday%40group.v.calendar.google.com/public/basic.ics
// Never pass a user-supplied value here: parseFile() reads any path or stream wrapper.
$source = __DIR__ . '/calendar.ics';

$from = new DateTimeImmutable('today');
$to = $from->modify('+3 months');

$title = 'Calendar';
$error = null;
$occurrences = [];
$warnings = [];
try {
	$result = ICal::parser()->mode(ParserMode::Permissive)->parseFile($source);
	$calendar = $result->calendar();
	$warnings = $result->warnings();
	$title = $calendar->name() ?? $title;
	// recurring events are expanded lazily, only for the shown period
	$occurrences = $calendar->occurrencesBetween($from, $to);
} catch (Throwable $e) {
	$error = $e->getMessage();
}

function e(mixed $value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES);
}

function when(Occurrence $occurrence): string {
	$start = $occurrence->start;
	$end = $occurrence->end;
	if ($occurrence->isAllDay()) { // the end of an all-day event is exclusive
		$last = $end->add(DateInterval::createFromDateString('-1 day'));
		return $last->format('Ymd') > $start->format('Ymd') ? $start->format('j M') . ' – ' . $last->format('j M Y') : $start->format('j M Y');
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
		nav { margin-bottom: 1.5rem; font-size: .875rem; }
		nav a { margin-right: 1rem; }
	</style>
</head>
<body>
<nav>
	Examples:
	<a href="index.php">Upcoming events</a>
	<a href="create.php" title="create a calendar with named arguments">Create a calendar</a>
	<a href="validate.php" title="repairs of the parser and RFC 5545 violations">Validate</a>
	<a href="stream.php" title="read a large calendar event by event">Stream</a>
	<a href="calendar.ics">calendar.ics</a>
</nav>
<h1><?= e($title) ?></h1>
<p class="muted">Events from <?= $from->format('j M Y') ?> to <?= $to->format('j M Y') ?></p>

<?php if ($error !== null): ?>
	<p class="error">The calendar cannot be read: <?= e($error) ?></p>
<?php elseif ($occurrences === []): ?>
	<p>No events in this period.</p>
<?php else: ?>
	<table>
		<thead>
		<tr><th>When</th><th>Event</th></tr>
		</thead>
		<tbody>
		<?php foreach ($occurrences as $occurrence): ?>
			<tr>
				<td><?= e(when($occurrence)) ?></td>
				<td>
					<?= e($occurrence->summary() ?? '(no title)') ?>
					<?php if ($occurrence->isRecurring()): ?><span class="muted" title="recurring">↻</span><?php endif ?>
					<?php if ($occurrence->isModified()): ?><span class="muted">(changed)</span><?php endif ?>
					<?php if ($occurrence->item->location()): ?><br><span class="muted"><?= e($occurrence->item->location()) ?></span><?php endif ?>
				</td>
			</tr>
		<?php endforeach ?>
		</tbody>
	</table>
<?php endif ?>
<?php if ($warnings !== []): ?>
	<p class="muted">The calendar was repaired: <?= e(implode('; ', array_map('strval', $warnings))) ?></p>
<?php endif ?>
</body>
</html>
