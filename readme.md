[![Packagist Version](https://img.shields.io/packagist/v/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist Downloads](https://img.shields.io/packagist/dm/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist License](https://img.shields.io/packagist/l/om/icalparser?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/om/icalparser/php?style=for-the-badge)](https://packagist.org/packages/om/icalparser)
[![PHP Tests](https://img.shields.io/github/actions/workflow/status/OzzyCzech/icalparser/php.yml?style=for-the-badge)](https://github.com/OzzyCzech/icalparser/actions/workflows/php.yml)

# PHP iCal Parser

Internet Calendaring Parser [rfc2445](https://www.ietf.org/rfc/rfc2445.txt) or iCal parser is simple PHP class for parsing format into array.

[!["Buy Me A Coffee"](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://www.buymeacoffee.com/ozzyczech)

## How to install

The recommended way to is via Composer:

```shell script
composer require om/icalparser
```

## Usage and example

```php
<?php
use om\IcalParser;
require_once '../vendor/autoload.php';

$cal = new IcalParser();
$results = $cal->parseFile(
	'https://www.google.com/calendar/ical/cs.czech%23holiday%40group.v.calendar.google.com/public/basic.ics'
);

foreach ($cal->getEvents()->sorted() as $event) {
	printf('%s - %s' . PHP_EOL, $event['DTSTART']->format('j.n.Y'), $event['SUMMARY']);
	
}
```

Each property of each event is available using the property name (in capital letters) as a key. 
There are some special cases:

- multiple attendees with individual parameters: use `ATTENDEES` as key to get all attendees in the following scheme:
```php
[
	[
		'ROLE' => 'REQ-PARTICIPANT',
		'PARTSTAT' => 'NEEDS-ACTION',
		'CN' => 'John Doe',
		'VALUE' => 'mailto:john.doe@example.org'
	],
	[
		'ROLE' => 'REQ-PARTICIPANT',
		'PARTSTAT' => 'NEEDS-ACTION',
		'CN' => 'Test Example',
		'VALUE' => 'mailto:test@example.org'
	]
]
```
- organizer's name: the *CN* parameter of the organizer property can be retrieved using the key `ORGANIZER-CN`

You can run example with [PHP Built-in web server](https://www.php.net/manual/en/features.commandline.webserver.php) as follow:

```shell
php -S localhost:8000 -t example
```

## Requirements

- PHP 8.1+

## Run tests

iCal parser using [Nette Tester](https://github.com/nette/tester). The tests can be invoked via [composer](https://getcomposer.org/).

```shell script
composer update
composer test
```

