<?php
declare(strict_types=1);

/**
 * Read a large calendar event by event with constant memory.
 *
 * Usage: php examples/stream.php calendar.ics
 */

use om\ICal;
use om\ICal\Event;

require_once __DIR__ . '/../vendor/autoload.php';

$file = $argv[1] ?? __DIR__ . '/calendar.ics';

if (PHP_SAPI !== 'cli') {
	header('Content-Type: text/plain; charset=utf-8');
}
$count = 0;
foreach (ICal::parser()->stream($file, fn($warning) => error_log("warning: $warning")) as $item) {
	if ($item instanceof Event) {
		$start = $item->start();
		printf("%-17s %s\n", $start === null ? '-' : $start->format($start->isDate() ? 'Y-m-d' : 'Y-m-d H:i'), $item->summary() ?? '(no title)');
		$count++;
	}
}
printf("%d events, peak memory %.1f MB\n", $count, memory_get_peak_usage() / 1048576);
