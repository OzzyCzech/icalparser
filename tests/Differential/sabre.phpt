<?php
declare(strict_types=1);

/**
 * Differential test against sabre/vobject. It is not a dependency of the project; the CI job
 * installs it: composer require --dev sabre/vobject --no-update && composer update
 *
 * sabre/vobject supports a subset of the rule parts well: BY* parts that only limit
 * DAILY or HOURLY rules and BYSETPOS with most frequencies are ignored or applied
 * differently, so only the subset it supports is compared. Series with EXDATE, RDATE and
 * RECURRENCE-ID overrides of the fixtures are compared through its EventIterator, except
 * series in custom VTIMEZONE definitions, which sabre/vobject does not evaluate.
 */

use om\ICal;
use om\RRule\Expander;
use om\RRule\Rule;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\RRuleIterator;
use Tester\Assert;
use Tester\Environment;

require_once __DIR__ . '/../bootstrap.php';

if (!class_exists(RRuleIterator::class)) {
	Environment::skip('sabre/vobject is not installed.');
}

mt_srand(2445);
$pick = static fn(array $values): mixed => $values[array_rand($values)];
$zones = ['UTC', 'Europe/Prague', 'America/New_York', 'Australia/Sydney'];
$mismatches = [];
for ($i = 0; $i < 1000; $i++) {
	$parts = $pick([
		fn() => ['FREQ' => 'DAILY'],
		fn() => ['FREQ' => 'WEEKLY', 'BYDAY' => $pick(['MO', 'TU,TH', 'SA,SU', 'MO,WE,FR'])] + (mt_rand(0, 1) ? ['WKST' => $pick(['MO', 'SU'])] : []),
		fn() => ['FREQ' => 'MONTHLY', 'BYMONTHDAY' => $pick(['1', '15', '-1', '1,15', '31'])],
		fn() => ['FREQ' => 'MONTHLY', 'BYDAY' => $pick(['1MO', '-1FR', '2TU,4TU', '3WE'])],
		fn() => ['FREQ' => 'MONTHLY', 'BYDAY' => 'MO,TU,WE,TH,FR', 'BYSETPOS' => $pick(['1', '-1', '-2'])],
		fn() => ['FREQ' => 'YEARLY', 'BYMONTH' => (string) mt_rand(1, 12), 'BYDAY' => $pick(['1SU', '-1SU', '2MO'])],
		fn() => ['FREQ' => 'YEARLY', 'BYMONTH' => (string) mt_rand(1, 12), 'BYMONTHDAY' => $pick(['1', '15', '-1'])],
		fn() => ['FREQ' => 'YEARLY', 'BYYEARDAY' => $pick(['1', '100', '-1', '1,200'])],
	])();
	if (mt_rand(0, 2) === 0) {
		$parts['INTERVAL'] = (string) mt_rand(2, 4);
	}
	$parts['COUNT'] = (string) mt_rand(2, 40);
	$rule = implode(';', array_map(fn($name, $value) => "$name=$value", array_keys($parts), $parts));
	$start = (new DateTimeImmutable('@' . mt_rand(946684800, 1893456000)))->setTimezone(new DateTimeZone($pick($zones)))->setTime(mt_rand(0, 23), 0);

	$theirs = [];
	foreach (new RRuleIterator($rule, $start) as $date) {
		$theirs[] = $date->getTimestamp();
	}
	$ours = iterator_to_array(new Expander(Rule::fromString($rule), $start), false);
	if ($ours !== $theirs) {
		$mismatches[] = "$rule from " . $start->format('c e');
	}
}
Assert::same([], $mismatches);

// whole series with EXDATE, RDATE and overrides
$from = new DateTimeImmutable('1990-01-01T00:00:00Z');
$to = new DateTimeImmutable('2040-01-01T00:00:00Z');
$series = $skipped = 0;
foreach (glob(__DIR__ . '/../Fixtures/{Google,Exchange,Outlook,Nextcloud,Fastmail,RFC5545,Regression}/*.ics', GLOB_BRACE) as $file) {
	$content = (string) file_get_contents($file);
	$calendar = ICal::parse($content);
	$vcalendar = Reader::read($content);
	foreach ($calendar->events() as $event) {
		if (!$event->isRecurring() || $event->uid() === null || $event->recurrenceRules() === [] || count($event->recurrenceRules()) > 1) {
			continue;
		}
		$tzid = $event->start()?->tzid;
		if ($tzid !== null && $calendar->values()->timezone($tzid)?->source === ICal\Timezone\TimezoneSource::VTimezone && !in_array($tzid, DateTimeZone::listIdentifiers(), true)) {
			$skipped++; // sabre/vobject does not evaluate custom VTIMEZONE rules (it uses UTC)
			continue;
		}
		$ours = [];
		foreach ($event->occurrences(100) as $occurrence) {
			$ours[] = $occurrence->startTime(new DateTimeZone('UTC'))->getTimestamp();
		}
		sort($ours);
		$theirs = [];
		try {
			$iterator = new EventIterator($vcalendar, $event->uid(), new DateTimeZone('UTC'));
			$iterator->fastForward($from);
			while ($iterator->valid() && count($theirs) < count($ours)) {
				$theirs[] = $iterator->getDTStart()->getTimestamp();
				$iterator->next();
			}
		} catch (Sabre\VObject\InvalidDataException) {
			$skipped++; // e.g. the Google style RDATE 20271210Z, which icalparser accepts
			continue;
		}
		sort($theirs);
		Assert::same($theirs, $ours, basename($file) . ' ' . $event->uid());
		$series++;
	}
}
Assert::true($series >= 8, "$series series compared");
echo "1000 rules and $series series match sabre/vobject, $skipped series rejected by sabre\n";
