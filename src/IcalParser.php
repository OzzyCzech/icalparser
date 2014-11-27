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

	public $windows_timezones = array(
		'Dateline Standard Time' => 'Etc/GMT+12',
		'UTC-11' => 'Etc/GMT+11',
		'Hawaiian Standard Time' => 'Pacific/Honolulu',
		'Alaskan Standard Time' => 'America/Anchorage',
		'Pacific Standard Time (Mexico)' => 'America/Santa_Isabel',
		'Pacific Standard Time' => 'America/Los_Angeles',
		'US Mountain Standard Time' => 'America/Phoenix',
		'Mountain Standard Time (Mexico)' => 'America/Chihuahua',
		'Mountain Standard Time' => 'America/Denver',
		'Central America Standard Time' => 'America/Guatemala',
		'Central Standard Time' => 'America/Chicago',
		'Central Standard Time (Mexico)' => 'America/Mexico_City',
		'Canada Central Standard Time' => 'America/Regina',
		'SA Pacific Standard Time' => 'America/Bogota',
		'Eastern Standard Time' => 'America/New_York',
		'US Eastern Standard Time' => 'America/Indianapolis',
		'Venezuela Standard Time' => 'America/Caracas',
		'Paraguay Standard Time' => 'America/Asuncion',
		'Atlantic Standard Time' => 'America/Halifax',
		'Central Brazilian Standard Time' => 'America/Cuiaba',
		'SA Western Standard Time' => 'America/La_Paz',
		'Pacific SA Standard Time' => 'America/Santiago',
		'Newfoundland Standard Time' => 'America/St_Johns',
		'E. South America Standard Time' => 'America/Sao_Paulo',
		'Argentina Standard Time' => 'America/Buenos_Aires',
		'SA Eastern Standard Time' => 'America/Cayenne',
		'Greenland Standard Time' => 'America/Godthab',
		'Montevideo Standard Time' => 'America/Montevideo',
		'Bahia Standard Time' => 'America/Bahia',
		'UTC-02' => 'Etc/GMT+2',
		'Azores Standard Time' => 'Atlantic/Azores',
		'Cape Verde Standard Time' => 'Atlantic/Cape_Verde',
		'Morocco Standard Time' => 'Africa/Casablanca',
		'UTC' => 'Etc/GMT',
		'GMT Standard Time' => 'Europe/London',
		'Greenwich Standard Time' => 'Atlantic/Reykjavik',
		'W. Europe Standard Time' => 'Europe/Berlin',
		'Central Europe Standard Time' => 'Europe/Budapest',
		'Romance Standard Time' => 'Europe/Paris',
		'Central European Standard Time' => 'Europe/Warsaw',
		'W. Central Africa Standard Time' => 'Africa/Lagos',
		'Namibia Standard Time' => 'Africa/Windhoek',
		'GTB Standard Time' => 'Europe/Bucharest',
		'Middle East Standard Time' => 'Asia/Beirut',
		'Egypt Standard Time' => 'Africa/Cairo',
		'Syria Standard Time' => 'Asia/Damascus',
		'South Africa Standard Time' => 'Africa/Johannesburg',
		'FLE Standard Time' => 'Europe/Kiev',
		'Turkey Standard Time' => 'Europe/Istanbul',
		'Israel Standard Time' => 'Asia/Jerusalem',
		'Libya Standard Time' => 'Africa/Tripoli',
		'Jordan Standard Time' => 'Asia/Amman',
		'Arabic Standard Time' => 'Asia/Baghdad',
		'Kaliningrad Standard Time' => 'Europe/Kaliningrad',
		'Arab Standard Time' => 'Asia/Riyadh',
		'E. Africa Standard Time' => 'Africa/Nairobi',
		'Iran Standard Time' => 'Asia/Tehran',
		'Arabian Standard Time' => 'Asia/Dubai',
		'Azerbaijan Standard Time' => 'Asia/Baku',
		'Russian Standard Time' => 'Europe/Moscow',
		'Mauritius Standard Time' => 'Indian/Mauritius',
		'Georgian Standard Time' => 'Asia/Tbilisi',
		'Caucasus Standard Time' => 'Asia/Yerevan',
		'Afghanistan Standard Time' => 'Asia/Kabul',
		'West Asia Standard Time' => 'Asia/Tashkent',
		'Pakistan Standard Time' => 'Asia/Karachi',
		'India Standard Time' => 'Asia/Calcutta',
		'Sri Lanka Standard Time' => 'Asia/Colombo',
		'Nepal Standard Time' => 'Asia/Katmandu',
		'Central Asia Standard Time' => 'Asia/Almaty',
		'Bangladesh Standard Time' => 'Asia/Dhaka',
		'Ekaterinburg Standard Time' => 'Asia/Yekaterinburg',
		'Myanmar Standard Time' => 'Asia/Rangoon',
		'SE Asia Standard Time' => 'Asia/Bangkok',
		'N. Central Asia Standard Time' => 'Asia/Novosibirsk',
		'China Standard Time' => 'Asia/Shanghai',
		'North Asia Standard Time' => 'Asia/Krasnoyarsk',
		'Singapore Standard Time' => 'Asia/Singapore',
		'W. Australia Standard Time' => 'Australia/Perth',
		'Taipei Standard Time' => 'Asia/Taipei',
		'Ulaanbaatar Standard Time' => 'Asia/Ulaanbaatar',
		'North Asia East Standard Time' => 'Asia/Irkutsk',
		'Tokyo Standard Time' => 'Asia/Tokyo',
		'Korea Standard Time' => 'Asia/Seoul',
		'Cen. Australia Standard Time' => 'Australia/Adelaide',
		'AUS Central Standard Time' => 'Australia/Darwin',
		'E. Australia Standard Time' => 'Australia/Brisbane',
		'AUS Eastern Standard Time' => 'Australia/Sydney',
		'West Pacific Standard Time' => 'Pacific/Port_Moresby',
		'Tasmania Standard Time' => 'Australia/Hobart',
		'Yakutsk Standard Time' => 'Asia/Yakutsk',
		'Central Pacific Standard Time' => 'Pacific/Guadalcanal',
		'Vladivostok Standard Time' => 'Asia/Vladivostok',
		'New Zealand Standard Time' => 'Pacific/Auckland',
		'UTC+12' => 'Etc/GMT-12',
		'Fiji Standard Time' => 'Pacific/Fiji',
		'Magadan Standard Time' => 'Asia/Magadan',
		'Tonga Standard Time' => 'Pacific/Tongatapu',
		'Samoa Standard Time' => 'Pacific/Apia',
	);

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

		foreach (explode("\n", $string) as $row) {

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

			if ($key === 'X-WR-TIMEZONE' || $key === 'TZID') {
				if (preg_match('#(\w+/\w+)$#i', $value, $matches)) {
					$value = $matches[1];
				}
				if (isset($this->windows_timezones[$value])) {
					$value = $this->windows_timezones[$value];
				}
				$this->timezone = new \DateTimeZone($value);
			}

			// have some middle part ?
			if ($middle && preg_match_all('#(?<key>[^=;]+)=(?<value>[^;]+)#', $middle, $matches, PREG_SET_ORDER)) {
				$middle = array();
				foreach ($matches as $match) {
					if ($match['key'] === 'TZID') {
						if (isset($this->windows_timezones[$match['value']])) {
							$match['value'] = $this->windows_timezones[$match['value']];
						}
						try {
							$middle[$match['key']] = $timezone = new \DateTimeZone($match['value']);
						} catch (\Exception $e) {
							$middle[$match['key']] = $match['value'];
						}
					}
				}
			}
		}

		// process simple dates with timezone
		if ($key === 'DTSTAMP' || $key === 'LAST-MODIFIED' || $key === 'CREATED' || $key === 'DTSTART' || $key === 'DTEND') {
			$value = new \DateTime($value, ($timezone ?: $this->timezone));
		}

		if ($key === 'RRULE' && preg_match_all('#(?<key>[^=;]+)=(?<value>[^;]+)#', $value, $matches, PREG_SET_ORDER)) {
			$middle = null;
			$value = array();
			foreach ($matches as $match) {
				$value[$match['key']] = $match['value'];
			}
		}

		//split by comma, escape \,
		if ($key === 'CATEGORIES') {
			$value = preg_split('/(?<![^\\\\]\\\\),/', $value);
		}

		//implement 4.3.11 Text ESCAPED-CHAR
		$text_properties = array(
			'CALSCALE', 'METHOD', 'PRODID', 'VERSION', 'CATEGORIES', 'CLASS', 'COMMENT', 'DESCRIPTION'
		, 'LOCATION', 'RESOURCES', 'STATUS', 'SUMMARY', 'TRANSP', 'TZID', 'TZNAME', 'CONTACT', 'RELATED-TO', 'UID'
		, 'ACTION', 'REQUEST-STATUS'
		);
		if (in_array($key, $text_properties) || strpos($key, 'X-') === 0) {
			if (is_array($value)) {
				foreach ($value as &$var) {
					$var = strtr($var, array('\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\\;' => ';', '\\,' => ','));
				}
			} else {
				$value = strtr($value, array('\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\\;' => ';', '\\,' => ','));
			}
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
