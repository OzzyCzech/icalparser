<?php
declare(strict_types=1);

namespace om\ICal\Parser;

use Generator;
use om\ICal\Component;
use om\ICal\ContentLine;
use om\ICal\Exception\ResourceLimitException;
use om\ICal\Exception\SyntaxException;
use om\ICal\Property;

/**
 * Builds components from content lines.
 *
 * Yields "component" => Component for every component inside a VCALENDAR as soon as it
 * ends, and "calendar" => Component (the VCALENDAR with its properties, without child
 * components) at the end of every calendar. Structural problems throw SyntaxException in
 * strict mode; in permissive mode they are repaired and reported as warnings.
 *
 * @internal
 */
final class TreeBuilder {
	/** Components that can only be direct children of VCALENDAR. */
	private const array CALENDAR_CHILDREN = ['VEVENT' => true, 'VTODO' => true, 'VJOURNAL' => true, 'VFREEBUSY' => true, 'VTIMEZONE' => true];

	/** @var list<ParseWarning> */
	private array $warnings = [];

	/** @var list<array{name: string, properties: list<Property>, components: list<Component>, line: int}> */
	private array $stack = [];
	private int $componentCount = 0;
	private int $calendars = 0;
	private int $propertyCount = 0;

	public function __construct(
		private readonly ParserMode $mode = ParserMode::Permissive,
		private readonly ParseLimits $limits = new ParseLimits(),
	) {
	}

	/**
	 * @param iterable<ContentLine|array{string, string, string, int}> $lines content lines, or rows of Tokenizer::rows()
	 * @return Generator<string, Component>
	 */
	public function build(iterable $lines): Generator {
		foreach ($lines as $line) {
			[$name, $parameters, $value, $number] = $line instanceof ContentLine ? [$line->name, $line->rawParameters, $line->value, $line->line] : $line;

			if (($name === 'BEGIN' || $name === 'END') && $parameters === '') {
				$component = strtoupper(trim($value));
				if ($name === 'BEGIN') {
					// a new calendar ends a calendar left open (concatenated or truncated feeds)
					while ($component === 'VCALENDAR' && $this->stack !== []) {
						$this->problem('syntax.missing-end', "END:{$this->current()} is missing before BEGIN:VCALENDAR, the component was closed.", $number);
						yield from $this->close();
					}
					if ($this->stack === [] && $component !== 'VCALENDAR') {
						$this->problem('syntax.missing-calendar', "$component outside of VCALENDAR, an implicit VCALENDAR was added.", $number);
						$this->open('VCALENDAR', $number);
					}
					// a new event (task, ...) ends components left open, e.g. in truncated feeds
					while (isset(self::CALENDAR_CHILDREN[$component]) && count($this->stack) > 1) {
						$this->problem('syntax.missing-end', "END:{$this->current()} is missing before BEGIN:$component, the component was closed.", $number);
						yield from $this->close();
					}
					$this->open($component, $number);
					continue;
				}

				$depth = $this->depthOf($component);
				if ($depth === null) {
					$this->problem('syntax.unexpected-end', "END:$component without BEGIN:$component was ignored.", $number);
					continue;
				}
				while (count($this->stack) - 1 > $depth) {
					$this->problem('syntax.missing-end', "END:{$this->current()} is missing, the component was closed.", $number);
					yield from $this->close();
				}
				yield from $this->close();
				continue;
			}

			if ($this->stack === []) {
				$this->problem('syntax.outside-calendar', "$name outside of VCALENDAR was ignored.", $number, $name);
				continue;
			}
			if (++$this->propertyCount > $this->limits->maxProperties) {
				throw ResourceLimitException::create('limit.properties', "More than {$this->limits->maxProperties} properties.", $number, $name);
			}
			$this->stack[array_key_last($this->stack)]['properties'][] = new Property($name, $parameters, $value, $number);
		}

		while ($this->stack !== []) {
			$open = $this->current();
			$this->problem('syntax.missing-end', "END:$open is missing at the end of the input, the component was closed.");
			yield from $this->close();
		}
		if ($this->calendars === 0) {
			$this->problem('syntax.no-calendar', 'The input contains no VCALENDAR component.');
		}
	}

	/**
	 * Report a line that is not a content line.
	 */
	public function invalidLine(string $line, int $number): void {
		$this->problem('syntax.invalid-line', 'Invalid content line was ignored: ' . substr($line, 0, 80), $number);
	}

	/**
	 * Report a problem found by another layer: an exception in strict mode, a warning otherwise.
	 */
	public function recover(string $code, string $message, ?int $line = null, ?string $property = null): void {
		$this->problem($code, $message, $line, $property);
	}

	/**
	 * Report a repaired problem found by another layer.
	 */
	public function warn(string $code, string $message, ?int $line = null, ?string $property = null): void {
		$this->warnings[] = new ParseWarning($code, $message, $line, $property);
	}

	/**
	 * Properties of the calendar being read.
	 *
	 * @return list<Property>
	 */
	public function calendarProperties(): array {
		return $this->stack[0]['properties'] ?? [];
	}

	/**
	 * @return list<ParseWarning>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	private function open(string $name, int $line): void {
		if (count($this->stack) >= $this->limits->maxNestingDepth) {
			throw ResourceLimitException::create('limit.nesting', "Components are nested deeper than {$this->limits->maxNestingDepth} levels.", $line);
		}
		if (++$this->componentCount > $this->limits->maxComponents) {
			throw ResourceLimitException::create('limit.components', "More than {$this->limits->maxComponents} components.", $line);
		}
		$this->stack[] = ['name' => $name, 'properties' => [], 'components' => [], 'line' => $line];
	}

	/**
	 * @return Generator<string, Component>
	 */
	private function close(): Generator {
		$frame = array_pop($this->stack);
		if ($frame === null) {
			return;
		}
		if ($this->stack === []) {
			// the calendar itself; its components were yielded already
			$this->calendars++;
			yield 'calendar' => new Component($frame['name'], $frame['properties']);
			return;
		}
		$component = new Component($frame['name'], $frame['properties'], $frame['components']);
		if (count($this->stack) === 1) {
			yield 'component' => $component;
		} else {
			$this->stack[array_key_last($this->stack)]['components'][] = $component;
		}
	}

	private function current(): string {
		$frame = end($this->stack);
		return $frame === false ? '' : $frame['name'];
	}

	private function depthOf(string $name): ?int {
		for ($depth = count($this->stack) - 1; $depth >= 0; $depth--) {
			if ($this->stack[$depth]['name'] === $name) {
				return $depth;
			}
		}
		return null;
	}

	private function problem(string $code, string $message, ?int $line = null, ?string $property = null): void {
		if ($this->mode === ParserMode::Strict) {
			throw SyntaxException::create($code, $message, $line, $property);
		}
		$this->warn($code, $message, $line, $property);
	}
}
