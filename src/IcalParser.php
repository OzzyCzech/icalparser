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
	private const array DATE_PROPERTIES = ['DTSTAMP', 'LAST-MODIFIED', 'CREATED', 'DTSTART', 'DTEND', 'DUE', 'COMPLETED'];

	/** Properties of the TEXT value type (RFC 5545, section 3.3.11) that are unescaped. */
	private const array TEXT_PROPERTIES = [
		'CALSCALE', 'METHOD', 'PRODID', 'VERSION', 'CATEGORIES', 'CLASS', 'COMMENT', 'DESCRIPTION',
		'LOCATION', 'RESOURCES', 'STATUS', 'SUMMARY', 'TRANSP', 'TZID', 'TZNAME', 'CONTACT',
		'RELATED-TO', 'UID', 'ACTION', 'REQUEST-STATUS', 'URL',
	];

	private const array TEXT_ESCAPES = ['\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\\;' => ';', '\\,' => ','];

	/** Timezone of floating dates: the last X-WR-TIMEZONE or TZID property seen. */
	public ?DateTimeZone $timezone = null;
	/** @var array<string, mixed>|null */
	public ?array $data = null;
	/** @var array<string, int> */
	protected array $counters = [];

	private readonly ParserOptions $options;
	/** @var array<string, string> */
	private array $windowsTimezones;
	/** @var array<string, DateTimeZone|false> */
	private array $timezoneCache = [];

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
		$this->windowsTimezones = $this->options->windowsTimezones ?? [];
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
		$content = @file_get_contents($file);
		if ($content === false) {
			throw new RuntimeException(sprintf('Cannot read iCalendar file "%s".', $file));
		}

		return $this->parseString($content, $callback);
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

		// Normalize line breaks and unfold lines (RFC 5545, section 3.1)
		$string = str_replace(["\r\n", "\r"], "\n", $string);
		$string = str_replace(["\n ", "\n\t"], '', $string);
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
					if ($component === 'VEVENT' && $callback === null && isset($this->counters['VEVENT'])) {
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
		return $this->recurrences($event, []);
	}

	public function isMultipleKey(string $key): ?string {
		return (['ATTACH' => 'ATTACHMENTS', 'EXDATE' => 'EXDATES', 'RDATE' => 'RDATES', 'ATTENDEE' => 'ATTENDEES'])[$key] ?? null;
	}

	public function isMultipleKeyWithCommaSeparation(string $key): ?string {
		return (['X-CATEGORIES' => 'X-CATEGORIES', 'CATEGORIES' => 'CATEGORIES'])[$key] ?? null;
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
			$meta = $this->meta['VEVENT'][$counter] ?? [];
			$start = $event['DTSTART'] ?? null;
			if (!$start instanceof DateTimeInterface) {
				$events->append($event);
				continue;
			}

			$duration = $this->duration($event, $meta);
			if (!array_key_exists('RECURRENCES', $event)) {
				if (!array_key_exists('DTEND', $event) && $duration !== null) {
					$end = DateTime::createFromInterface($start);
					$event['DTEND'] = $end->add($duration);
				}
				$events->append($event);
				continue;
			}

			$event['RECURRING'] = true;
			$duration ??= new DateInterval('PT0S');
			foreach ($event['RECURRENCES'] as $index => $date) {
				if (!$date instanceof DateTimeInterface) {
					continue;
				}
				$instance = $event;
				if ($index !== 0) {
					unset($instance['RECURRENCES']);
				}
				$instance['DTSTART'] = DateTime::createFromInterface($date);
				$end = DateTime::createFromInterface($date);
				$instance['DTEND'] = $end->add($duration);
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
		$component = &$this->data[$section][$counter];

		// Multiple entries are collected in an array under a separate key,
		// the original key keeps the last value.
		if ($newKey = $this->isMultipleKey($key)) {
			$component[$newKey][] = $value;
		}

		if ($this->isMultipleKeyWithCommaSeparation($key)) {
			// split on commas not preceded by backslash, then unescape
			foreach (preg_split('/(?<!\\\\),/', $raw) ?: [] as $item) {
				$component[$key][] = trim(strtr($item, self::TEXT_ESCAPES));
			}
			return;
		}

		if ($key === 'ORGANIZER') {
			foreach (is_array($middle) ? $middle : [] as $midKey => $midVal) {
				$component[$key . '-' . $midKey] = $midVal;
			}
		}
		if ($key === 'ATTENDEE' || $key === 'ORGANIZER') {
			$value = $value['VALUE']; // backwards compatibility (leaves ATTENDEE entry as it was)
		}
		$component[$key] = $value;

		$this->storeMeta($section, $counter, $key, $middle, $raw);
	}

	/**
	 * Remember parser details that the public data cannot express.
	 */
	private function storeMeta(string $section, int $counter, string $key, mixed $middle, string $raw): void {
		$params = is_array($middle) ? $middle : [];
		$dateOnly = ($params['VALUE'] ?? null) === 'DATE';
		switch ($key) {
			case 'DTSTART':
				$this->meta[$section][$counter]['dateOnly'] = $dateOnly || preg_match('/^\d{8}$/D', trim($raw)) === 1;
				break;
			case 'RRULE':
				$this->meta[$section][$counter]['rrule'] = $raw;
				break;
			case 'EXDATE':
				foreach (explode(',', $raw) as $item) {
					if ($dateOnly || preg_match('/^\d{8}$/D', trim($item))) {
						$this->meta[$section][$counter]['exdateDays'][] = substr(trim($item), 0, 8);
					}
				}
				break;
			case 'RECURRENCE-ID':
				$timezone = $params['TZID'] ?? null;
				$this->meta[$section][$counter]['recurrenceIdTimezone'] = $timezone instanceof DateTimeZone ? $timezone : null;
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
		$nameLength = strcspn($row, ';:');
		if ($nameLength === 0 || $nameLength === strlen($row) || strspn($row, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_', 0, $nameLength) !== $nameLength) {
			return null;
		}
		$key = strtoupper(substr($row, 0, $nameLength));

		$middle = '';
		$valueStart = $nameLength + 1;
		if ($row[$nameLength] === ';') {
			$colon = self::valueSeparator($row, $nameLength);
			if ($colon === null) {
				return null;
			}
			$middle = substr($row, $nameLength + 1, $colon - $nameLength - 1);
			$valueStart = $colon + 1;
		}
		$raw = (string) substr($row, $valueStart);
		$value = $raw;
		$timezone = null;

		if ($key === 'X-WR-TIMEZONE' || $key === 'TZID') {
			$resolved = $this->resolveTimezone($value);
			if ($resolved !== null) {
				$value = $resolved->getName();
				$this->timezone = $resolved;
			}
		}

		if ($middle !== '' && ($params = self::parseParameters($middle)) !== []) {
			$middle = [];
			foreach ($params as $name => $paramValue) {
				if ($name === 'TZID') {
					$resolved = $this->resolveTimezone($paramValue);
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

		if (in_array($key, self::DATE_PROPERTIES, true)) {
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
		} elseif (in_array($key, self::TEXT_PROPERTIES, true) || str_starts_with($key, 'X-')) {
			// 3.3.11 Text ESCAPED-CHAR
			$value = strtr($value, self::TEXT_ESCAPES);
		}

		if ($key === 'ATTENDEE' || $key === 'ORGANIZER') {
			$value = array_merge(is_array($middle) ? $middle : ['middle' => $middle], ['VALUE' => $value]);
		}

		return [$key, $middle, $value, $raw, $row];
	}

	/**
	 * Position of the colon that separates parameters from the value; colons inside quoted
	 * parameter values (e.g. ALTREP="http://...") are skipped.
	 */
	private static function valueSeparator(string $row, int $offset): ?int {
		$colon = strpos($row, ':', $offset);
		$quote = strpos($row, '"', $offset);
		if ($colon === false) {
			return null;
		}
		if ($quote === false || $quote > $colon) {
			return $colon;
		}
		$length = strlen($row);
		$quoted = false;
		for ($i = $quote; $i < $length; $i++) {
			if ($row[$i] === '"') {
				$quoted = !$quoted;
			} elseif ($row[$i] === ':' && !$quoted) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Parse "NAME=value;NAME2="quoted;value"" into [NAME => value]; parameter names are
	 * case-insensitive, quotes around values are removed.
	 *
	 * @return array<string, string>
	 */
	private static function parseParameters(string $middle): array {
		preg_match_all('/([^=;]+)=((?:"[^"]*"|[^";])*)/', $middle, $matches, PREG_SET_ORDER);
		$params = [];
		foreach ($matches as [, $name, $value]) {
			if (str_contains($value, '"')) {
				$value = str_replace('"', '', $value);
			}
			$params[strtoupper(trim($name))] = $value;
		}
		return $params;
	}

	private static function createDate(string $value, ?DateTimeZone $timezone): ?DateTime {
		try {
			return new DateTime($value, $timezone);
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * Extract and resolve timezone from a TZID or X-WR-TIMEZONE value.
	 * Handles Windows names, prefixed values (e.g. /mozilla.org/.../Europe/Paris) and
	 * multi-segment IANA zones (e.g. America/Argentina/Buenos_Aires).
	 */
	private function resolveTimezone(string $value): ?DateTimeZone {
		$cached = $this->timezoneCache[$value] ??= $this->findTimezone($value) ?? false;
		return $cached ?: null;
	}

	private function findTimezone(string $value): ?DateTimeZone {
		$value = trim($value, " \t'\"");
		$parts = array_values(array_filter(preg_split('#[/\\\\]#', $value) ?: []));
		$count = count($parts);
		if ($count < 2) {
			// no slashes - try as-is via windowsTimezones lookup
			return self::createTimezone($this->windowsTimezones[$value] ?? $value);
		}

		// try building timezone paths from the end, shortest first
		// e.g. for "/mozilla.org/20070129_1/Europe/Paris": try "Europe/Paris" ✓
		// e.g. for "America/Argentina/Buenos_Aires": "Argentina/Buenos_Aires" ✗, "America/Argentina/Buenos_Aires" ✓
		for ($length = 2; $length <= $count; $length++) {
			$candidate = implode('/', array_slice($parts, $count - $length));
			if ($timezone = self::createTimezone($this->windowsTimezones[$candidate] ?? $candidate)) {
				return $timezone;
			}
		}
		return null;
	}

	private static function createTimezone(string $name): ?DateTimeZone {
		try {
			return new DateTimeZone($name);
		} catch (Exception) {
			return null;
		}
	}
}
