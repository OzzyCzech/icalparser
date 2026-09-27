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

test('Blank description test', function () {
	$cal = new IcalParser();
	$results = $cal->parseFile(__DIR__ . '/cal/blank_description.ics');
	$first = $cal->getEvents()->getIterator()->current();

	Assert::hasKey('DESCRIPTION', $first);
	Assert::same('', $first['DESCRIPTION']);
});

test('Multiple lines description', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/multiline_description.ics');
	$events = $cal->getEvents()->sorted();
	$first = $events->getIterator()->current();

	Assert::same('30.6.2012 06:00:00', $first['DTSTART']->format('j.n.Y H:i:s'));
	Assert::same("Here is a description that spans multiple lines!\n\nThis should be on a new line as well because the description contains newline characters.", $first['DESCRIPTION']);
});

