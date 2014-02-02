<!DOCTYPE html>
<html lang="cs-CZ">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>Ical Parser example</title>

  <link href="//netdna.bootstrapcdn.com/bootstrap/3.0.3/css/bootstrap.min.css" rel="stylesheet">

</head>
<body>
<div class="container">
  <?php
  require_once '../vendor/autoload.php';
  $ical = new \om\IcalParser('http://www.google.com/calendar/ical/cs.czech%23holiday%40group.v.calendar.google.com/public/basic.ics');
  ?>

  <h1>Unsort events list</h1>
  <?

  foreach ($ical->getEventList() as $event) {
    echo date('j.n.Y', $event['DTSTART']['unixtime']) . ' - ' . $event['SUMMARY'] . '<br />';
  }
  ?>

  <h1>Sort events list</h1>

  <?php
  foreach ($ical->getSortEventList() as $event) {
    echo date('j.n.Y', $event['DTSTART']['unixtime']) . ' - ' . $event['SUMMARY'] . '<br />';
  }
  ?>
</div>
</body>
</html>