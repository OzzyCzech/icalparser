<?php
declare(strict_types=1);

/**
 * Robustness: corrupted versions of the sample calendars must never produce PHP warnings or
 * unexpected exceptions. The mutations are seeded, so every run checks the same inputs.
 */

use om\IcalParser;
use om\ParserOptions;
use Tester\Assert;

require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('Europe/Prague');

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

mt_srand(20260927);
$files = glob(__DIR__ . '/cal/*.ics');
$mutations = [
	static fn(string $line): string => substr($line, 0, mt_rand(0, strlen($line))),
	static fn(string $line): string => $line . ';X=' . chr(mt_rand(32, 126)),
	static fn(string $line): string => str_replace(':', ';', $line),
	static fn(string $line): string => str_replace(['T', 'Z'], ['', 'Q'], $line),
	static fn(string $line): string => (string) preg_replace('/\d/', (string) mt_rand(0, 9), $line),
	static fn(string $line): string => '"' . $line,
	static fn(string $line): string => strtolower($line),
	static fn(string $line): string => '',
	static fn(string $line): string => str_repeat($line, 2),
	static fn(string $line): string => (string) preg_replace('/=([A-Z0-9-]+)/', '=' . ['0', '-1', '99', 'XX', ''][mt_rand(0, 4)], $line),
];

$parsed = 0;
for ($i = 0; $i < 300; $i++) {
	$lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($files[array_rand($files)])));
	for ($count = mt_rand(1, 5); $count > 0; $count--) {
		$key = array_rand($lines);
		$lines[$key] = $mutations[array_rand($mutations)]($lines[$key]);
	}
	$parser = new IcalParser(new ParserOptions(now: new DateTimeImmutable('2026-01-01'), maxOccurrences: 5000));
	try {
		$parser->parseString(implode("\r\n", $lines));
	} catch (InvalidArgumentException $e) {
		Assert::same('Invalid ICAL data format', $e->getMessage()); // BEGIN:VCALENDAR was destroyed
		continue;
	}
	foreach ($parser->getEvents()->sorted() as $event) {
		Assert::type('array', $event);
	}
	$parsed++;
}
Assert::true($parsed > 250);
