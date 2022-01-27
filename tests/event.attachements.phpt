<?php
/**
 * @author PC Drew <pc@soprisapps.com>
 */

use om\IcalParser;
use Tester\Assert;
use Tester\Environment;
use function tests\test;

require_once __DIR__ . '/bootstrap.php';
date_default_timezone_set('Europe/Prague');

test('Event with multiple ATTACHMENTS', function () {
	$cal = new IcalParser();
	$cal->parseFile(__DIR__ . '/cal/multiple_attachments.ics');
	$first = $cal->getEvents()->getIterator()->current();

	// Backwards compatibility, there is only ever one key displayed
	Assert::hasKey('ATTACH', $first);
	Assert::type('string', $first['ATTACH']);

	// The new key 'ATTACHMENTS' is an array with 1 or more attachments
	Assert::type('array', $first['ATTACHMENTS']);
	Assert::count(2, $first['ATTACHMENTS']);
});
