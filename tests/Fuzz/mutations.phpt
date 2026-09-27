<?php
declare(strict_types=1);

/**
 * Corrupted versions of all fixtures must never produce PHP warnings, loop or fail with
 * other exceptions than the documented ones, in the permissive and the strict parser.
 */

use om\ICal;
use om\ICal\Exception\ICalException;
use om\ICal\Parser\ParserMode;
use om\RRule\RecurrenceLimits;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';
date_default_timezone_set('Europe/Prague');

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

mt_srand(20260927);
$files = glob(__DIR__ . '/../Fixtures/*/*.ics');
$mutations = [
	static fn(string $line): string => substr($line, 0, mt_rand(0, strlen($line))),
	static fn(string $line): string => $line . ';X=' . chr(mt_rand(1, 255)),
	static fn(string $line): string => str_replace(':', ';', $line),
	static fn(string $line): string => str_replace(['T', 'Z'], ['', 'Q'], $line),
	static fn(string $line): string => (string) preg_replace('/\d/', (string) mt_rand(0, 9), $line),
	static fn(string $line): string => '"' . $line,
	static fn(string $line): string => strtolower($line),
	static fn(string $line): string => '',
	static fn(string $line): string => str_repeat($line, 2),
	static fn(string $line): string => (string) preg_replace('/=([A-Z0-9-]+)/', '=' . ['0', '-1', '99', 'XX', '', '53MO', '-366'][mt_rand(0, 6)], $line),
	static fn(string $line): string => $line . "\xC3",
	static fn(string $line): string => ' ' . $line,
];

$from = new DateTimeImmutable('2000-01-01');
$to = new DateTimeImmutable('2030-01-01');
$parser = ICal::parser()->recurrenceLimits(new RecurrenceLimits(maxInstances: 2000, maxIterations: 200000));
$parsed = 0;
for ($i = 0; $i < 300; $i++) {
	$lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($files[array_rand($files)])));
	for ($count = mt_rand(1, 6); $count > 0; $count--) {
		$key = array_rand($lines);
		$lines[$key] = $mutations[array_rand($mutations)]($lines[$key]);
	}
	$content = implode("\r\n", $lines);
	$started = hrtime(true);

	foreach ([$parser, $parser->mode(ParserMode::Strict)] as $mode => $current) {
		try {
			$result = $current->parse($content);
			foreach ($result->calendars() as $calendar) {
				foreach ([...$calendar->events(), ...$calendar->todos(), ...$calendar->journals()] as $item) {
					$item->summary();
					$item->start();
					$item->end();
					$item->attendees();
					foreach ($item->occurrences(20) as $occurrence) {
						$occurrence->start->format('c');
					}
					foreach ($item->alarms() as $alarm) {
						$alarm->trigger();
					}
				}
				$calendar->occurrencesBetween($from, $to);
				(new ICal\Validation\Validator())->validate($calendar);
				ICal::parse($calendar->serialize() ?: "BEGIN:VCALENDAR\r\nEND:VCALENDAR");
			}
			$mode === 0 && $parsed++;
		} catch (ICalException $e) {
			Assert::type(ICalException::class, $e); // documented exceptions only
		}
	}
	Assert::true((hrtime(true) - $started) < 2e9, 'no input takes longer than 2 seconds');
}
Assert::true($parsed > 200);
