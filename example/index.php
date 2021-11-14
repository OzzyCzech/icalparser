<!DOCTYPE html>
<html lang="cs-CZ">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

	<title>Ical Parser example</title>

	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">

</head>
<body>
<div class="container">
	<h1>Czech holidays</h1>

	<ul>
		<?php

		use om\IcalParser;

		require_once __DIR__ . '/../vendor/autoload.php';

		$cal = new IcalParser();
		$results = $cal->parseFile(
			'https://www.google.com/calendar/ical/cs.czech%23holiday%40group.v.calendar.google.com/public/basic.ics'
		);

		foreach ($cal->getSortedEvents() as $r) {
			echo sprintf('	<li>%s - %s</li>' . PHP_EOL, $r['DTSTART']->format('j.n.Y'), $r['SUMMARY']);
		}

		?></ul>
</div>
</body>
</html>
