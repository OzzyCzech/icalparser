<?php
declare(strict_types=1);

namespace om;

use ArrayObject;
use DateInterval;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use om\Parser\ContentLine;
use om\RRule\Expander;
use om\RRule\Rule;
use RuntimeException;

/**
 * iCalendar (RFC 5545) parser producing PHP arrays.
 *
 * Copyright (c) Roman Ožana (https://ozana.cz)
 *
 * @license BSD-3-Clause
 * @author Roman Ožana <roman@ozana.cz>
 */
class IcalParser {
	private const array DATE_PROPERTIES = ['DTSTAMP' => true, 'LAST-MODIFIED' => true, 'CREATED' => true, 'DTSTART' => true, 'DTEND' => true, 'DUE' => true, 'COMPLETED' => true];
	private const array MULTIPLE_KEYS = ['ATTACH' => 'ATTACHMENTS', 'EXDATE' => 'EXDATES', 'RDATE' => 'RDATES', 'ATTENDEE' => 'ATTENDEES'];
	private const array COMMA_SEPARATED_KEYS = ['X-CATEGORIES' => 'X-CATEGORIES', 'CATEGORIES' => 'CATEGORIES'];
	private const array META_KEYS = ['DTSTART' => true, 'RRULE' => true, 'EXDATE' => true, 'RECURRENCE-ID' => true];

	/** Properties of the TEXT value type (RFC 5545, section 3.3.11) that are unescaped. */
	private const array TEXT_PROPERTIES = [
		'CALSCALE' => true, 'METHOD' => true, 'PRODID' => true, 'VERSION' => true, 'CATEGORIES' => true,
		'CLASS' => true, 'COMMENT' => true, 'DESCRIPTION' => true, 'LOCATION' => true, 'RESOURCES' => true,
		'STATUS' => true, 'SUMMARY' => true, 'TRANSP' => true, 'TZID' => true, 'TZNAME' => true, 'CONTACT' => true,
		'RELATED-TO' => true, 'UID' => true, 'ACTION' => true, 'REQUEST-STATUS' => true, 'URL' => true,
	];

	private const array TEXT_ESCAPES = ['\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\\;' => ';', '\\,' => ','];

	/** Timezone of floating dates: the last X-WR-TIMEZONE or TZID property seen. */
	public ?DateTimeZone $timezone = null;
	/** @var array<string, mixed>|null */
	public ?array $data = null;
	/** @var array<string, int> */
	protected array $counters = [];

	private readonly ParserOptions $options;
	private readonly TimezoneResolver $timezones;

	/**
	 * Parser details of components that are not part of the public data:
	 * date-only flags, raw RRULE and RECURRENCE-ID timezone.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $meta = [];

	/**
	 * Overridden instances (VEVENT with RECURRENCE-ID) by UID.
	 *
	 * @var array<string, list<array{value: string, timezone: ?DateTimeZone}>>
	 */
	private array $overrides = [];

	public function __construct(?ParserOptions $options = null) {
		$this->options = $options ?? new ParserOptions();
		$this->timezones = new TimezoneResolver($this->options->windowsTimezones ?? []);
	}

	/**
	 * Parse a file or any stream wrapper URL.
	 *
	 * @throws RuntimeException when the file cannot be read
	 * @throws InvalidArgumentException when the content is not iCalendar data
	 */
	/**
	 * @return array<string, mixed>|null
	 */
	public function parseFile(string $file, ?callable $callback = null): ?array {
		// the content is passed as a temporary value, so parseString() can free it while normalizing
		return $this->parseString(self::readFile($file), $callback);
	}

	private static function readFile(string $file): string {
		$content = @file_get_contents($file);
		if ($content === false) {
			throw new RuntimeException(sprintf('Cannot read iCalendar file "%s".', $file));
		}
		return $content;
	}

	/**
	 * Parse iCalendar data.
	 *
	 * With a callback, rows are not stored; the callback receives every property row as
	 * ($row, $key, $middle, $value, $section, $counter) and the method returns null.
	 *
	 * @param bool $add if true the parsed string is added to existing data
	 * @return array<string, mixed>|null
	 * @throws InvalidArgumentException when the content is not iCalendar data
	 */
	public function parseString(string $string, ?callable $callback = null, bool $add = false): ?array {
		if (stripos($string, 'BEGIN:VCALENDAR') === false) {
			throw new InvalidArgumentException('Invalid ICAL data format');
		}

		if ($add === false || $this->data === null) {
			$this->data = [];
			$this->counters = [];
			$this->meta = [];
			$this->overrides = [];
			$this->timezone = null;
		}

		// Normalize line breaks and unfold lines (RFC 5545, section 3.1). Each replacement
		// copies the whole string, so rare patterns are replaced only when present.
		$string = str_replace("\r\n", "\n", $string);
		if (str_contains($string, "\r")) {
			$string = str_replace("\r", "\n", $string);
		}
		$string = str_replace("\n ", '', $string);
		if (str_contains($string, "\n\t")) {
			$string = str_replace("\n\t", '', $string);
		}
		if (str_starts_with($string, "\u{FEFF}")) {
			$string = substr($string, 3);
		}

		$section = 'VCALENDAR';
		$parents = [];

		foreach (explode("\n", $string) as $row) {
			if ($row === '') {
				continue;
			}

			if (strncasecmp($row, 'BEGIN:', 6) === 0) {
				$component = strtoupper(trim(substr($row, 6)));
				if ($component !== 'VCALENDAR') {
					$parents[] = $section;
					$section = $component;
					$this->counters[$section] = isset($this->counters[$section]) ? $this->counters[$section] + 1 : 0;
					if ($callback === null) {
						$this->data[$section][$this->counters[$section]] = [];
					}
				}
				continue;
			}

			if (strncasecmp($row, 'END:', 4) === 0) {
				$component = strtoupper(trim(substr($row, 4)));
				if ($component !== 'VCALENDAR') {
					if ($component === 'VEVENT' && $callback === null && isset($this->data['VEVENT'][$this->counters['VEVENT'] ?? -1]['RECURRENCE-ID'])) {
						$this->registerOverride($this->counters['VEVENT']);
					}
					$section = array_pop($parents) ?? 'VCALENDAR';
				}
				continue;
			}

			$row = $this->parseRow($row);
			if ($row === null) {
				continue;
			}
			[$key, $middle, $value, $raw, $line] = $row;

			if ($callback) {
				$callback($line, $key, $middle, $value, $section, $this->counters[$section] ?? 0);
			} elseif ($section === 'VCALENDAR') {
				$this->data[$key] = $value;
			} else {
				$this->store($section, $this->counters[$section], $key, $middle, $value, $raw);
			}
		}

		if ($callback) {
			return null;
		}

		$this->expandRecurringEvents();
		return $this->data;
	}

	/**
	 * Expand the recurrence set of an event (RRULE, RDATE and EXDATE) into DateTime objects.
	 *
	 * @param array<string, mixed> $event parsed VEVENT
	 * @return list<DateTime>
	 * @throws InvalidArgumentException for an invalid RRULE in strict mode
	 */
	public function parseRecurrences(array $event): array {
		// details such as a date-only UNTIL or EXDATE are known for events of the parsed data
		$counter = array_search($event, $this->data['VEVENT'] ?? [], true);
		return $this->recurrences($event, $counter === false ? [] : $this->meta['VEVENT'][$counter] ?? []);
	}

	public function isMultipleKey(string $key): ?string {
		return self::MULTIPLE_KEYS[$key] ?? null;
	}

	public function isMultipleKeyWithCommaSeparation(string $key): ?string {
		return self::COMMA_SEPARATED_KEYS[$key] ?? null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getAlarms(): array {
		return $this->data['VALARM'] ?? [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getTimezone(): array {
		return $this->getTimezones();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getTimezones(): array {
		return $this->data['VTIMEZONE'] ?? [];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getTodos(): array {
		return array_values($this->data['VTODO'] ?? []);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getJournals(): array {
		return array_values($this->data['VJOURNAL'] ?? []);
	}

	/**
	 * Return sorted event list as ArrayObject
	 *
	 * @deprecated use IcalParser::getEvents()->sorted() instead
	 * @return ArrayObject<int, array<string, mixed>>
	 */
	public function getSortedEvents(): ArrayObject {
		return $this->getEvents()->sorted();
	}

	/**
	 * @deprecated use IcalParser::getEvents()->reversed() instead
	 * @return ArrayObject<int, array<string, mixed>>
	 */
	public function getReverseSortedEvents(): ArrayObject {
		return $this->getEvents()->reversed();
	}

	/**
	 * Events with recurring events expanded into single instances.
	 *
	 * Every instance has DTEND: from DTEND, from DURATION, or one day for all-day
	 * events (RFC 5545, section 3.6.1). Recurring instances also carry RECURRING
	 * and RECURRENCE_INSTANCE (zero based).
	 */
	public function getEvents(): EventsList {
		$events = new EventsList();
		foreach ($this->data['VEVENT'] ?? [] as $counter => $event) {
			$start = $event['DTSTART'] ?? null;
			if (!isset($event['RECURRENCES']) || !$start instanceof DateTimeInterface) {
				if (!array_key_exists('DTEND', $event) && $start instanceof DateTimeInterface
					&& ($duration = $this->duration($event, $this->meta['VEVENT'][$counter] ?? [])) !== null) {
					$end = DateTime::createFromInterface($start);
					$event['DTEND'] = $end->add($duration);
				}
				$events->append($event);
				continue;
			}

			$event['RECURRING'] = true;
			$duration = $this->duration($event, $this->meta['VEVENT'][$counter] ?? []) ?? new DateInterval('PT0S');
			$template = $event;
			unset($template['RECURRENCES']);
			foreach ($event['RECURRENCES'] as $index => $date) {
				if (!$date instanceof DateTime) {
					continue;
				}
				$instance = $index === 0 ? $event : $template;
				$instance['DTSTART'] = clone $date;
				$instance['DTEND'] = (clone $date)->add($duration);
				$instance['RECURRENCE_INSTANCE'] = $index;
				$events->append($instance);
			}
		}
		return $events;
	}

	/**
	 * Store a property of a component in the public data array.
	 */
	private function store(string $section, int $counter, string $key, mixed $middle, mixed $value, string $raw): void {
		$this->data ??= [];

		// Multiple entries are collected in an array under a separate key,
		// the original key keeps the last value.
		if ($newKey = self::MULTIPLE_KEYS[$key] ?? null) {
			$this->data[$section][$counter][$newKey][] = $value;
		}

		if (isset(self::COMMA_SEPARATED_KEYS[$key])) {
			// split on commas not preceded by backslash, then unescape
			foreach (preg_split('/(?<!\\\\),/', $raw) ?: [] as $item) {
				$this->data[$section][$counter][$key][] = trim(strtr($item, self::TEXT_ESCAPES));
			}
			return;
		}

		if ($key === 'ORGANIZER') {
			foreach (is_array($middle) ? $middle : [] as $midKey => $midVal) {
				$this->data[$section][$counter][$key . '-' . $midKey] = $midVal;
			}
		}
		if ($key === 'ATTENDEE' || $key === 'ORGANIZER') {
			$value = $value['VALUE']; // backwards compatibility (leaves ATTENDEE entry as it was)
		}
		$this->data[$section][$counter][$key] = $value;

		if (isset(self::META_KEYS[$key])) {
			$this->storeMeta($section, $counter, $key, $middle, $raw);
		}
	}

	/**
	 * Remember parser details that the public data cannot express.
	 * Only values other than the defaults are stored, to keep large calendars small.
	 */
	private function storeMeta(string $section, int $counter, string $key, mixed $middle, string $raw): void {
		$params = is_array($middle) ? $middle : [];
		$dateOnly = ($params['VALUE'] ?? null) === 'DATE';
		switch ($key) {
			case 'DTSTART':
				if ($dateOnly || (strlen($raw) === 8 && ctype_digit($raw))) {
					$this->meta[$section][$counter]['dateOnly'] = true;
				} else {
					unset($this->meta[$section][$counter]['dateOnly']);
				}
				break;
			case 'RRULE':
				$this->meta[$section][$counter]['rrule'] = $raw;
				break;
			case 'EXDATE':
				foreach (explode(',', $raw) as $item) {
					$item = trim($item);
					if ($dateOnly || preg_match('/^\d{8}$/D', $item)) {
						$this->meta[$section][$counter]['exdateDays'][] = substr($item, 0, 8);
					}
				}
				break;
			case 'RECURRENCE-ID':
				if (($params['TZID'] ?? null) instanceof DateTimeZone) {
					$this->meta[$section][$counter]['recurrenceIdTimezone'] = $params['TZID'];
				}
				break;
		}
	}

	private function registerOverride(int $counter): void {
		$event = $this->data['VEVENT'][$counter] ?? [];
		if (!isset($event['RECURRENCE-ID'], $event['UID']) || !is_string($event['RECURRENCE-ID'])) {
			return;
		}
		$this->data['_RECURRENCE_IDS'][$event['UID']][$event['RECURRENCE-ID']] = $event;
		$this->overrides[$event['UID']][] = [
			'value' => $event['RECURRENCE-ID'],
			'timezone' => $this->meta['VEVENT'][$counter]['recurrenceIdTimezone'] ?? null,
		];
	}

	private function expandRecurringEvents(): void {
		foreach ($this->data['VEVENT'] ?? [] as $counter => $event) {
			if (empty($event['RRULE']) && empty($event['RDATE']) && empty($event['EXDATE'])) {
				continue;
			}
			if (!($event['DTSTART'] ?? null) instanceof DateTimeInterface) {
				continue;
			}
			$this->data['VEVENT'][$counter]['RECURRENCES'] = $this->recurrences($event, $this->meta['VEVENT'][$counter] ?? []);
			if (!empty($event['UID'])) {
				$this->data['_RECURRENCE_COUNTERS_BY_UID'][$event['UID']] = $counter;
			}
		}
	}

	/**
	 * @param array<string, mixed> $event
	 * @param array<string, mixed> $meta
	 * @return list<DateTime>
	 */
	private function recurrences(array $event, array $meta): array {
		$start = $event['DTSTART'] ?? null;
		if (!$start instanceof DateTimeInterface) {
			throw new InvalidArgumentException('A recurring event requires a valid DTSTART.');
		}
		$start = DateTime::createFromInterface($start);
		$timezone = $start->getTimezone();

		$timestamps = [$start->getTimestamp()];
		if (!empty($event['RRULE'])) {
			try {
				$rule = isset($meta['rrule']) ? Rule::fromString($meta['rrule']) : Rule::fromArray($event['RRULE']);
				$timestamps = $this->expandRule($rule, $start);
			} catch (InvalidArgumentException $e) {
				if ($this->options->strict) {
					throw $e;
				}
			}
		}

		// RDATE is independent of INTERVAL, EXDATE takes precedence over RRULE and RDATE
		$timestamps = array_merge($timestamps, self::timestamps($event['RDATES'] ?? []));
		$timestamps = array_diff($timestamps, self::timestamps($event['EXDATES'] ?? []));
		$timestamps = array_unique($timestamps);
		sort($timestamps, SORT_NUMERIC);

		$excludedDays = array_fill_keys($meta['exdateDays'] ?? [], true);
		[$overriddenTimestamps, $overriddenDays] = $this->overriddenInstances($event['UID'] ?? null, $timezone);

		$recurrences = [];
		foreach ($timestamps as $timestamp) {
			$date = (clone $start)->setTimestamp($timestamp);
			if (isset($overriddenTimestamps[$timestamp])) {
				continue;
			}
			if ($excludedDays !== [] || $overriddenDays !== []) {
				$day = $date->format('Ymd');
				if (isset($excludedDays[$day]) || isset($overriddenDays[$day])) {
					continue;
				}
			}
			$recurrences[] = $date;
		}
		return $recurrences;
	}

	/**
	 * @return list<int>
	 */
	private function expandRule(Rule $rule, DateTimeInterface $start): array {
		$horizon = $from = null;
		if ($rule->count === null && $rule->until === null) {
			$now = $this->options->now();
			$horizon = ($this->options->untilInterval ? $now->add($this->options->untilInterval) : $now)->getTimestamp();
			if ($this->options->shiftEventDates) {
				$from = $now->sub($this->options->shiftEventDates)->getTimestamp();
			}
		}

		$timestamps = [];
		$limit = $this->options->maxOccurrences;
		foreach (new Expander($rule, $start, $horizon, PHP_INT_MAX) as $timestamp) {
			if ($from !== null && $timestamp < $from) {
				continue;
			}
			if (count($timestamps) >= $limit) {
				if ($this->options->strict) {
					throw new RuntimeException("Recurrence occurrence limit of $limit exceeded.");
				}
				break;
			}
			$timestamps[] = $timestamp;
		}
		return $timestamps;
	}

	/**
	 * Instances replaced by a VEVENT with the same UID and a RECURRENCE-ID.
	 *
	 * @return array{array<int, true>, array<string, true>} timestamps, and days of date-only IDs
	 */
	private function overriddenInstances(?string $uid, DateTimeZone $timezone): array {
		$timestamps = $days = [];
		foreach ($this->overrides[$uid ?? ''] ?? [] as ['value' => $value, 'timezone' => $idTimezone]) {
			$value = trim($value);
			if (preg_match('/^\d{8}$/D', $value)) {
				$days[$value] = true;
				continue;
			}
			try {
				$timestamps[(new DateTime($value, $idTimezone ?? $timezone))->getTimestamp()] = true;
			} catch (Exception) {
				// invalid RECURRENCE-ID matches nothing
			}
		}
		return [$timestamps, $days];
	}

	/**
	 * @param array<string, mixed> $event
	 * @param array<string, mixed> $meta
	 */
	private function duration(array $event, array $meta): ?DateInterval {
		if (($event['DTEND'] ?? null) instanceof DateTimeInterface) {
			return $event['DTSTART']->diff($event['DTEND']);
		}
		if (is_string($event['DURATION'] ?? null) && ($duration = self::parseDuration($event['DURATION'])) !== null) {
			return $duration;
		}
		return !empty($meta['dateOnly']) ? new DateInterval('P1D') : null;
	}

	/**
	 * Parse a DURATION value (RFC 5545, section 3.3.6), e.g. "PT1H30M", "-P1W" or "P1DT12H".
	 */
	public static function parseDuration(string $value): ?DateInterval {
		if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/D', strtoupper(trim($value)), $match) || !preg_match('/\d/', $value)) {
			return null;
		}
		$days = (int) ($match[2] ?? 0) * 7 + (int) ($match[3] ?? 0);
		$interval = new DateInterval(sprintf('P%dDT%dH%dM%dS', $days, (int) ($match[4] ?? 0), (int) ($match[5] ?? 0), (int) ($match[6] ?? 0)));
		$interval->invert = ($match[1] ?? '') === '-' ? 1 : 0;
		return $interval;
	}

	/**
	 * @param array<mixed> $dates EXDATES or RDATES: dates and lists of dates
	 * @return list<int>
	 */
	private static function timestamps(array $dates): array {
		$result = [];
		foreach ($dates as $date) {
			foreach (is_array($date) ? $date : [$date] as $single) {
				if ($single instanceof DateTimeInterface) {
					$result[] = $single->getTimestamp();
				}
			}
		}
		return $result;
	}

	/**
	 * Parse one content line (RFC 5545, section 3.1) into its name, parameters and value.
	 *
	 * @return array{string, mixed, mixed, string, string}|null [key, middle, value, raw value, line]
	 */
	private function parseRow(string $row): ?array {
		$line = ContentLine::split($row);
		if ($line === null) {
			return null;
		}
		[$key, $middle, $raw] = $line;
		$value = $raw;
		$timezone = null;

		if ($key === 'X-WR-TIMEZONE' || $key === 'TZID') {
			$resolved = $this->timezones->resolve($value);
			if ($resolved !== null) {
				$value = $resolved->getName();
				$this->timezone = $resolved;
			}
		}

		if ($middle !== '' && ($params = ContentLine::parameters($middle)) !== []) {
			$middle = [];
			foreach ($params as $name => $paramValue) {
				if ($name === 'TZID') {
					$resolved = $this->timezones->resolve($paramValue);
					$middle[$name] = $resolved ?? $paramValue;
					$timezone = $resolved;
				} elseif ($name === 'ENCODING') {
					if (strtoupper($paramValue) === 'QUOTED-PRINTABLE') {
						$value = $raw = quoted_printable_decode($value);
					}
				} else {
					$middle[$name] = $paramValue;
				}
			}
		}

		if (isset(self::DATE_PROPERTIES[$key])) {
			$value = self::createDate($value, $timezone ?? $this->timezone);
		} elseif ($key === 'EXDATE' || $key === 'RDATE') {
			$values = [];
			foreach (explode(',', $value) as $singleValue) {
				// a PERIOD value (start/end or start/duration) is represented by its start
				$singleValue = strstr($singleValue, '/', true) ?: $singleValue;
				if (($date = self::createDate($singleValue, $timezone ?? $this->timezone)) !== null) {
					$values[] = $date;
				}
			}
			$value = count($values) === 1 ? $values[0] : $values;
		} elseif ($key === 'RRULE' && preg_match_all('#(?<key>[^=;]+)=(?<value>[^;]+)#', $value, $matches, PREG_SET_ORDER)) {
			$middle = null;
			$value = [];
			foreach ($matches as $match) {
				if ($match['key'] === 'UNTIL') {
					$value[$match['key']] = self::createDate($match['value'], $timezone ?? $this->timezone) ?? $match['value'];
				} else {
					$value[$match['key']] = $match['value'];
				}
			}
		} elseif (isset(self::TEXT_PROPERTIES[$key]) || str_starts_with($key, 'X-')) {
			// 3.3.11 Text ESCAPED-CHAR
			$value = strtr($value, self::TEXT_ESCAPES);
		}

		if ($key === 'ATTENDEE' || $key === 'ORGANIZER') {
			$value = array_merge(is_array($middle) ? $middle : ['middle' => $middle], ['VALUE' => $value]);
		}

		return [$key, $middle, $value, $raw, $row];
	}

	private static function createDate(string $value, ?DateTimeZone $timezone): ?DateTime {
		try {
			// Fast path for UTC values like 20240105T100000Z: resolving the "Z" abbreviation
			// is slow, so the date is read in UTC and the shared "Z" timezone is attached.
			if (strlen($value) === 16 && $value[15] === 'Z' && $value[8] === 'T') {
				static $utc, $zulu;
				$utc ??= new DateTimeZone('UTC');
				$zulu ??= (new DateTime('20000101T000000Z'))->getTimezone();
				return (new DateTime(substr($value, 0, 15), $utc))->setTimezone($zulu);
			}
			return new DateTime($value, $timezone);
		} catch (Exception) {
			return null;
		}
	}
}
