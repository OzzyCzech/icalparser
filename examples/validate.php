<?php
declare(strict_types=1);

/**
 * Report problems of a calendar: repairs made by the parser and RFC 5545 violations.
 *
 * Usage: php examples/validate.php calendar.ics
 */

use om\ICal;
use om\ICal\Validation\Severity;
use om\ICal\Validation\Validator;

require_once __DIR__ . '/../vendor/autoload.php';

$result = ICal::parser()->parseFile($argv[1] ?? __DIR__ . '/calendar.ics');
foreach ($result->warnings() as $warning) {
	echo "repaired: $warning\n";
}

$errors = 0;
foreach ($result->calendars() as $calendar) {
	foreach ((new Validator())->validate($calendar) as $issue) {
		echo $issue, "\n";
		$errors += $issue->severity === Severity::Error ? 1 : 0;
	}
}
echo $errors === 0 ? "valid\n" : "$errors errors\n";
exit($errors === 0 ? 0 : 1);
