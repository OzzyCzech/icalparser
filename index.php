<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8" />
    <title></title>
    <meta name="author" content="Roman Ožana, 2010 (www.omdesign.cz)" />
    <meta name="webmaster" content="Roman Ožana, ozana@omdesign.cz (www.omdesign.cz)" />
  </head>
  <body>
    <?php
    require_once 'ical.php';
    $ical = new \Helpers\Calendar\ical('http://www.google.com/calendar/ical/cs.czech%23holiday%40group.v.calendar.google.com/public/basic.ics');
    $ical->parse();

    echo '<h1>Unsort events list</h1>';

    foreach ($ical->get_event_list() as $event)
    {
      echo date('j.n.Y', $event['DTSTART']['unixtime']) . ' - ' . $event['SUMMARY'] . '<br />';
    }

    echo '<h1>Sort events list</h1>';

    foreach ($ical->get_sort_event_list() as $event)
    {
      echo date('j.n.Y', $event['DTSTART']['unixtime']) . ' - ' . $event['SUMMARY'] . '<br />';
    }
    ?>
  </body>
</html>