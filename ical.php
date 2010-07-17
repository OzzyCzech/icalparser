<?php
/*
  Copyright (c) 2010, Roman Ožana

  Permission is hereby granted, free of charge, to any person
  obtaining a copy of this software and associated documentation
  files (the "Software"), to deal in the Software without
  restriction, including without limitation the rights to use,
  copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the
  Software is furnished to do so, subject to the following
  conditions:

  The above copyright notice and this permission notice shall be
  included in all copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
  EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
  OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
  NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
  HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
  WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
  FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
  OTHER DEALINGS IN THE SOFTWARE.
 */
/**
 * This class Parse iCal standard. Is prepare to iCal feature version. Now is testing with apple iCal standard 2.0.
 * 
 * @name iCal parser
 * @copyright Roman Ožana, 2010
 * @author Roman Ožana 2006-2010

 * @link www.nabito.net
 * @link www.omdesign.cz
 * @version 1.1
 *
 * @license MIT
 * 
 * @example
 * 	$ical = new ical();
 * 	$ical->parse('./calendar.ics');
 * 	echo "<pre>";
 * 	$ical->get_all_data();
 *  echo "</pre>";
 *
 * @package omdesign
 */
class ical
{

  /**
   * Text in file
   *
   * @var string
   */
  public var $file_text;
  /**
   * This array save iCalendar parse data
   *
   * @var array
   */
  var $cal;
  /**
   * Number of Events
   *
   * @var integer
   */
  var $event_count;
  /**
   * Number of ToDos
   *
   * @var integer
   */
  var $todo_count;
  /**
   * Help variable save last key (multiline string)
   *
   * @var string
   */
  var $last_key;


  /**
   * Read text file, icalender text file
   *
   * @param string $file
   * @return string
   */
  function read_file($file)
  {
    $this->file = $file;
    $file_text = join("", file($file)); //load file
    # next line withp preg_replace is because Mozilla Calendar save values wrong, like this ->
    #SUMMARY
    # :Text of sumary
    # good way is, for example in SunnyBird. SunnyBird save iCal like this example ->
    #SUMMARY:Text of sumary

    $file_text = preg_replace("/[\r\n]{1,} ([:;])/", "\\1", $file_text);

    return $file_text; // return all text
  }


  /**
   * Return event count in calendar
   *
   * @return integer
   */
  function get_event_count()
  {
    return (int) $this->event_count;
  }


  /**
   * Return todos count in calendar
   *
   * @return integer
   */
  function get_todo_count()
  {
    return (int) $this->todo_count;
  }


  /**
   * Prekladac kalendare
   *
   * @param string|url $uri
   * @return unknown
   */
  function parse($uri)
  {
    $this->cal = array (); // new empty array

    $this->event_count = -1;

    // read FILE text
    $this->file_text = $this->read_file($uri);

    $this->file_text = split("[\n]", $this->file_text);

    // is this text vcalendar standart text ? on line 1 is BEGIN:VCALENDAR
    if (!stristr($this->file_text[0], 'BEGIN:VCALENDAR'))
        return 'error not VCALENDAR';

    foreach ($this->file_text as $text)
    {
      $text = trim($text); // trim one line
      if (!empty($text))
      {
        // get Key and Value VCALENDAR:Begin -> Key = VCALENDAR, Value = begin
        list($key, $value) = $this->retun_key_value($text);

        switch ($text) // search special string
        {
          case "BEGIN:VTODO":
            $this->todo_count = $this->todo_count + 1; // new todo begin
            $type = "VTODO";
            break;

          case "BEGIN:VEVENT":
            $this->event_count = $this->event_count + 1; // new event begin
            $type = "VEVENT";
            break;

          case "BEGIN:VCALENDAR": // all other special string
          case "BEGIN:DAYLIGHT":
          case "BEGIN:VTIMEZONE":
          case "BEGIN:STANDARD":
            $type = $value; // save tu array under value key
            break;

          case "END:VTODO": // end special text - goto VCALENDAR key
          case "END:VEVENT":

          case "END:VCALENDAR":
          case "END:DAYLIGHT":
          case "END:VTIMEZONE":
          case "END:STANDARD":
            $type = "VCALENDAR";
            break;

          default: // no special string
            $this->add_to_array($type, $key, $value); // add to array
            break;
        }
      }
    }
    return $this->cal;
  }


  /**
   * Add to $this->ical array one value and key. Type is VTODO, VEVENT, VCALENDAR ... .
   *
   * @param string $type
   * @param string $key
   * @param string $value
   */
  function add_to_array($type, $key, $value)
  {
    if ($key == false)
    {
      $key = $this->last_key;
      switch ($type)
      {
        case 'VEVENT': $value = $this->cal[$type][$this->event_count][$key] . $value;
          break;
        case 'VTODO': $value = $this->cal[$type][$this->todo_count][$key] . $value;
          break;
      }
    }

    if (($key == "DTSTAMP") or ($key == "LAST-MODIFIED") or ($key == "CREATED"))
        $value = $this->ical_date_to_unix($value);
    if ($key == "RRULE") $value = $this->ical_rrule($value);

    if (stristr($key, "DTSTART") or stristr($key, "DTEND"))
        list($key, $value) = $this->ical_dt_date($key, $value);

    switch ($type)
    {
      case "VTODO":
        $this->cal[$type][$this->todo_count][$key] = $value;
        break;

      case "VEVENT":
        $this->cal[$type][$this->event_count][$key] = $value;
        break;

      default:
        $this->cal[$type][$key] = $value;
        break;
    }
    $this->last_key = $key;
  }


  /**
   * Parse text "XXXX:value text some with : " and return array($key = "XXXX", $value="value"); 
   *
   * @param unknown_type $text
   * @return unknown
   */
  function retun_key_value($text)
  {
    preg_match("/([^:]+)[:]([\w\W]+)/", $text, $matches);

    if (empty($matches))
    {
      return array (false, $text);
    }
    else
    {
      $matches = array_splice($matches, 1, 2);
      return $matches;
    }
  }


  /**
   * Parse RRULE  return array
   *
   * @param unknown_type $value
   * @return unknown
   */
  function ical_rrule($value)
  {
    $rrule = explode(';', $value);
    foreach ($rrule as $line)
    {
      $rcontent = explode('=', $line);
      $result[$rcontent[0]] = $rcontent[1];
    }
    return $result;
  }


  /**
   * Return Unix time from ical date time fomrat (YYYYMMDD[T]HHMMSS[Z] or YYYYMMDD[T]HHMMSS)
   *
   * @param unknown_type $ical_date
   * @return unknown
   */
  function ical_date_to_unix($ical_date)
  {
    $ical_date = str_replace('T', '', $ical_date);
    $ical_date = str_replace('Z', '', $ical_date);

    // TIME LIMITED EVENT
    ereg('([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{0,2})([0-9]{0,2})([0-9]{0,2})', $ical_date, $date);

    // UNIX timestamps can't deal with pre 1970 dates
    if ($date[1] <= 1970)
    {
      $date[1] = 1971;
    }
    return mktime($date[4], $date[5], $date[6], $date[2], $date[3], $date[1]);
  }


  /**
   * Return unix date from iCal date format
   *
   * @param string $key
   * @param string $value
   * @return array
   */
  function ical_dt_date($key, $value)
  {
    $value = $this->ical_date_to_unix($value);

    // zjisteni TZID
    $temp = explode(";", $key);

    if (empty($temp[1])) // neni TZID
    {
      $data = str_replace('T', '', $data);
      return array ($key, $value);
    }
    // pridani $value a $tzid do pole
    $key = $temp[0];
    $temp = explode("=", $temp[1]);
    $return_value[$temp[0]] = $temp[1];
    $return_value['unixtime'] = $value;

    return array ($key, $return_value);
  }


  /**
   * Return sorted eventlist as array or false if calenar is empty
   *
   * @return unknown
   */
  function get_sort_event_list()
  {
    $temp = $this->get_event_list();
    if (!empty($temp))
    {
      usort($temp, array (&$this, "ical_dtstart_compare"));
      return $temp;
    }
    else
    {
      return false;
    }
  }


  /**
   * Compare two unix timestamp
   *
   * @param array $a
   * @param array $b
   * @return integer
   */
  function ical_dtstart_compare($a, $b)
  {
    return strnatcasecmp($a['DTSTART']['unixtime'], $b['DTSTART']['unixtime']);
  }


  /**
   * Return eventlist array (not sort eventlist array)
   *
   * @return array
   */
  function get_event_list()
  {
    return $this->cal['VEVENT'];
  }


  /**
   * Return todo arry (not sort todo array)
   *
   * @return array
   */
  function get_todo_list()
  {
    return $this->cal['VTODO'];
  }


  /**
   * Return base calendar data
   *
   * @return array
   */
  function get_calender_data()
  {
    return $this->cal['VCALENDAR'];
  }


  /**
   * Return array with all data
   *
   * @return array
   */
  function get_all_data()
  {
    return $this->cal;
  }


}