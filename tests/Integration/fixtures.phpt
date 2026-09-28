<?php
declare(strict_types=1);

/**
 * Golden files: every calendar in tests/Fixtures has an .expected.json with the normalized
 * output of the parser, the typed model, the first occurrences and the validator.
 *
 * Regenerate after an intended change: UPDATE_SNAPSHOTS=1 composer test:integration
 */

use om\ICal;
use om\ICal\Calendar;
use om\ICal\Item;
use om\ICal\Parser\ParseWarning;
use om\ICal\Validation\Issue;
use om\ICal\Validation\Validator;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Image;
use Tester\Assert;
use Tester\Environment;

require_once __DIR__ . '/../bootstrap.php';
date_default_timezone_set('UTC');

const OCCURRENCES = 10;

function describe(?DateTimeValue $value): ?string {
	return match (true) {
		$value === null => null,
		$value->isDate() => $value->format('Y-m-d') . ' (date)',
		$value->isFloating() => $value->format('Y-m-d H:i:s') . ' (floating' . ($value->tzid !== null ? ", unresolved $value->tzid" : '') . ')',
		$value->isUtc() => $value->format('Y-m-d H:i:s') . ' UTC',
		default => $value->format('Y-m-d H:i:s P') . " ($value->tzid = " . $value->timezone()?->getName() . ')',
	};
}

function image(Image $image): string {
	return ($image->uri ?? 'binary, ' . strlen((string) $image->data) . ' bytes') . ' ' . implode(',', $image->display()) . ($image->mediaType() !== null ? ' ' . $image->mediaType() : '');
}

function item(Item $item): array {
	$occurrences = [];
	try {
		foreach ($item->occurrences(OCCURRENCES) as $occurrence) {
			$occurrences[] = describe($occurrence->start) . ' – ' . describe($occurrence->end) . ($occurrence->isModified() ? ' [' . $occurrence->summary() . ']' : '');
		}
	} catch (Throwable $e) {
		$occurrences[] = $e::class . ': ' . $e->getMessage();
	}
	return array_filter([
		'type' => $item->component->name,
		'uid' => $item->uid(),
		'summary' => $item->summary(),
		'start' => describe($item->start()),
		'end' => describe($item->end()),
		'recurrenceId' => describe($item->recurrenceId()),
		'rrules' => array_map(fn($rule) => $rule->toString(), $item->recurrenceRules()),
		'overrides' => count($item->overrides()),
		'status' => $item->status(),
		'categories' => $item->categories(),
		'color' => $item->color(),
		'images' => array_map(image(...), $item->images()),
		'conferences' => array_map(fn($conference) => $conference->uri . ' ' . implode(',', $conference->features()) . ($conference->label() !== null ? ' "' . $conference->label() . '"' : ''), $item->conferences()),
		'attendees' => array_map(fn($a) => $a->email() . ' ' . $a->role() . '/' . $a->status(), $item->attendees()),
		'alarms' => array_map(fn($alarm) => $alarm->action() . ' ' . (is_object($trigger = $alarm->trigger()) ? ($trigger instanceof DateInterval ? ICal\Value\Duration::format($trigger) : describe($trigger)) : '-'), $item->alarms()),
		'x-properties' => array_values(array_unique(array_map(fn($p) => $p->name, array_filter($item->component->properties, fn($p) => str_starts_with($p->name, 'X-'))))),
		'occurrences' => $occurrences,
	], fn($value) => $value !== null && $value !== [] && $value !== 0);
}

function calendar(Calendar $calendar): array {
	return array_filter([
		'name' => $calendar->name(),
		'method' => $calendar->method(),
		'timezone' => $calendar->timezone()?->getName(),
		'color' => $calendar->color(),
		'source' => $calendar->source(),
		'refreshInterval' => ($interval = $calendar->refreshInterval()) === null ? null : ICal\Value\Duration::format($interval),
		'images' => array_map(image(...), $calendar->images()),
		'timezones' => array_map(fn($definition) => $definition->tzid() . ' => ' . ($definition->resolve()?->timezone->getName() ?? 'unresolved') . ($definition->resolve() ? ' (' . $definition->resolve()->source->name . ')' : ''), $calendar->timezones()),
		'items' => array_map(item(...), [...$calendar->events(), ...$calendar->todos(), ...$calendar->journals(), ...$calendar->freeBusy()]),
		'issues' => array_map(fn(Issue $issue) => (string) $issue, (new Validator())->validate($calendar)),
	], fn($value) => $value !== null && $value !== []);
}

$update = (bool) getenv('UPDATE_SNAPSHOTS');
$files = glob(__DIR__ . '/../Fixtures/*/*.ics');
Assert::true(count($files) > 40);
foreach ($files as $file) {
	$result = ICal::parser()->parseFile($file);
	$actual = json_encode([
		'warnings' => array_map(fn(ParseWarning $warning) => (string) $warning, $result->warnings()),
		'calendars' => array_map(calendar(...), $result->calendars()),
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	$target = substr($file, 0, -4) . '.expected.json';
	if ($update) {
		file_put_contents($target, $actual);
		continue;
	}
	Assert::true(is_file($target), "Missing $target, run UPDATE_SNAPSHOTS=1 composer test:integration");
	Assert::same(file_get_contents($target), $actual, basename(dirname($file)) . '/' . basename($file));
}
if ($update) {
	Environment::skip('Golden files were updated.');
}
