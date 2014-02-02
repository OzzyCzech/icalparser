<?php
namespace om;
/**
 * Copyright (c) 2004 Roman Ožana (http://www.omdesign.cz)
 *
 * @author Roman Ožana <ozana@omdesign.cz>
 */
class IcalParser {

  /** @var \DateTime */
  public $timezone;

  /**
   * @param $file
   * @param null $callback
   * @return array|null
   * @throws \RuntimeException
   * @throws \InvalidArgumentException
   */
  public function parseFile($file, $callback = null) {
    $output = array();

    if (!$handle = fopen($file, 'r')) {
      throw new \RuntimeException('Can\'t open file' . $file . ' for reading');
    }

    if (!preg_match('/BEGIN:VCALENDAR/', fgets($handle, 4096))) {
      throw new \InvalidArgumentException('Invalid ICAL data format');
    }


    $counters = array();
    $section = 'VCALENDAR';

    while (!feof($handle)) {
      $row = trim(fgets($handle, 4096), "\n\r\t\x0B\0"); // read line from file

      switch ($row) {
        case 'BEGIN:DAYLIGHT':
        case 'BEGIN:VALARM':
        case 'BEGIN:VTIMEZONE':
        case 'BEGIN:VFREEBUSY':
        case 'BEGIN:VJOURNAL':
        case 'BEGIN:STANDARD':
        case 'BEGIN:VTODO':
        case 'BEGIN:VEVENT':
          $section = substr($row, 6);
          $counters[$section] = isset($counters[$section]) ? $counters[$section] + 1 : 0;
          continue 2; // while
          break;
        case 'END:DAYLIGHT':
        case 'END:VALARM':
        case 'END:VTIMEZONE':
        case 'END:VFREEBUSY':
        case 'END:VJOURNAL':
        case 'END:STANDARD':
        case 'END:VEVENT':
        case 'END:VTODO':
        case 'END:VCALENDAR':
          continue 2; // while
          break;
      }

      list($key, $value) = $this->parseRow($row);

      if ($callback) {
        call_user_func($callback, $row, $key, $value, $section, $counters[$section]); // call user function for processing line
      } else {
        if ($section === 'VCALENDAR') {
          $output[$key] = $value;
        } else {
          $output[$section][$counters[$section]][$key] = $value;
        }

      }
    }

    var_dump($this->timezone);
    fclose($handle);
    return ($callback) ? null : $output;
  }

  private function parseRow($row) {
    preg_match('/([^:]+)[:]([\w\W]+)/', $row, $matches);

    $key = false;
    $value = $row;

    if ($matches) {
      $key = $matches[1];
      $value = $matches[2];

      if ($key === 'X-WR-TIMEZONE') {
        if ($date = \DateTime::createFromFormat('e', $value)) {
          $this->timezone = $value = $date->getTimezone();
        }
      }

      // process simple dates
      if (($key === 'DTSTAMP') || ($key === 'LAST-MODIFIED') || ($key === 'CREATED')) {
        $value = new \DateTime($value, $this->timezone);
      }

      // process RRULE
      if ($key === 'RRULE') {
        $value = $this->icalRrule($value);
      }

      if ($key === 'CATEGORIES') {
        $value = explode(',', $value);
      }

      //
      // process ical date values like
      //
      // [DTSTART;VALUE=DATE] => 20121224
      // [DTEND;VALUE=DATE] => 20121225
      if (strpos($key, 'DTSTART') !== false || strpos($key, 'DTEND') !== false) {
        list($key, $value) = $this->icalDtDate($key, $value);
      }
    }

    return array($key, $value);
  }


  /**
   * Parse RRULE  return array
   *
   * @param unknown_type $value
   * @return unknown
   */
  private function icalRrule($value) {
    $rrule = explode(';', $value);
    foreach ($rrule as $line) {
      $rcontent = explode('=', $line);
      $result[$rcontent[0]] = $rcontent[1];
    }
    return $result;
  }

  /**
   * Return unix date from iCal date format
   *
   * @param string $key
   * @param string $value
   * @return array
   */
  private function icalDtDate($key, $value) {
    $value = strtotime($value);

    // zjisteni TZID
    $temp = explode(";", $key);

    $data = null;
    if (empty($temp[1])) // neni TZID
    {
      $data = str_replace('T', '', $data);
      return array($key, $value);
    }
    // pridani $value a $tzid do pole
    $key = $temp[0];
    $temp = explode("=", $temp[1]);
    $return_value[$temp[0]] = $temp[1];
    $return_value['unixtime'] = $value;

    return array($key, $return_value);
  }


  /* --------------------------------------------------------------------------
   * List of public getters
   * -------------------------------------------------------------------------- */


  /**
   * Return sorted eventlist as array or false if calenar is empty
   *
   * @return array|boolean
   */
  public function getSortEventList() {
    $temp = $this->getEventList();
    if (!empty($temp)) {
      usort(
        $temp, function ($a, $b) {
          return strnatcasecmp($a['DTSTART']['unixtime'], $b['DTSTART']['unixtime']);
        }
      );
      return $temp;
    } else {
      return false;
    }
  }
}