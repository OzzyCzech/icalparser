<?php
/**
 * @author Roman Ozana <ozana@omdesign.cz>
 */
use Tester\Assert;

require_once __DIR__ . '/../vendor/autoload.php';

$ical = new \om\IcalParser();

$out = $ical->parseFile(__DIR__ . '/basic.ics');

//var_dump($out);