<?php
/**
 * Copyright (c) 2004-2022 Roman Ožana (https://ozana.cz)
 *
 * @license BSD-3-Clause
 * @author Roman Ožana <roman@ozana.cz>
 */

use om\IcalParser;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('Europe/Prague');

test('Multiple categories test', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/multiple_categories.ics');
	$events = $cal->getEvents()->sorted();

	foreach ($events as $event) {
		Assert::type('array', $event['CATEGORIES']);
		Assert::same(['one', 'two', 'three'], $event['CATEGORIES']);
	}
});
