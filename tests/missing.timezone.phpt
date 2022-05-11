<?php

use om\IcalParser;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('Europe/Prague');

test('Time zone should remain empty', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/missing-timezone.ics');

	Assert::null($cal->timezone);
});

test('Timezone should be same as current timezone', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/missing-timezone.ics');
	$dtstart = $cal->getEvents()->reversed()->getIterator()->current()['DTSTART'];
	/** @var DateTime $dtstart */
	Assert::same('Europe/Prague', $dtstart->getTimezone()->getName());
	Assert::same('7.11.2022', $dtstart->format('j.n.Y'));
});