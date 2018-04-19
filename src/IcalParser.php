<?php

namespace om;
/**
 * Copyright (c) 2004-2015 Roman Ožana (http://www.omdesign.cz)
 *
 * @author Roman Ožana <ozana@omdesign.cz>
 */
class IcalParser {

	/** @var \DateTimeZone */
	public $timezone;

	/** @var array */
	public $data;

	/** @var array */
	public $windows_timezones = [
		'Dateline Standard Time' => 'Etc/GMT+12',
		'(UTC-12:00) International Date Line West' => 'Etc/GMT+12',
		'UTC-11' => 'Etc/GMT+11',
		'(UTC-11:00) Coordinated Universal Time -11' => 'Etc/GMT+11',
		'Hawaiian Standard Time' => 'Pacific/Honolulu',
		'(UTC-10:00) Hawaii' => 'Pacific/Honolulu',
		'Alaskan Standard Time' => 'America/Anchorage',
		'(UTC-09:00) Alaska' => 'America/Anchorage',
		'Pacific Standard Time (Mexico)' => 'America/Santa_Isabel',
		'(UTC-08:00) Baja California' => 'America/Santa_Isabel',
		'Pacific Standard Time' => 'America/Los_Angeles',
		'Pacific Time' => 'America/Los_Angeles',
		'(UTC-08:00) Pacific Time (US and Canada)' => 'America/Los_Angeles',
		'(UTC-08:00) Pacific Time (US & Canada)' => 'America/Los_Angeles',
		'US Mountain Standard Time' => 'America/Phoenix',
		'(UTC-07:00) Arizona' => 'America/Phoenix',
		'Mountain Standard Time (Mexico)' => 'America/Chihuahua',
		'(UTC-07:00) Chihuahua, La Paz, Mazatlan' => 'America/Chihuahua',
		'Mountain Standard Time' => 'America/Denver',
		'Mountain Time' => 'America/Denver',
		'(UTC-07:00) Mountain Time (US and Canada)' => 'America/Denver',
		'(UTC-07:00) Mountain Time (US & Canada)' => 'America/Denver',
		'Central America Standard Time' => 'America/Guatemala',
		'(UTC-06:00) Central America' => 'America/Guatemala',
		'Central Standard Time' => 'America/Chicago',
		'Central Time' => 'America/Chicago',
		'(UTC-06:00) Central Time (US and Canada)' => 'America/Chicago',
		'(UTC-06:00) Central Time (US & Canada)' => 'America/Chicago',
		'Central Standard Time (Mexico)' => 'America/Mexico_City',
		'(UTC-06:00) Guadalajara, Mexico City, Monterrey' => 'America/Mexico_City',
		'Canada Central Standard Time' => 'America/Regina',
		'(UTC-06:00) Saskatchewan' => 'America/Regina',
		'SA Pacific Standard Time' => 'America/Bogota',
		'(UTC-05:00) Bogota, Lima, Quito' => 'America/Bogota',
		'Eastern Standard Time' => 'America/New_York',
		'Eastern Time' => 'America/New_York',
		'(UTC-05:00) Eastern Time (US and Canada)' => 'America/New_York',
		'(UTC-05:00) Eastern Time (US & Canada)' => 'America/New_York',
		'US Eastern Standard Time' => 'America/Indianapolis',
		'(UTC-05:00) Indiana (East)' => 'America/Indianapolis',
		'Venezuela Standard Time' => 'America/Caracas',
		'(UTC-04:30) Caracas' => 'America/Caracas',
		'Paraguay Standard Time' => 'America/Asuncion',
		'(UTC-04:00) Asuncion' => 'America/Asuncion',
		'Atlantic Standard Time' => 'America/Halifax',
		'(UTC-04:00) Atlantic Time (Canada)' => 'America/Halifax',
		'Central Brazilian Standard Time' => 'America/Cuiaba',
		'(UTC-04:00) Cuiaba' => 'America/Cuiaba',
		'SA Western Standard Time' => 'America/La_Paz',
		'(UTC-04:00) Georgetown, La Paz, Manaus, San Juan' => 'America/La_Paz',
		'Pacific SA Standard Time' => 'America/Santiago',
		'(UTC-04:00) Santiago' => 'America/Santiago',
		'Newfoundland Standard Time' => 'America/St_Johns',
		'(UTC-03:30) Newfoundland' => 'America/St_Johns',
		'E. South America Standard Time' => 'America/Sao_Paulo',
		'(UTC-03:00) Brasilia' => 'America/Sao_Paulo',
		'Argentina Standard Time' => 'America/Buenos_Aires',
		'(UTC-03:00) Buenos Aires' => 'America/Buenos_Aires',
		'SA Eastern Standard Time' => 'America/Cayenne',
		'(UTC-03:00) Cayenne, Fortaleza' => 'America/Cayenne',
		'Greenland Standard Time' => 'America/Godthab',
		'(UTC-03:00) Greenland' => 'America/Godthab',
		'Montevideo Standard Time' => 'America/Montevideo',
		'(UTC-03:00) Montevideo' => 'America/Montevideo',
		'Bahia Standard Time' => 'America/Bahia',
		'UTC-02' => 'Etc/GMT+2',
		'(UTC-02:00) Coordinated Universal Time -02' => 'Etc/GMT+2',
		'Azores Standard Time' => 'Atlantic/Azores',
		'(UTC-01:00) Azores' => 'Atlantic/Azores',
		'Cape Verde Standard Time' => 'Atlantic/Cape_Verde',
		'(UTC-01:00) Cabo Verde Is.' => 'Atlantic/Cape_Verde',
		'Morocco Standard Time' => 'Africa/Casablanca',
		'(UTC) Casablanca' => 'Africa/Casablanca',
		'UTC' => 'Etc/GMT',
		'Microsoft/Utc' => 'Etc/GMT',
		'GMT Standard Time' => 'Europe/London',
		'(UTC) Dublin, Edinburgh, Lisbon, London' => 'Europe/London',
		'Greenwich Standard Time' => 'Atlantic/Reykjavik',
		'(UTC) Monrovia, Reykjavik' => 'Atlantic/Reykjavik',
		'W. Europe Standard Time' => 'Europe/Berlin',
		'(UTC+01:00) Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna' => 'Europe/Berlin',
		'Central Europe Standard Time' => 'Europe/Budapest',
		'(UTC+01:00) Belgrade, Bratislava, Budapest, Ljubljana, Prague' => 'Europe/Budapest',
		'Romance Standard Time' => 'Europe/Paris',
		'(UTC+01:00) Brussels, Copenhagen, Madrid, Paris' => 'Europe/Paris',
		'Central European Standard Time' => 'Europe/Warsaw',
		'(UTC+01:00) Sarajevo, Skopje, Warsaw, Zagreb' => 'Europe/Warsaw',
		'W. Central Africa Standard Time' => 'Africa/Lagos',
		'(UTC+01:00) West Central Africa' => 'Africa/Lagos',
		'Namibia Standard Time' => 'Africa/Windhoek',
		'(UTC+01:00) Windhoek' => 'Africa/Windhoek',
		'GTB Standard Time' => 'Europe/Bucharest',
		'(UTC+02:00) Athens, Bucharest' => 'Europe/Bucharest',
		'Middle East Standard Time' => 'Asia/Beirut',
		'(UTC+02:00) Beirut' => 'Asia/Beirut',
		'Egypt Standard Time' => 'Africa/Cairo',
		'(UTC+02:00) Cairo' => 'Africa/Cairo',
		'Syria Standard Time' => 'Asia/Damascus',
		'(UTC+02:00) Damascus' => 'Asia/Damascus',
		'South Africa Standard Time' => 'Africa/Johannesburg',
		'(UTC+02:00) Harare, Pretoria' => 'Africa/Johannesburg',
		'FLE Standard Time' => 'Europe/Kiev',
		'(UTC+02:00) Helsinki, Kyiv, Riga, Sofia, Tallinn, Vilnius' => 'Europe/Kiev',
		'Turkey Standard Time' => 'Europe/Istanbul',
		'(UTC+02:00) Istanbul' => 'Europe/Istanbul',
		'Israel Standard Time' => 'Asia/Jerusalem',
		'(UTC+02:00) Jerusalem' => 'Asia/Jerusalem',
		'Libya Standard Time' => 'Africa/Tripoli',
		'Jordan Standard Time' => 'Asia/Amman',
		'(UTC+02:00) Amman' => 'Asia/Amman',
		'Arabic Standard Time' => 'Asia/Baghdad',
		'(UTC+03:00) Baghdad' => 'Asia/Baghdad',
		'Kaliningrad Standard Time' => 'Europe/Kaliningrad',
		'(UTC+03:00) Kaliningrad' => 'Europe/Kaliningrad',
		'Arab Standard Time' => 'Asia/Riyadh',
		'(UTC+03:00) Kuwait, Riyadh' => 'Asia/Riyadh',
		'E. Africa Standard Time' => 'Africa/Nairobi',
		'(UTC+03:00) Nairobi' => 'Africa/Nairobi',
		'Iran Standard Time' => 'Asia/Tehran',
		'(UTC+03:30) Tehran' => 'Asia/Tehran',
		'Arabian Standard Time' => 'Asia/Dubai',
		'(UTC+04:00) Abu Dhabi, Muscat' => 'Asia/Dubai',
		'Azerbaijan Standard Time' => 'Asia/Baku',
		'(UTC+04:00) Baku' => 'Asia/Baku',
		'Russian Standard Time' => 'Europe/Moscow',
		'(UTC+04:00) Moscow, St. Petersburg, Volgograd' => 'Europe/Moscow',
		'Mauritius Standard Time' => 'Indian/Mauritius',
		'(UTC+04:00) Port Louis' => 'Indian/Mauritius',
		'Georgian Standard Time' => 'Asia/Tbilisi',
		'(UTC+04:00) Tbilisi' => 'Asia/Tbilisi',
		'Caucasus Standard Time' => 'Asia/Yerevan',
		'(UTC+04:00) Yerevan' => 'Asia/Yerevan',
		'Afghanistan Standard Time' => 'Asia/Kabul',
		'(UTC+04:30) Kabul' => 'Asia/Kabul',
		'West Asia Standard Time' => 'Asia/Tashkent',
		'(UTC+05:00) Tashkent' => 'Asia/Tashkent',
		'Pakistan Standard Time' => 'Asia/Karachi',
		'(UTC+05:00) Islamabad, Karachi' => 'Asia/Karachi',
		'India Standard Time' => 'Asia/Calcutta',
		'(UTC+05:30) Chennai, Kolkata, Mumbai, New Delhi' => 'Asia/Calcutta',
		'Sri Lanka Standard Time' => 'Asia/Colombo',
		'(UTC+05:30) Sri Jayawardenepura' => 'Asia/Colombo',
		'Nepal Standard Time' => 'Asia/Katmandu',
		'(UTC+05:45) Kathmandu' => 'Asia/Katmandu',
		'Central Asia Standard Time' => 'Asia/Almaty',
		'(UTC+06:00) Astana' => 'Asia/Almaty',
		'Bangladesh Standard Time' => 'Asia/Dhaka',
		'(UTC+06:00) Dhaka' => 'Asia/Dhaka',
		'Ekaterinburg Standard Time' => 'Asia/Yekaterinburg',
		'(UTC+06:00) Ekaterinburg' => 'Asia/Yekaterinburg',
		'Myanmar Standard Time' => 'Asia/Rangoon',
		'(UTC+06:30) Yangon (Rangoon)' => 'Asia/Rangoon',
		'SE Asia Standard Time' => 'Asia/Bangkok',
		'(UTC+07:00) Bangkok, Hanoi, Jakarta' => 'Asia/Bangkok',
		'N. Central Asia Standard Time' => 'Asia/Novosibirsk',
		'(UTC+07:00) Novosibirsk' => 'Asia/Novosibirsk',
		'China Standard Time' => 'Asia/Shanghai',
		'(UTC+08:00) Beijing, Chongqing, Hong Kong, Urumqi' => 'Asia/Shanghai',
		'North Asia Standard Time' => 'Asia/Krasnoyarsk',
		'(UTC+08:00) Krasnoyarsk' => 'Asia/Krasnoyarsk',
		'Singapore Standard Time' => 'Asia/Singapore',
		'(UTC+08:00) Kuala Lumpur, Singapore' => 'Asia/Singapore',
		'W. Australia Standard Time' => 'Australia/Perth',
		'(UTC+08:00) Perth' => 'Australia/Perth',
		'Taipei Standard Time' => 'Asia/Taipei',
		'(UTC+08:00) Taipei' => 'Asia/Taipei',
		'Ulaanbaatar Standard Time' => 'Asia/Ulaanbaatar',
		'(UTC+08:00) Ulaanbaatar' => 'Asia/Ulaanbaatar',
		'North Asia East Standard Time' => 'Asia/Irkutsk',
		'(UTC+09:00) Irkutsk' => 'Asia/Irkutsk',
		'Tokyo Standard Time' => 'Asia/Tokyo',
		'(UTC+09:00) Osaka, Sapporo, Tokyo' => 'Asia/Tokyo',
		'Korea Standard Time' => 'Asia/Seoul',
		'(UTC+09:00) Seoul' => 'Asia/Seoul',
		'Cen. Australia Standard Time' => 'Australia/Adelaide',
		'(UTC+09:30) Adelaide' => 'Australia/Adelaide',
		'AUS Central Standard Time' => 'Australia/Darwin',
		'(UTC+09:30) Darwin' => 'Australia/Darwin',
		'E. Australia Standard Time' => 'Australia/Brisbane',
		'(UTC+10:00) Brisbane' => 'Australia/Brisbane',
		'AUS Eastern Standard Time' => 'Australia/Sydney',
		'(UTC+10:00) Canberra, Melbourne, Sydney' => 'Australia/Sydney',
		'West Pacific Standard Time' => 'Pacific/Port_Moresby',
		'(UTC+10:00) Guam, Port Moresby' => 'Pacific/Port_Moresby',
		'Tasmania Standard Time' => 'Australia/Hobart',
		'(UTC+10:00) Hobart' => 'Australia/Hobart',
		'Yakutsk Standard Time' => 'Asia/Yakutsk',
		'(UTC+10:00) Yakutsk' => 'Asia/Yakutsk',
		'Central Pacific Standard Time' => 'Pacific/Guadalcanal',
		'(UTC+11:00) Solomon Is., New Caledonia' => 'Pacific/Guadalcanal',
		'Vladivostok Standard Time' => 'Asia/Vladivostok',
		'(UTC+11:00) Vladivostok' => 'Asia/Vladivostok',
		'New Zealand Standard Time' => 'Pacific/Auckland',
		'(UTC+12:00) Auckland, Wellington' => 'Pacific/Auckland',
		'UTC+12' => 'Etc/GMT-12',
		'(UTC+12:00) Coordinated Universal Time +12' => 'Etc/GMT-12',
		'Fiji Standard Time' => 'Pacific/Fiji',
		'(UTC+12:00) Fiji' => 'Pacific/Fiji',
		'Magadan Standard Time' => 'Asia/Magadan',
		'(UTC+12:00) Magadan' => 'Asia/Magadan',
		'Tonga Standard Time' => 'Pacific/Tongatapu',
		'(UTC+13:00) Nuku\'alofa' => 'Pacific/Tongatapu',
		'Samoa Standard Time' => 'Pacific/Apia',
		'(UTC-11:00)Samoa' => 'Pacific/Apia',
	];

	protected $arrayKeyMappings = [
		'ATTACH' => 'ATTACHMENTS',
		'EXDATE' => 'EXDATES',
		'RDATE' => 'RDATES',
	];

	/**
	 * @param string $file
	 * @param null $callback
	 * @return array|null
	 * @throws \RuntimeException
	 * @throws \InvalidArgumentException
	 * @throws \Exception
	 */
	public function parseFile($file, $callback = null) {
		if (!$handle = fopen($file, 'r')) {
			throw new \RuntimeException('Can\'t open file' . $file . ' for reading');
		}
		fclose($handle);

		return $this->parseString(file_get_contents($file), $callback);
	}

	/**
	 * @param string $string
	 * @param null $callback
	 * @param boolean $add if true the parsed string is added to existing data
	 * @return array|null
	 * @throws \InvalidArgumentException
	 * @throws \Exception
	 */
	public function parseString($string, $callback = null, $add = false) {
		if ($add === false) {
			// delete old data
			$this->data = [];
		}

		if (!preg_match('/BEGIN:VCALENDAR/', $string)) {
			throw new \InvalidArgumentException('Invalid ICAL data format');
		}

		$counters = [];
		$section = 'VCALENDAR';

		// Replace \r\n with \n
		$string = str_replace("\r\n", "\n", $string);

		// Unfold multi-line strings
		$string = str_replace("\n ", '', $string);

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
				case 'END:VEVENT':
					$section = substr($row, 4);
					$currCounter = $counters[$section];
					$event = $this->data[$section][$currCounter];
					if(!empty($event['RECURRENCE-ID'])) {
						$this->data['_RECURRENCE_IDS'][$event['RECURRENCE-ID']] = $event;
					}

					continue 2; // while
					break;
				case 'END:DAYLIGHT':
				case 'END:VALARM':
				case 'END:VTIMEZONE':
				case 'END:VFREEBUSY':
				case 'END:VJOURNAL':
				case 'END:STANDARD':
				case 'END:VTODO':
					continue 2; // while
					break;

				case 'END:VCALENDAR':
					$veventSection = 'VEVENT';
					if(!empty($this->data[$veventSection])) {
						foreach($this->data[ $veventSection ] as $currCounter => $event) {
							if(!empty($event[ 'RRULE' ]) || !empty($event[ 'RDATE' ])) {
								$recurrences = $this->parseRecurrences($event);
								if(!empty($recurrences)) {
									$this->data[ $veventSection ][ $currCounter ][ 'RECURRENCES' ] = $recurrences;
								}

								if(!empty($event[ 'UID' ])) {
									$this->data[ "_RECURRENCE_COUNTERS_BY_UID" ][ $event[ 'UID' ] ] = $currCounter;
								}
							}
						}
					}
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
					if (isset($this->arrayKeyMappings[$key])) {
						// use an array since there can be multiple entries for this key.  This does not
						// break the current implementation--it leaves the original key alone and adds
						// a new one specifically for the array of values.
						$arrayKey = $this->arrayKeyMappings[$key];
						$this->data[$section][$counters[$section]][$arrayKey][] = $value;
					}

					$this->data[$section][$counters[$section]][$key] = $value;
				}

			}
		}

		return ($callback) ? null : $this->data;
	}

	/**
	 * @param $row
	 * @return array
	 */
	private function parseRow($row) {
		preg_match('#^([\w-]+);?([\w-]+="[^"]*"|.*?):(.*)$#i', $row, $matches);

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
				$middle = [];
				foreach ($matches as $match) {
					if ($match['key'] === 'TZID') {
						$match['value'] = trim($match['value'], "'\"");
						if (isset($this->windows_timezones[$match['value']])) {
							$match['value'] = $this->windows_timezones[$match['value']];
						}
						try {
							$middle[$match['key']] = $timezone = new \DateTimeZone($match['value']);
						} catch (\Exception $e) {
							$middle[$match['key']] = $match['value'];
						}
					} else if ($match['key'] === 'ENCODING') {
						if ($match['value'] === 'QUOTED-PRINTABLE') {
							$value = quoted_printable_decode($value);
						}
					}
				}
			}
		}

		// process simple dates with timezone
		if (in_array($key, ['DTSTAMP', 'LAST-MODIFIED', 'CREATED', 'DTSTART', 'DTEND'], true)) {
			try {
				$value = new \DateTime($value, ($timezone ?: $this->timezone));
			} catch (\Exception $e) {
				$value = null;
			}
		} else if (in_array($key, ['EXDATE', 'RDATE'])) {
			$values = [];
			foreach (explode(',', $value) as $singleValue) {
				try {
					$values[] = new \DateTime($singleValue, ($timezone ?: $this->timezone));
				} catch (\Exception $e) {
					// pass
				}
			}
			if (count($values) === 1) {
				$value = $values[0];
			} else {
				$value = $values;
			}
		}

		if ($key === 'RRULE' && preg_match_all('#(?<key>[^=;]+)=(?<value>[^;]+)#', $value, $matches, PREG_SET_ORDER)) {
			$middle = null;
			$value = [];
			foreach ($matches as $match) {
				if (in_array($match['key'], ['UNTIL'])) {
					try {
						$value[$match['key']] = new \DateTime($match['value'], ($timezone ?: $this->timezone));
					} catch (\Exception $e) {
						$value[$match['key']] = $match['value'];
					}
				} else {
					$value[$match['key']] = $match['value'];
				}
			}
		}

		//split by comma, escape \,
		if ($key === 'CATEGORIES') {
			$value = preg_split('/(?<![^\\\\]\\\\),/', $value);
		}

		//implement 4.3.11 Text ESCAPED-CHAR
		$text_properties = [
			'CALSCALE', 'METHOD', 'PRODID', 'VERSION', 'CATEGORIES', 'CLASS', 'COMMENT', 'DESCRIPTION'
			, 'LOCATION', 'RESOURCES', 'STATUS', 'SUMMARY', 'TRANSP', 'TZID', 'TZNAME', 'CONTACT', 'RELATED-TO', 'UID'
			, 'ACTION', 'REQUEST-STATUS'
		];
		if (in_array($key, $text_properties) || strpos($key, 'X-') === 0) {
			if (is_array($value)) {
				foreach ($value as &$var) {
					$var = strtr($var, ['\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\\;' => ';', '\\,' => ',']);
				}
			} else {
				$value = strtr($value, ['\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\\;' => ';', '\\,' => ',']);
			}
		}

		return [$key, $middle, $value];
	}

	/**
	 * @param $event
	 * @return array
	 * @throws \Exception
	 */
	public function parseRecurrences($event) {
		$recurring = new Recurrence($event['RRULE']);
		$exclusions = [];
		$additions = [];

		if (!empty($event['EXDATES'])) {
			foreach ($event['EXDATES'] as $exDate) {
				if (is_array($exDate)) {
					foreach ($exDate as $singleExDate) {
						$exclusions[] = $singleExDate->getTimestamp();
					}
				} else {
					$exclusions[] = $exDate->getTimestamp();
				}
			}
		}

		if (!empty($event['RDATES'])) {
			foreach ($event['RDATES'] as $rDate) {
				if (is_array($rDate)) {
					foreach ($rDate as $singleRDate) {
						$additions[] = $singleRDate->getTimestamp();
					}
				} else {
					$additions[] = $rDate->getTimestamp();
				}
			}
		}

		$until = $recurring->getUntil();
		if ($until === false) {
			//forever... limit to 3 years
			$end = clone($event['DTSTART']);
			$end->add(new \DateInterval('P3Y')); // + 3 years
			$recurring->setUntil($end);
			$until = $recurring->getUntil();
		}

		date_default_timezone_set($event['DTSTART']->getTimezone()->getName());
		$frequency = new Freq($recurring->rrule, $event['DTSTART']->getTimestamp(), $exclusions, $additions);
		$recurrenceTimestamps = $frequency->getAllOccurrences();
		$recurrences = [];
		foreach ($recurrenceTimestamps as $recurrenceTimestamp) {
			$tmp = new \DateTime('now', $event['DTSTART']->getTimezone());
			$tmp->setTimestamp($recurrenceTimestamp);

			$recurrenceIDDate = $tmp->format('Ymd');
			$recurrenceIDDateTime = $tmp->format('Ymd\THis');
			if(empty($this->data['_RECURRENCE_IDS'][$recurrenceIDDate]) &&
				empty($this->data['_RECURRENCE_IDS'][$recurrenceIDDateTime])) {
				$gmtCheck = new \DateTime("now", new \DateTimeZone('UTC'));
				$gmtCheck->setTimestamp($recurrenceTimestamp);
				$recurrenceIDDateTimeZ = $gmtCheck->format('Ymd\THis\Z');
				if(empty($this->data['_RECURRENCE_IDS'][$recurrenceIDDateTimeZ])) {
					$recurrences[] = $tmp;
				}
			}
		}

		return $recurrences;
	}

	/**
	 * @return array
	 */
	public function getEvents() {
		$events = [];
		if (isset($this->data['VEVENT'])) {
			for ($i = 0; $i < count($this->data['VEVENT']); $i++) {
				$event = $this->data['VEVENT'][$i];

				if (empty($event['RECURRENCES'])) {
					if(!empty($event['RECURRENCE-ID']) && !empty($event['UID']) && isset($event['SEQUENCE'])) {
						$modifiedEventUID = $event['UID'];
						$modifiedEventRecurID = $event['RECURRENCE-ID'];
						$modifiedEventSeq = intval($event['SEQUENCE'], 10);

						if(isset($this->data["_RECURRENCE_COUNTERS_BY_UID"][$modifiedEventUID])) {
							$counter = $this->data[ "_RECURRENCE_COUNTERS_BY_UID" ][ $modifiedEventUID ];

							$originalEvent = $this->data[ "VEVENT" ][ $counter ];
							if(isset($originalEvent[ 'SEQUENCE' ])) {
								$originalEventSeq = intval($originalEvent['SEQUENCE'], 10);
								$originalEventFormattedStartDate = $originalEvent['DTSTART']->format('Ymd\THis');
								if ($modifiedEventRecurID === $originalEventFormattedStartDate && $modifiedEventSeq > $originalEventSeq) {
									// this modifies the original event
									$modifiedEvent = array_replace_recursive($originalEvent, $event);
									$this->data[ "VEVENT" ][ $counter ] = $modifiedEvent;
									foreach($events as $z => $event) {
										if ($events[$z]['UID'] === $originalEvent['UID'] &&
											$events[$z]['SEQUENCE'] === $originalEvent['SEQUENCE']) {
											// replace the original event with the modified event
											$events[$z] = $modifiedEvent;
											break;
										}
									}
									$event = null; // don't add this to the $events[] array again
								} else if (!empty($originalEvent[ 'RECURRENCES' ])) {
									for($j = 0; $j < count($originalEvent[ 'RECURRENCES' ]); $j++) {
										$recurDate = $originalEvent[ 'RECURRENCES' ][ $j ];
										$formattedStartDate = $recurDate->format('Ymd\THis');
										if($formattedStartDate === $modifiedEventRecurID) {
											unset($this->data[ "VEVENT" ][ $counter ][ 'RECURRENCES' ][ $j ]);
											$this->data[ "VEVENT" ][ $counter ][ 'RECURRENCES' ] = array_values($this->data[ "VEVENT" ][ $counter ][ 'RECURRENCES' ]);
											break;
										}
									}
								}
							}
						}
					}

					if(!empty($event)) {
						$events[] = $event;
					}
				} else {
					$recurrences = $event['RECURRENCES'];
					$event['RECURRING'] = true;
					$event['DTEND'] = !empty($event['DTEND']) ? $event['DTEND'] : $event['DTSTART'];
					$eventInterval = $event['DTSTART']->diff($event['DTEND']);

					$firstEvent = true;
					foreach ($recurrences as $j => $recurDate) {
						$newEvent = $event;
						if (!$firstEvent) {
							unset($newEvent['RECURRENCES']);
							$newEvent['DTSTART'] = $recurDate;
							$newEvent['DTEND'] = clone($recurDate);
							$newEvent['DTEND']->add($eventInterval);
						}

						$newEvent['RECURRENCE_INSTANCE'] = $j;
						$events[] = $newEvent;
						$firstEvent = false;
					}
				}
			}
		}
		return $events;
	}

	/**
	 * @return array
	 */
	public function getAlarms() {
		return isset($this->data['VALARM']) ? $this->data['VALARM'] : [];
	}

	/**
	 * @return array
	 */
	public function getTimezones() {
		return isset($this->data['VTIMEZONE']) ? $this->data['VTIMEZONE'] : [];
	}

	/**
	 * Return sorted event list as array
	 *
	 * @return array
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
		return [];
	}

	/**
	 * @return array
	 */
	public function getReverseSortedEvents() {
		if ($events = $this->getEvents()) {
			usort(
				$events, function ($a, $b) {
				return $a['DTSTART'] < $b['DTSTART'];
			}
			);
			return $events;
		}
		return [];
	}

}
