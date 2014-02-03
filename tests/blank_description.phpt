<?php
/**
 * @author Roman Ozana <ozana@omdesign.cz>
 */
use Tester\Assert;

require_once __DIR__ . '/../vendor/autoload.php';
\Tester\Environment::setup();

$ical = new \om\IcalParser();

$data = explode("\n", file_get_contents(__DIR__ . '/cal/blank_description.ics'));
$results = $ical->parseFile(__DIR__ . '/cal/blank_description.ics');

Assert::same('', $results['VEVENT'][0]['DESCRIPTION']);
Assert::same('America/Los_Angeles', $ical->timezone->getName());