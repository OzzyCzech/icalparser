<?php
declare(strict_types=1);

namespace om\ICal\Parser;

use DateTimeZone;
use Generator;
use om\ICal\Calendar;
use om\ICal\Component;
use om\ICal\Exception\TimezoneResolutionException;
use om\ICal\Item;
use om\ICal\Property;
use om\ICal\Timezone\CompositeTimezoneResolver;
use om\ICal\Timezone\TimezoneResolver;
use om\ICal\Value\ValueParser;
use om\RRule\RecurrenceLimits;

/**
 * Configurable iCalendar parser, see ICal::parser().
 *
 *     $result = ICal::parser()->mode(ParserMode::Strict)->limits(new ParseLimits(maxFileSize: 1_000_000))->parse($ics);
 *
 * The configuration is immutable: every setter returns a new parser.
 */
final readonly class Parser {
	private TimezoneResolver $timezoneResolver;

	public function __construct(
		private ParserMode $mode = ParserMode::Permissive,
		private ParseLimits $limits = new ParseLimits(),
		private RecurrenceLimits $recurrenceLimits = new RecurrenceLimits(),
		?TimezoneResolver $timezoneResolver = null,
		private ?DateTimeZone $floatingTimezone = null,
		private bool $checkValues = true,
	) {
		$this->timezoneResolver = $timezoneResolver ?? CompositeTimezoneResolver::default();
	}

	public function mode(ParserMode $mode): self {
		return new self($mode, $this->limits, $this->recurrenceLimits, $this->timezoneResolver, $this->floatingTimezone, $this->checkValues);
	}

	public function limits(ParseLimits $limits): self {
		return new self($this->mode, $limits, $this->recurrenceLimits, $this->timezoneResolver, $this->floatingTimezone, $this->checkValues);
	}

	public function recurrenceLimits(RecurrenceLimits $limits): self {
		return new self($this->mode, $this->limits, $limits, $this->timezoneResolver, $this->floatingTimezone, $this->checkValues);
	}

	public function timezoneResolver(TimezoneResolver $resolver): self {
		return new self($this->mode, $this->limits, $this->recurrenceLimits, $resolver, $this->floatingTimezone, $this->checkValues);
	}

	/**
	 * Timezone of dates and floating times (instead of X-WR-TIMEZONE).
	 */
	public function floatingTimezone(?DateTimeZone $timezone): self {
		return new self($this->mode, $this->limits, $this->recurrenceLimits, $this->timezoneResolver, $timezone, $this->checkValues);
	}

	/**
	 * Convert every value of a known type during parsing and report invalid and nonstandard
	 * values as warnings (default). Turn it off to parse large files faster; values are then
	 * checked only when they are read (and by the Validator).
	 */
	public function checkValues(bool $check = true): self {
		return new self($this->mode, $this->limits, $this->recurrenceLimits, $this->timezoneResolver, $this->floatingTimezone, $check);
	}

	public function parse(string $content): ParseResult {
		return $this->parseLines(fn(callable $warn): Generator => LineReader::fromString($content, $this->limits->maxLineLength, $this->limits->maxFileSize, $warn));
	}

	/**
	 * @throws \RuntimeException when the file cannot be read
	 */
	public function parseFile(string $file): ParseResult {
		return $this->parseLines(fn(callable $warn): Generator => LineReader::fromFile($file, $this->limits->maxLineLength, $this->limits->maxFileSize, $warn));
	}

	/**
	 * @param resource $stream
	 */
	public function parseStream($stream): ParseResult {
		return $this->parseLines(fn(callable $warn): Generator => LineReader::fromStream($stream, $this->limits->maxLineLength, $this->limits->maxFileSize, $warn));
	}

	/**
	 * Read events, tasks, journal entries and free/busy components one by one with constant
	 * memory. VTIMEZONE components and calendar properties seen so far are used for their values;
	 * overrides (RECURRENCE-ID) are returned as separate items.
	 *
	 * @param string|resource $input a file name or a stream
	 * @param ?callable(ParseWarning): void $onWarning
	 * @return Generator<int, Item>
	 */
	public function stream($input, ?callable $onWarning = null): Generator {
		$builder = new TreeBuilder($this->mode, $this->limits);
		$warn = static fn(string $code, string $message, ?int $line = null) => $builder->recover($code, $message, $line);
		$lines = is_string($input)
			? LineReader::fromFile($input, $this->limits->maxLineLength, $this->limits->maxFileSize, $warn)
			: LineReader::fromStream($input, $this->limits->maxLineLength, $this->limits->maxFileSize, $warn);

		$reported = 0;
		$shell = null;       // properties and VTIMEZONE components of the current calendar
		$calendar = null;
		foreach ($builder->build(Tokenizer::tokenize($lines, $builder->invalidLine(...))) as $kind => $component) {
			foreach (array_slice($builder->warnings(), $reported) as $warning) {
				$onWarning !== null && $onWarning($warning);
				$reported++;
			}
			if ($kind === 'calendar') {
				[$shell, $calendar] = [null, null];
				continue;
			}
			if ($component->name === 'VTIMEZONE') {
				$shell = ($shell ?? new Component('VCALENDAR', $builder->calendarProperties()))->withComponent($component);
				$calendar = null;
				continue;
			}
			$properties = $builder->calendarProperties();
			if ($shell === null || $shell->properties !== $properties) {
				$shell = new Component('VCALENDAR', $properties, $shell->components ?? []);
				$calendar = null;
			}
			$calendar ??= $this->calendar($shell);
			$item = $calendar->itemOf($component);
			if ($item !== null) {
				yield $item;
			}
		}
		foreach (array_slice($builder->warnings(), $reported) as $warning) {
			$onWarning !== null && $onWarning($warning);
		}
	}

	/**
	 * @param callable(callable(string, string, int): void): iterable<int, string> $lines
	 */
	private function parseLines(callable $lines): ParseResult {
		$builder = new TreeBuilder($this->mode, $this->limits);
		$warn = static fn(string $code, string $message, ?int $line = null) => $builder->recover($code, $message, $line);

		$calendars = [];
		$children = [];
		foreach ($builder->build(Tokenizer::tokenize($lines($warn), $builder->invalidLine(...))) as $kind => $component) {
			if ($kind === 'component') {
				$children[] = $component;
				continue;
			}
			$calendar = $this->calendar($component->withComponents($children));
			$children = [];
			$this->check($calendar, $builder);
			$calendars[] = $calendar;
		}
		return new ParseResult($calendars, $builder->warnings());
	}

	private function calendar(Component $component): Calendar {
		return new Calendar($component, $this->timezoneResolver, $this->floatingTimezone, $this->recurrenceLimits, $this->mode === ParserMode::Strict);
	}

	/**
	 * Unresolved TZIDs are warnings (errors in strict mode). Values of known types are converted:
	 * invalid and nonstandard values are warnings, strict mode throws InvalidValueException.
	 */
	private function check(Calendar $calendar, TreeBuilder $builder): void {
		$values = $calendar->values();
		$strict = $this->mode === ParserMode::Strict;
		$reported = [];
		foreach (self::properties($calendar->component) as $property) {
			$tzid = $property->parameter('TZID');
			if ($tzid !== null && !isset($reported[$tzid]) && $values->timezone($tzid) === null) {
				$reported[$tzid] = true;
				if ($strict) {
					throw TimezoneResolutionException::create('timezone.unresolved', "Unknown timezone \"$tzid\"", $property->line, $property->name, $property->value);
				}
				$builder->warn('timezone.unresolved', "Unknown timezone \"$tzid\", its times are floating.", $property->line, $property->name);
			}
			if (($strict || $this->checkValues) && (isset(ValueParser::TYPES[$property->name]) || $property->parameter('VALUE') !== null)) {
				// throws InvalidValueException in strict mode
				foreach ($values->diagnose($property) as [$code, $message]) {
					$builder->warn($code, $message, $property->line, $property->name);
				}
			}
		}
	}

	/**
	 * @return Generator<int, Property>
	 */
	private static function properties(Component $component): Generator {
		yield from $component->properties;
		foreach ($component->components as $child) {
			yield from self::properties($child);
		}
	}
}
