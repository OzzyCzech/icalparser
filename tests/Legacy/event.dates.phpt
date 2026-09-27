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

test('Events with wrong dates', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/wrong_dates.ics');
	$events = $cal->getEvents()->sorted();
	Assert::same('29.9.2014 00:00:00', $events[1]['DTSTART']->format('j.n.Y H:i:s'));
	Assert::same(null, $events[1]['DTEND']);

	Assert::same(null, $events[0]['DTSTART']);
	Assert::same('30.9.2014 00:00:00', $events[0]['DTEND']->format('j.n.Y H:i:s'));
});

