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

test('URL parsing check', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/url.ics');
	$first = $cal->getEvents()->getIterator()->current();

	Assert::hasKey('URL', $first);
	Assert::same($first['URL'], urlencode('https://github.com/OzzyCzech/icalparser/'));
});
