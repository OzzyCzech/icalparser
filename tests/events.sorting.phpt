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

test('Natural sort order by date', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/basic.ics');
	$first = $cal->getEvents()->sorted()->getIterator()->current();
	Assert::same('1.1.2013 00:00:00', $first['DTSTART']->format('j.n.Y H:i:s'));
});

test('Reverse events sort (parseFile)', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/basic.ics');
	$first = $cal->getEvents()->reversed()->getIterator()->current();
	Assert::same('26.12.2015 00:00:00', $first['DTSTART']->format('j.n.Y H:i:s'));
});

test('Reverse events sort (parseString)', function () {
	$cal = new IcalParser();
	$cal->parseString(file_get_contents(__DIR__ . '/cal/basic.ics'));
	$first = $cal->getEvents()->reversed()->getIterator()->current();
	Assert::same('26.12.2015 00:00:00', $first['DTSTART']->format('j.n.Y H:i:s'));
});
