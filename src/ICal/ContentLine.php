<?php
declare(strict_types=1);

namespace om\ICal;

use Stringable;

/**
 * One unfolded content line (RFC 5545, section 3.1):
 *
 *     name *(";" param) ":" value
 *
 * BEGIN and END lines are content lines too, their value is the component name.
 *
 *     $line = ContentLine::parse('ATTENDEE;CN="Doe, John";ROLE=REQ-PARTICIPANT:mailto:john@example.com');
 *     $line->name;                  // "ATTENDEE"
 *     $line->parameters->get('CN'); // "Doe, John"
 *     $line->value;                 // "mailto:john@example.com"
 */
final class ContentLine implements Stringable {
	private const string NAME_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

	/** Parameters are parsed on first access. */
	public Parameters $parameters {
		get => $this->parsed ??= Parameters::parse($this->rawParameters);
	}

	private ?Parameters $parsed = null;

	/**
	 * @param string $name upper-cased property name
	 * @param string $rawParameters parameters without the leading semicolon
	 * @param string $value raw (still escaped) value
	 * @param int $line number of the first physical line, 0 when unknown
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $rawParameters,
		public readonly string $value,
		public readonly int $line = 0,
	) {
	}

	/**
	 * @return ?self null when the line is not a content line
	 */
	public static function parse(string $line, int $number = 0): ?self {
		$parts = self::split($line);
		return $parts === null ? null : new self($parts[0], $parts[1], $parts[2], $number);
	}

	public function isBegin(): bool {
		return $this->name === 'BEGIN' && $this->rawParameters === '';
	}

	public function isEnd(): bool {
		return $this->name === 'END' && $this->rawParameters === '';
	}

	/**
	 * Component name of a BEGIN or END line.
	 */
	public function componentName(): string {
		return strtoupper(trim($this->value));
	}

	public function __toString(): string {
		return $this->name . ($this->rawParameters === '' ? '' : ';' . $this->rawParameters) . ':' . $this->value;
	}

	/**
	 * Split a line into its upper-cased name, raw parameters and value without creating objects.
	 *
	 * @internal
	 * @return array{string, string, string}|null
	 */
	public static function split(string $line): ?array {
		$nameLength = strcspn($line, ';:');
		if ($nameLength === 0 || $nameLength === strlen($line) || strspn($line, self::NAME_CHARACTERS, 0, $nameLength) !== $nameLength) {
			return null;
		}

		$parameters = '';
		$valueStart = $nameLength + 1;
		if ($line[$nameLength] === ';') {
			$colon = self::valueSeparator($line, $nameLength);
			if ($colon === null) {
				return null;
			}
			$parameters = substr($line, $nameLength + 1, $colon - $nameLength - 1);
			$valueStart = $colon + 1;
		}
		return [strtoupper(substr($line, 0, $nameLength)), $parameters, substr($line, $valueStart)];
	}

	/**
	 * Parse parameters into [NAME => value] with several values joined by a comma.
	 *
	 * @internal used by the array based IcalParser
	 * @return array<string, string>
	 */
	public static function parameters(string $parameters): array {
		$result = [];
		foreach (Parameters::parse($parameters) as $name => $values) {
			$result[$name] = implode(',', $values);
		}
		return $result;
	}

	/**
	 * Position of the colon that separates parameters from the value; colons inside quoted
	 * parameter values (e.g. ALTREP="http://...") are skipped.
	 */
	private static function valueSeparator(string $line, int $offset): ?int {
		$colon = strpos($line, ':', $offset);
		if ($colon === false) {
			return null;
		}
		$quote = strpos($line, '"', $offset);
		if ($quote === false || $quote > $colon) {
			return $colon;
		}
		$length = strlen($line);
		$quoted = false;
		for ($i = $quote; $i < $length; $i++) {
			if ($line[$i] === '"') {
				$quoted = !$quoted;
			} elseif ($line[$i] === ':' && !$quoted) {
				return $i;
			}
		}
		return null;
	}
}
