<?php
declare(strict_types=1);

/**
 * The bundled timezone maps point to timezones known to PHP.
 */

use Tester\Assert;

require_once __DIR__ . '/../../bootstrap.php';

foreach (['windows', 'aliases'] as $file) {
	$map = require __DIR__ . "/../../../resources/timezones/$file.php";
	Assert::true(count($map) > 100, $file);
	foreach ($map as $name => $zone) {
		Assert::noError(fn() => new DateTimeZone($zone));
	}
}
$merged = require __DIR__ . '/../../../src/WindowsTimezones.php';
Assert::same('Europe/Berlin', $merged['W. Europe Standard Time']);
Assert::same('America/Los_Angeles', $merged['(UTC-08:00) Pacific Time (US & Canada)']);
Assert::same('America/Tijuana', $merged['Pacific Standard Time (Mexico)']);
