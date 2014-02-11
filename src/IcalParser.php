<?php
namespace om;
/**
 * Copyright (c) 2004 Roman Ožana (http://www.omdesign.cz)
 *
 * @author Roman Ožana <ozana@omdesign.cz>
 */
class IcalParser {

	/** @var \DateTimeZone */
	public $timezone;

	/** @var array */
	public $data;

	/**
	 * @param $file
	 * @param null $callback
	 * @return array|null
	 * @throws \RuntimeException
	 * @throws \InvalidArgumentException
	 */
	public function parseFile($file, $callback = null) {
		if (!$handle = fopen($file, 'r')) {
			throw new \RuntimeException('Can\'t open file' . $file . ' for reading');
		}
		fclose($handle);

		return $this->parseString(file_get_contents($file), $callback);
	}

	/**
	 * @param $file
	 * @param null $callback
	 * @return array|null
	 * @throws \RuntimeException
	 * @throws \InvalidArgumentException
	 */
	public function parseString($string, $callback = null) {
		$this->data = array();

		if (!preg_match('/BEGIN:VCALENDAR/', $string)) {
			throw new \InvalidArgumentException('Invalid ICAL data format');
		}

		$counters = array();
		$section = 'VCALENDAR';

		// Replace \r\n with \n
		$string = str_replace("\r\n", "\n", $string);

		// Unfold multi-line strings
		$string = str_replace("\n ", "", $string);

		foreach(explode("\n", $string) as $row) {

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

			list($key, $middle, $value) = $this->parseRow($row);


			if ($callback) {
				// call user function for processing line
				call_user_func($callback, $row, $key, $middle, $value, $section, $counters[$section]);
			} else {
				if ($section === 'VCALENDAR') {
					$this->data[$key] = $value;
				} else {
					$this->data[$section][$counters[$section]][$key] = $value;
				}

			}
		}

		return ($callback) ? null : $this->data;
	}

	private function parseRow($row) {
		preg_match('#^([\w-]+);?(.*?):(.*)$#i', $row, $matches);

		$key = false;
		$middle = null;
		$value = null;

		if ($matches) {
			$key = $matches[1];
			$middle = $matches[2];
			$value = $matches[3];
			$timezone = null;

			if ($key === 'X-WR-TIMEZONE') {
				$this->timezone = new \DateTimeZone($value);
			}

			// have some middle part ?
			if ($middle && preg_match_all('#(?<key>[^=;]+)=(?<value>[^;]+)#', $middle, $matches, PREG_SET_ORDER)) {
				$middle = array();

				foreach ($matches as $match) {
					if ($match['key'] === 'TZID' && $timezone = new \DateTimeZone($match['value'])) {
						$middle[$match['key']] = $timezone;
					} else {
						$middle[$match['key']] = $match['value'];
					}
				}
			}
		}

		// process simple dates with timezone
		if ($key === 'DTSTAMP' || $key === 'LAST-MODIFIED' || $key === 'CREATED' || $key === 'DTSTART' || $key === 'DTEND') {
			$value = new \DateTime($value, ($timezone ? : $this->timezone));
		}

		if ($key === 'RRULE' && preg_match_all('#(?<key>[^=;]+)=(?<value>[^;]+)#', $value, $matches, PREG_SET_ORDER)) {
			$middle = null;
			$value = array();
			foreach ($matches as $match) {
				$value[$match['key']] = $match['value'];
			}
		}

		if ($key === 'CATEGORIES') {
			$value = explode(',', $value);
		}

		if ($key === 'DESCRIPTION') {
			// Replace literal \n with newlines
			$value = str_replace('\n', "\n", $value);
		}

		return array($key, $middle, $value);
	}

	public function getEvents() {
		return isset($this->data['VEVENT']) ? $this->data['VEVENT'] : array();
	}

	public function getAlarms() {
		return isset($this->data['VALARM']) ? $this->data['VALARM'] : array();
	}

	public function getTimezones() {
		return isset($this->data['VTIMEZONE']) ? $this->data['VTIMEZONE'] : array();
	}

	/**
	 * Return sorted eventlist as array or false if calenar is empty
	 *
	 * @return array|boolean
	 */
	public function getSortedEvents() {
		if ($events = $this->getEvents()) {
			usort(
				$events, function ($a, $b) {
					return $a['DTSTART'] > $b['DTSTART'];
				}
			);
			return $events;
		}
		return array();
	}

	public function getReverseSortedEvents() {
		if ($events = $this->getEvents()) {
			usort(
				$events, function ($a, $b) {
					return $a['DTSTART'] < $b['DTSTART'];
				}
			);
			return $events;
		}
		return array();
	}
}