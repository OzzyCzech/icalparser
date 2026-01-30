<?php
declare(strict_types=1);

namespace om;

use ArrayObject;
use DateException;
use DateInterval;
use DateInvalidOperationException;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTime;
use DateTimeImmutable;
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

	public function __construct(public ParserOptions $options = new ParserOptions()) {}

	/**
	 * @throws Exception
	 */
	public function parseFile(string $file, ?callable $callback = null): ?array {
		if (!$handle = fopen($file, 'rb')) {
			throw new RuntimeException('Can\'t open file' . $file . ' for reading.');
		}
		fclose($handle);

		return $this->parseString(file_get_contents($file), $callback);
	}

	/**
	 * @param boolean $add if true the parsed string is added to existing data
	 * @throws Exception
	 */
	public function parseString(string $string, ?callable $callback = null, bool $add = false): ?array {
		if ($add === false) {
			// delete old data
			$this->data = [];
			$this->counters = [];
		}

		if (!str_contains($string, 'BEGIN:VCALENDAR')) {
			throw new InvalidArgumentException('Invalid ICAL data format');
		}

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
					$this->counters[$section] = isset($this->counters[$section]) ? $this->counters[$section] + 1 : 0;
					continue 2; // while
				case 'END:VEVENT':
					$section = substr($row, 4);
					$currCounter = $this->counters[$section];
					$event = $this->data[$section][$currCounter];
					if (!empty($event['RECURRENCE-ID'])) {
						$this->data['_RECURRENCE_IDS'][$event['RECURRENCE-ID']] = $event;
					}

					continue 2; // while
				case 'END:DAYLIGHT':
				case 'END:VALARM':
				case 'END:VTIMEZONE':
				case 'END:VFREEBUSY':
				case 'END:VJOURNAL':
				case 'END:STANDARD':
				case 'END:VTODO':
					continue 2; // while

				case 'END:VCALENDAR':
					$veventSection = 'VEVENT';
					if (!empty($this->data[$veventSection])) {
						foreach ($this->data[$veventSection] as $currCounter => $event) {
							if (!empty($event['RRULE']) || !empty($event['RDATE'])) {
								$recurrences = $this->parseRecurrences($event);
								if (!empty($recurrences)) {
									$this->data[$veventSection][$currCounter]['RECURRENCES'] = $recurrences;
								}

								if (!empty($event['UID'])) {
									$this->data["_RECURRENCE_COUNTERS_BY_UID"][$event['UID']] = $currCounter;
								}
							}
						}
					}
					continue 2; // while
			}

			[$key, $middle, $value] = $this->parseRow($row);

			if ($callback) {
				// call user function for processing line
				call_user_func($callback, $row, $key, $middle, $value, $section, $this->counters[$section]);
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
						if ($key == 'ORGANIZER') {
							foreach ($middle as $midKey => $midVal) {
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
	public function parseRecurrences($event): array {
		$recurring = new Recurrence($event['RRULE']);
		$timezone = $event['DTSTART']->getTimezone();
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

		// if no UNTIL is set, we set it to +3 years from now
		if ($recurring->getUntil() === false) {

			// set UNTIL to now + shiftEnd
			if ($this->options->untilInterval instanceof DateInterval) {
				$recurring->setUntil(
					new DateTime('today', $timezone)
						->add($this->options->untilInterval)
				);
			} else {
				$recurring->setUntil(
					new DateTimeImmutable('today', $timezone)
				);
			}

			// shift event dates if option is set
			if ($this->options->shiftEventDates instanceof DateInterval) {
				$event['DTSTART'] = $this->shiftDate(
					date: $event['DTSTART'],
					shiftInterval: $this->options->shiftEventDates,
				);

				$event['DTEND'] = $this->shiftDate(
					date: $event['DTEND'],
					shiftInterval: $this->options->shiftEventDates,
				);
			}
		}

		date_default_timezone_set($timezone->getName());
		$frequency = new Freq(
			rule: $recurring->rrule,
			start: $event['DTSTART']->getTimestamp(),
			excluded: $exclusions,
			added: $additions
		);
		$recurrenceTimestamps = $frequency->getAllOccurrences();

		// This guard only works on WEEKLY, because the others have no fixed time interval
		// There may still be a bug with the others
		if (isset($event['RRULE']['INTERVAL']) && $recurring->getFreq() === "WEEKLY") {
			$interval = (int) $event['RRULE']['INTERVAL'];
			if ($interval > 1) {
				$replacementList = [];
				$wkst = $recurring->getWkst() ?: 'MO';
				$wkstMap = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
				$wkstIndex = $wkstMap[$wkst] ?? 1;

				$getStartOfWeek = function ($ts) use ($event, $wkstIndex, $timezone) {
					$dt = new DateTime('now', $timezone);
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

		$recurrences = [];
		foreach ($recurrenceTimestamps as $recurrenceTimestamp) {
			$tmp = DateTime::createFromTimestamp($recurrenceTimestamp)->setTimezone($timezone);

			$recurrenceIDDate = $tmp->format('Ymd');
			$recurrenceIDDateTime = $tmp->format('Ymd\THis');
			if (empty($this->data['_RECURRENCE_IDS'][$recurrenceIDDate]) &&
				empty($this->data['_RECURRENCE_IDS'][$recurrenceIDDateTime])) {
				$gmtCheck = new DateTime('now', new DateTimeZone('UTC'));
				$gmtCheck->setTimestamp($recurrenceTimestamp);
				$recurrenceIDDateTimeZ = $gmtCheck->format('Ymd\THis\Z');
				if (empty($this->data['_RECURRENCE_IDS'][$recurrenceIDDateTimeZ])) {
					$recurrences[] = $tmp;
				}
			}
		}

		return $recurrences;
	}

	/**
	 * Shift event start date forward based on interval until it's in the future
	 *
	 * @param mixed $date
	 * @param DateInterval $shiftInterval
	 * @throws DateInvalidOperationException|DateMalformedStringException
	 * @return DateTime
	 */
	private function shiftDate(DateTime $date, DateInterval $shiftInterval): DateTime {
		$now = new DateTimeImmutable('today', $date->getTimezone());
		$targetDate = $now->sub($shiftInterval);

		// if start is already after target date, return it as is
		if ($date >= $targetDate) {
			return clone $date;
		}

		$resultDate = clone $date;
		$resultDate->setDate(
			(int) $targetDate->format('Y'),
			(int) $date->format('m'),
			(int) $date->format('d')
		);

		return $resultDate;
	}

	/**
	 * @throws DateInvalidTimeZoneException
	 */
	private function parseRow($row): array {
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
				$value = $this->valueToTimezone($value);
				$this->timezone = new DateTimeZone($value);
			}

			// have some middle part ?
			if ($middle && preg_match_all('#(?<key>[^=;]+)=(?<value>[^;]+)#', $middle, $matches, PREG_SET_ORDER)) {
				$middle = [];
				foreach ($matches as $match) {
					if ($match['key'] === 'TZID') {
						$match['value'] = trim($match['value'], "'\"");
						$match['value'] = $this->valueToTimezone($match['value']);
						try {
							$middle[$match['key']] = $timezone = new DateTimeZone($match['value']);
						} catch (DateException) {
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
			} catch (DateException) {
				$value = null;
			}
		} elseif (in_array($key, ['EXDATE', 'RDATE'], true)) {
			$values = [];
			foreach (explode(',', $value) as $singleValue) {
				try {
					$values[] = new DateTime($singleValue, ($timezone ?? $this->timezone));
				} catch (DateException) {
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
				if ($match['key'] === 'UNTIL') {
					try {
						$value[$match['key']] = new DateTime($match['value'], ($timezone ?? $this->timezone));
					} catch (DateException) {
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
	 * @param string $value
	 * @return mixed|null
	 */
	private function valueToTimezone(string $value): mixed {
		return $this->options->windowsTimezones[$value] ?? $value;
	}

	public function isMultipleKey(string $key): ?string {
		return ([
			'ATTACH' => 'ATTACHMENTS',
			'EXDATE' => 'EXDATES',
			'RDATE' => 'RDATES',
			'ATTENDEE' => 'ATTENDEES',
		])[$key] ?? null;
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
		if (isset($this->data['VEVENT'])) {
			foreach ($this->data['VEVENT'] as $iValue) {
				$event = $iValue;

				if (empty($event['RECURRENCES'])) {
					if (!empty($event['RECURRENCE-ID']) && !empty($event['UID']) && isset($event['SEQUENCE'])) {
						$modifiedEventUID = $event['UID'];
						$modifiedEventRecurID = $event['RECURRENCE-ID'];
						$modifiedEventSeq = (int) $event['SEQUENCE'];

						if (isset($this->data['_RECURRENCE_COUNTERS_BY_UID'][$modifiedEventUID])) {
							$counter = $this->data['_RECURRENCE_COUNTERS_BY_UID'][$modifiedEventUID];

							$originalEvent = $this->data['VEVENT'][$counter];
							if (isset($originalEvent['SEQUENCE'])) {
								$originalEventSeq = (int) $originalEvent['SEQUENCE'];
								$originalEventFormattedStartDate = $originalEvent['DTSTART']->format('Ymd\THis');
								if ($modifiedEventRecurID === $originalEventFormattedStartDate && $modifiedEventSeq > $originalEventSeq) {
									// this modifies the original event
									$modifiedEvent = array_replace_recursive($originalEvent, $event);
									$this->data['VEVENT'][$counter] = $modifiedEvent;
									foreach ($events as $z => $event) {
										if ($events[$z]['UID'] === $originalEvent['UID'] &&
											$events[$z]['SEQUENCE'] === $originalEvent['SEQUENCE']) {
											// replace the original event with the modified event
											$events[$z] = $modifiedEvent;
											break;
										}
									}
									$event = null; // don't add this to the $events[] array again
								} elseif (!empty($originalEvent['RECURRENCES'])) {
									for ($j = 0; $j < count($originalEvent['RECURRENCES']); $j++) {
										$recurDate = $originalEvent['RECURRENCES'][$j];
										$formattedStartDate = $recurDate->format('Ymd\THis');
										if ($formattedStartDate === $modifiedEventRecurID) {
											unset($this->data['VEVENT'][$counter]['RECURRENCES'][$j]);
											$this->data['VEVENT'][$counter]['RECURRENCES'] = array_values($this->data['VEVENT'][$counter]['RECURRENCES']);
											break;
										}
									}
								}
							}
						}
					}

					if (!empty($event)) {
						$events->append($event);
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
						$events->append($newEvent);
						$firstEvent = false;
					}
				}
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
