<?php
declare(strict_types=1);

/**
 * VTIMEZONE definitions created from PHP timezones (VTimezoneBuilder).
 */

use om\ICal\Component;
use om\ICal\Event;
use om\ICal\Serializer;
use om\ICal\Timezone\VTimezoneBuilder;
use om\ICal\Value\UtcOffset;
use om\RRule\Expander;
use om\RRule\Rule;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

/**
 * Offset at $from and the offset changes in ($from, $to] defined by the observances.
 *
 * @return array{int, list<array{int, int}>}
 */
function fromDefinition(Component $vtimezone, int $from, int $to): array {
	$all = [];
	foreach ($vtimezone->components as $observance) {
		$start = (new DateTimeImmutable($observance->property('DTSTART')->value, new DateTimeZone('UTC')));
		$offsetFrom = UtcOffset::parse($observance->property('TZOFFSETFROM')->value);
		$offsetTo = UtcOffset::parse($observance->property('TZOFFSETTO')->value);
		$local = [$start->getTimestamp()];
		$until = null;
		if (($rrule = $observance->property('RRULE')?->value) !== null) {
			$rule = Rule::fromString($rrule);
			$until = $rule->until?->getTimestamp();
			$local = iterator_to_array(new Expander(Rule::fromString((string) preg_replace('/;UNTIL=[^;]+/', '', $rrule)), $start, $to + 3 * 86400), false);
		}
		foreach ($local as $timestamp) {
			if ($until === null || $timestamp - $offsetFrom <= $until) {
				$all[] = [$timestamp - $offsetFrom, $offsetTo];
			}
		}
	}
	sort($all);
	$initial = UtcOffset::parse($vtimezone->components[0]->property('TZOFFSETFROM')->value);
	$changes = [];
	foreach ($all as [$timestamp, $offset]) {
		if ($timestamp <= $from) {
			$initial = $offset;
		} elseif ($timestamp <= $to) {
			$changes[] = [$timestamp, $offset];
		}
	}
	return [$initial, changes($initial, $changes)];
}

/**
 * The same from PHP.
 *
 * @return array{int, list<array{int, int}>}
 */
function expected(DateTimeZone $timezone, int $from, int $to): array {
	$initial = $timezone->getOffset(new DateTimeImmutable('@' . $from));
	$changes = [];
	foreach (array_slice($timezone->getTransitions($from, $to), 1) as $transition) {
		if ($transition['ts'] > $from) {
			$changes[] = [$transition['ts'], $transition['offset']];
		}
	}
	return [$initial, changes($initial, $changes)];
}

/**
 * @param list<array{int, int}> $changes
 * @return list<array{int, int}>
 */
function changes(int $offset, array $changes): array {
	$result = [];
	foreach ($changes as [$timestamp, $next]) {
		if ($next !== $offset) {
			$result[] = [$timestamp, $next];
			$offset = $next;
		}
	}
	return $result;
}

test('Europe/Prague: yearly rules of the last Sunday of March and October', function () {
	$vtimezone = VTimezoneBuilder::build(new DateTimeZone('Europe/Prague'), new DateTimeImmutable('2025-01-05'), new DateTimeImmutable('2027-01-05'));
	Assert::same(implode("\r\n", [
		'BEGIN:VTIMEZONE',
		'TZID:Europe/Prague',
		'X-LIC-LOCATION:Europe/Prague',
		'BEGIN:STANDARD',
		'DTSTART:20241027T030000',
		'TZOFFSETFROM:+0200',
		'TZOFFSETTO:+0100',
		'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
		'TZNAME:CET',
		'END:STANDARD',
		'BEGIN:DAYLIGHT',
		'DTSTART:20250330T020000',
		'TZOFFSETFROM:+0100',
		'TZOFFSETTO:+0200',
		'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
		'TZNAME:CEST',
		'END:DAYLIGHT',
		'END:VTIMEZONE',
	]) . "\r\n", Serializer::serialize($vtimezone));
});

test('Rules that ended get UNTIL, irregular transitions are observances of their own', function () {
	// the United States changed the rules in 2007
	$vtimezone = VTimezoneBuilder::build(new DateTimeZone('America/New_York'), new DateTimeImmutable('2005-01-01'), new DateTimeImmutable('2009-01-01'), 'Eastern');
	Assert::same('Eastern', $vtimezone->property('TZID')->value);
	$rules = array_map(fn(Component $observance) => $observance->property('DTSTART')->value . ' ' . $observance->property('RRULE')?->value, $vtimezone->components);
	Assert::same([
		'20041031T020000 FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU;UNTIL=20061029T060000Z',
		'20050403T020000 FREQ=YEARLY;BYMONTH=4;BYDAY=1SU;UNTIL=20060402T070000Z',
		'20070311T020000 FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
		'20071104T020000 FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
	], $rules);
});

test('Timezones without transitions have one observance', function () {
	$tokyo = VTimezoneBuilder::build(new DateTimeZone('Asia/Tokyo'), new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01'));
	Assert::count(1, $tokyo->components);
	Assert::same(['STANDARD', '+0900', 'JST', null], [$tokyo->components[0]->name, $tokyo->components[0]->property('TZOFFSETTO')->value, $tokyo->components[0]->property('TZNAME')->value, $tokyo->components[0]->property('RRULE')]);

	$fixed = VTimezoneBuilder::build(new DateTimeZone('Etc/GMT+5'), new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01'));
	Assert::same(['19700101T000000', '-0500', '-0500'], [$fixed->components[0]->property('DTSTART')->value, $fixed->components[0]->property('TZOFFSETFROM')->value, $fixed->components[0]->property('TZOFFSETTO')->value]);
	Assert::exception(fn() => VTimezoneBuilder::build(new DateTimeZone('UTC'), 10, 5), InvalidArgumentException::class);
});

test('The definitions give the transitions of PHP for every timezone', function () {
	$ranges = [
		[strtotime('2024-06-01'), strtotime('2028-06-01')],
		[strtotime('1995-01-01'), strtotime('2010-01-01')],
		[strtotime('2036-01-01'), strtotime('2045-01-01')],
	];
	$count = 0;
	foreach (DateTimeZone::listIdentifiers() as $name) {
		$timezone = new DateTimeZone($name);
		foreach ($ranges as [$from, $to]) {
			$vtimezone = VTimezoneBuilder::build($timezone, $from, $to);
			Assert::same(expected($timezone, $from, $to), fromDefinition($vtimezone, $from, $to), "$name " . date('Y', $from) . '-' . date('Y', $to));
			// the rules without UNTIL continue after the range (while the timezone keeps them; in Africa/Cairo
			// the "last Thursday at 24:00" rule moves to November in 2030, in America/Santiago "the first Sunday on or after
			// September 2" is sometimes the second Sunday: a yearly RRULE of the nth weekday cannot express them)
			if (in_array($name, ['Europe/Prague', 'Europe/London', 'America/New_York', 'Australia/Sydney', 'Australia/Lord_Howe', 'Pacific/Chatham', 'Asia/Tokyo'], true)) {
				Assert::same(expected($timezone, $to, $to + 3 * 366 * 86400), fromDefinition($vtimezone, $to, $to + 3 * 366 * 86400), "$name after " . date('Y', $to));
			}
			$count++;
		}
	}
	Assert::true($count > 1000);
});

test('forComponents(): a definition for every TZID used, for the range of the dates', function () {
	$event = Event::new(uid: 'a', stamp: '20260101T000000Z', start: new DateTimeImmutable('2026-01-05 09:30', new DateTimeZone('Europe/Prague')), end: new DateTimeImmutable('2026-01-05 10:30', new DateTimeZone('America/New_York')), exdates: [], properties: [
		'X-OTHER' => new DateTimeImmutable('2026-02-01 10:00', new DateTimeZone('Asia/Tokyo')),
	]);
	$definitions = VTimezoneBuilder::forComponents([$event->component], ['Asia/Tokyo']);
	Assert::same(['Europe/Prague', 'America/New_York'], array_map(fn(Component $c) => $c->property('TZID')->value, $definitions));
	Assert::same([], VTimezoneBuilder::forComponents([new Component('VEVENT', [om\ICal\Property::create('DTSTART', '20260105T093000', ['TZID' => 'Custom Zone'])])]), 'only IANA timezones');
});
