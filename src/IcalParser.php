<?php
declare(strict_types=1);

namespace om;

use ArrayObject;
use DateInterval;
use DateInvalidTimeZoneException;
use DateTime;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Copyright (c) Roman Ožana (https://ozana.cz)
 *
 * @license BSD-3-Clause
 * @author Roman Ožana <roman@ozana.cz>
 */
class IcalParser {
	public ?DateTimeZone $timezone = null;
	public ?array $data = null;
	protected array $counters = [];
	private array $windowsTimezones;

	public function __construct() {
		$this->windowsTimezones = require __DIR__ . '/WindowsTimezones.php'; // load Windows timezones from separate file
	}

	/**
	 * @throws Exception
	 */
	public function parseFile(string $file, ?callable $callback = null): ?array {
		$content = @file_get_contents($file);
		if ($content === false) {
			throw new RuntimeException(sprintf('Cannot read iCalendar file "%s".', $file));
		}

		return $this->parseString($content, $callback);
	}

	/**
	 * @param boolean $add if true the parsed string is added to existing data
	 * @throws Exception
	 */
	public function parseString(string $string, ?callable $callback = null, bool $add = false): ?array {
		if (!str_contains($string, 'BEGIN:VCALENDAR')) {
			throw new InvalidArgumentException('Invalid ICAL data format');
		}

		if ($add === false) {
			// delete old data
			$this->data = [];
			$this->counters = [];
		}

		$section = 'VCALENDAR';
		$sections = [];

		// Replace \r\n with \n
		$string = str_replace("\r\n", "\n", $string);

		// Unfold multi-line strings
		$string = str_replace(["\n ", "\n\t"], '', $string);

		foreach (explode("\n", $string) as $row) {

			switch ($row) {
				case '':
				case 'BEGIN:VCALENDAR':
					continue 2;
				case 'BEGIN:DAYLIGHT':
				case 'BEGIN:VALARM':
				case 'BEGIN:VTIMEZONE':
				case 'BEGIN:VFREEBUSY':
				case 'BEGIN:VJOURNAL':
				case 'BEGIN:STANDARD':
				case 'BEGIN:VTODO':
				case 'BEGIN:VEVENT':
					$sections[] = $section;
					$section = substr($row, 6);
					$this->counters[$section] = isset($this->counters[$section]) ? $this->counters[$section] + 1 : 0;
					if ($callback === null) {
						$this->data[$section][$this->counters[$section]] = [];
					}
					continue 2; // while
				case 'END:VEVENT':
					$section = substr($row, 4);
					$currCounter = $this->counters[$section];
					$event = $this->data[$section][$currCounter] ?? [];
					if (isset($event['RECURRENCE-ID'], $event['UID'])) {
						$this->data['_RECURRENCE_IDS'][$event['UID']][$event['RECURRENCE-ID']] = $event;
					}
					$section = array_pop($sections) ?? 'VCALENDAR';
					continue 2; // while
				case 'END:DAYLIGHT':
				case 'END:VALARM':
				case 'END:VTIMEZONE':
				case 'END:VFREEBUSY':
				case 'END:VJOURNAL':
				case 'END:STANDARD':
				case 'END:VTODO':
					$section = array_pop($sections) ?? 'VCALENDAR';
					continue 2; // while

				case 'END:VCALENDAR':
					$veventSection = 'VEVENT';
					if (!empty($this->data[$veventSection])) {
						foreach ($this->data[$veventSection] as $currCounter => $event) {
							if (!empty($event['RRULE']) || !empty($event['RDATE']) || !empty($event['EXDATE'])) {
								$recurrences = $this->parseRecurrences($event);
								$this->data[$veventSection][$currCounter]['RECURRENCES'] = $recurrences;

								if (!empty($event['UID'])) {
									$this->data["_RECURRENCE_COUNTERS_BY_UID"][$event['UID']] = $currCounter;
								}
							}
						}
					}
					continue 2; // while
			}

			[$key, $middle, $value] = $this->parseRow($row);
			if ($key === false) {
				continue;
			}

			if ($callback) {
				// call user function for processing line
				$callback($row, $key, $middle, $value, $section, $this->counters[$section] ?? 0);
			} else {
				if ($section === 'VCALENDAR') {
					$this->data[$key] = $value;
				} else {

					// use an array since there can be multiple entries for this key.  This does not
					// break the current implementation--it leaves the original key alone and adds
					// a new one specifically for the array of values.

					if ($newKey = $this->isMultipleKey((string) $key)) {
						$this->data[$section][$this->counters[$section]][$newKey][] = $value;
					}

					// CATEGORIES can be multiple also but there is special case that there are comma separated categories

					if ($this->isMultipleKeyWithCommaSeparation($key)) {

						if (str_contains($value, ',')) {
							// split on commas not preceded by backslash
							$values = array_map('trim', preg_split('/(?<!\\\\),/', $value));
						} else {
							$values = [$value];
						}

						foreach ($values as $value) {
							$this->data[$section][$this->counters[$section]][$key][] = $value;
						}

					} else {
						if ($key === 'ORGANIZER') {
							foreach ((is_array($middle) ? $middle : []) as $midKey => $midVal) {
								$this->data[$section][$this->counters[$section]][$key . '-' . $midKey] = $midVal;
							}
						}
						if (in_array($key, ['ATTENDEE', 'ORGANIZER'])) {
							$value = $value['VALUE'];    // backwards compatibility (leaves ATTENDEE entry as it was)
						}
						$this->data[$section][$this->counters[$section]][$key] = $value;
					}

				}

			}
		}

		return ($callback) ? null : $this->data;
	}

	/**
	 * @param $event
	 * @throws Exception
	 * @return array
	 */
	public function parseRecurrences(array $event): array {
		$recurring = new Recurrence($event['RRULE'] ?? []);
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

		if (isset($event['RRULE']) && $recurring->getUntil() === false && $recurring->getCount() === false) {
			//forever... limit to 3 years from now
			$end = new DateTime('now');
			$end->add(new DateInterval('P3Y')); // + 3 years
			$recurring->setUntil($end);
		}

		$defaultTimezone = date_default_timezone_get();
		$tzName = $event['DTSTART']->getTimezone()->getName();
		try {
			date_default_timezone_set($tzName === 'Z' ? 'UTC' : $tzName);
			$recurrenceTimestamps = isset($event['RRULE'])
				? (new Freq($recurring->rrule, $event['DTSTART']->getTimestamp()))->getAllOccurrences()
				: [$event['DTSTART']->getTimestamp()];
		} finally {
			date_default_timezone_set($defaultTimezone);
		}

		// This guard only works on WEEKLY, because the others have no fixed time interval
		// There may still be a bug with the others
		if (isset($event['RRULE']['INTERVAL']) && $recurring->getFreq() === "WEEKLY") {
			$interval = (int) $event['RRULE']['INTERVAL'];
			if ($interval > 1) {
				$replacementList = [];
				$wkst = $recurring->getWkst() ?: 'MO';
				$wkstMap = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
				$wkstIndex = $wkstMap[$wkst] ?? 1;

				$getStartOfWeek = function ($ts) use ($event, $wkstIndex) {
					$dt = new DateTime('now', $event['DTSTART']->getTimezone());
					$dt->setTimestamp($ts);
					$dt->setTime(0, 0, 0);
					$w = (int) $dt->format('w');
					$diff = $w - $wkstIndex;
					if ($diff < 0)
						$diff += 7;
					$dt->modify("-{$diff} days");
					return $dt->getTimestamp();
				};

				$startOfWeekStart = $getStartOfWeek($event['DTSTART']->getTimestamp());

				foreach ($recurrenceTimestamps as $timestamp) {
					$startOfWeekCurrent = $getStartOfWeek($timestamp);
					$diffWeeks = (int) round(($startOfWeekCurrent - $startOfWeekStart) / 604800);

					if ($diffWeeks % $interval == 0) {
						$replacementList[] = $timestamp;
					}
				}

				$recurrenceTimestamps = $replacementList;
			}
		}

		// Apply set operations after RRULE filtering: RDATE is independent of INTERVAL,
		// and EXDATE takes precedence over both generated and explicitly added dates.
		$recurrenceTimestamps = array_values(array_unique(array_diff(
			array_merge($recurrenceTimestamps, $additions), $exclusions,
		)));
		sort($recurrenceTimestamps, SORT_NUMERIC);
		$overrides = $this->data['_RECURRENCE_IDS'][$event['UID'] ?? ''] ?? [];
		$recurrences = [];
		foreach ($recurrenceTimestamps as $recurrenceTimestamp) {
			$tmp = new DateTime('now', $event['DTSTART']->getTimezone());
			$tmp->setTimestamp($recurrenceTimestamp);

			$recurrenceIDDate = $tmp->format('Ymd');
			$recurrenceIDDateTime = $tmp->format('Ymd\THis');
			if (empty($overrides[$recurrenceIDDate]) && empty($overrides[$recurrenceIDDateTime])) {
				$gmtCheck = new DateTime('now', new DateTimeZone('UTC'));
				$gmtCheck->setTimestamp($recurrenceTimestamp);
				$recurrenceIDDateTimeZ = $gmtCheck->format('Ymd\THis\Z');
				if (empty($overrides[$recurrenceIDDateTimeZ])) {
					$recurrences[] = $tmp;
				}
			}
		}

		return $recurrences;
	}

	/**
	 * @throws DateInvalidTimeZoneException
	 */
	private function parseRow(string $row): array {
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
				$resolved = $this->resolveTimezone($value);
				if ($resolved !== null) {
					$value = $resolved->getName();
					$this->timezone = $resolved;
				}
			}

			// have some middle part ?
			if ($middle && preg_match_all('#(?<key>[^=;]+)=(?<value>[^;]+)#', $middle, $matches, PREG_SET_ORDER)) {
				$middle = [];
				foreach ($matches as $match) {
					if ($match['key'] === 'TZID') {
						$match['value'] = trim($match['value'], "'\"");
						$resolved = $this->resolveTimezone($match['value']);
						if ($resolved !== null) {
							$middle[$match['key']] = $timezone = $resolved;
						} else {
							$middle[$match['key']] = $match['value'];
						}
					} elseif ($match['key'] === 'ENCODING') {
						if ($match['value'] === 'QUOTED-PRINTABLE') {
							$value = quoted_printable_decode($value);
						}
					} else {
						$middle[$match['key']] = $match['value'];
					}
				}
			}
		}

		// process simple dates with timezone
		if (in_array($key, ['DTSTAMP', 'LAST-MODIFIED', 'CREATED', 'DTSTART', 'DTEND'], true)) {
			try {
				$value = new DateTime($value, ($timezone ?? $this->timezone));
			} catch (Exception $e) {
				$value = null;
			}
		} elseif (in_array($key, ['EXDATE', 'RDATE'])) {
			$values = [];
			foreach (explode(',', $value) as $singleValue) {
				try {
					$values[] = new DateTime($singleValue, ($timezone ?? $this->timezone));
				} catch (Exception $e) {
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
						$value[$match['key']] = new DateTime($match['value'], ($timezone ?? $this->timezone));
					} catch (Exception $e) {
						$value[$match['key']] = $match['value'];
					}
				} else {
					$value[$match['key']] = $match['value'];
				}
			}
		}

		//implement 4.3.11 Text ESCAPED-CHAR
		$text_properties = [
			'CALSCALE', 'METHOD', 'PRODID', 'VERSION', 'CATEGORIES', 'CLASS', 'COMMENT', 'DESCRIPTION',
			'LOCATION', 'RESOURCES', 'STATUS', 'SUMMARY', 'TRANSP', 'TZID', 'TZNAME', 'CONTACT',
			'RELATED-TO', 'UID', 'ACTION', 'REQUEST-STATUS', 'URL',
		];

		if (in_array($key, $text_properties, true) || str_starts_with((string) $key, 'X-')) {
			if (is_array($value)) {
				foreach ($value as &$var) {
					$var = strtr($var, ['\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\\;' => ';', '\\,' => ',']);
				}
			} else {
				$value = strtr($value, ['\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\\;' => ';', '\\,' => ',']);
			}
		}

		if (in_array($key, ['ATTENDEE', 'ORGANIZER'])) {
			$value = array_merge(is_array($middle) ? $middle : ['middle' => $middle], ['VALUE' => $value]);
		}

		return [$key, $middle, $value];
	}

	/**
	 * Process timezone and return correct one...
	 *
	 * @param string $zone
	 * @return mixed|null
	 */
	private function toTimezone(string $zone): mixed {
		return $this->windowsTimezones[$zone] ?? $zone;
	}

	/**
	 * Extract and resolve timezone from a TZID or X-WR-TIMEZONE value.
	 * Handles prefixed values (e.g. /mozilla.org/.../Europe/Paris) and
	 * multi-segment IANA zones (e.g. America/Argentina/Buenos_Aires).
	 */
	private function resolveTimezone(string $value): ?DateTimeZone {
		$parts = preg_split('#[/\\\\]#', $value);
		$parts = array_values(array_filter($parts));

		$count = count($parts);
		if ($count < 2) {
			// no slashes - try as-is via windowsTimezones lookup
			$resolved = $this->toTimezone(trim($value));
			try {
				return new DateTimeZone($resolved);
			} catch (Exception) {
				return null;
			}
		}

		// try building timezone paths from the end, shortest first
		// e.g. for "/mozilla.org/20070129_1/Europe/Paris":
		//   try "Europe/Paris" ✓
		// e.g. for "America/Argentina/Buenos_Aires":
		//   try "Argentina/Buenos_Aires" ✗, then "America/Argentina/Buenos_Aires" ✓
		for ($length = 2; $length <= $count; $length++) {
			$candidate = implode('/', array_slice($parts, $count - $length));
			$resolved = $this->toTimezone($candidate);
			try {
				return new DateTimeZone($resolved);
			} catch (Exception) {
			}
		}

		return null;
	}

	public function isMultipleKey(string $key): ?string {
		return (['ATTACH' => 'ATTACHMENTS', 'EXDATE' => 'EXDATES', 'RDATE' => 'RDATES', 'ATTENDEE' => 'ATTENDEES'])[$key] ?? null;
	}

	/**
	 * @param $key
	 * @return string|null
	 */
	public function isMultipleKeyWithCommaSeparation($key): ?string {
		return (['X-CATEGORIES' => 'X-CATEGORIES', 'CATEGORIES' => 'CATEGORIES'])[$key] ?? null;
	}

	public function getAlarms(): array {
		return $this->data['VALARM'] ?? [];
	}

	public function getTimezone(): array {
		return $this->getTimezones();
	}

	public function getTimezones(): array {
		return $this->data['VTIMEZONE'] ?? [];
	}

	/**
	 * Return sorted event list as ArrayObject
	 *
	 * @deprecated use IcalParser::getEvents()->sorted() instead
	 */
	public function getSortedEvents(): ArrayObject {
		return $this->getEvents()->sorted();
	}

	public function getEvents(): EventsList {
		$events = new EventsList();
		foreach ($this->data['VEVENT'] ?? [] as $event) {
			if (!array_key_exists('RECURRENCES', $event)) {
				$events->append($event);
				continue;
			}

			$event['RECURRING'] = true;
			$eventInterval = $event['DTSTART']->diff($event['DTEND'] ?? $event['DTSTART']);
			foreach ($event['RECURRENCES'] as $index => $date) {
				$instance = $event;
				if ($index !== 0) {
					unset($instance['RECURRENCES']);
				}
				$instance['DTSTART'] = clone $date;
				$instance['DTEND'] = (clone $date)->add($eventInterval);
				$instance['RECURRENCE_INSTANCE'] = $index;
				$events->append($instance);
			}
		}
		return $events;
	}

	/**
	 * @return ArrayObject
	 * @deprecated use IcalParser::getEvents->reversed();
	 */
	public function getReverseSortedEvents(): ArrayObject {
		return $this->getEvents()->reversed();
	}

}
