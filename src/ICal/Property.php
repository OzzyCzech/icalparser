<?php
declare(strict_types=1);

namespace om\ICal;

use InvalidArgumentException;
use Stringable;

/**
 * An immutable property: name, parameters and the raw (still escaped) value.
 *
 * Unknown and X- properties are kept like standard ones. Typed values are provided
 * by om\ICal\Value\ValueParser and by the typed components (Event, Todo, ...).
 */
final class Property implements Stringable {
	/** Parameters are parsed on first access. */
	public Parameters $parameters {
		get => $this->parsed ??= Parameters::parse($this->rawParameters);
	}

	private ?Parameters $parsed;
	private readonly string $rawParameters;

	/**
	 * @param string|Parameters $parameters raw parameters (without the leading semicolon) or parsed ones
	 * @param string $value raw value as in the content line
	 * @param int $line number of the content line, 0 for created properties
	 */
	public function __construct(
		public readonly string $name,
		string|Parameters $parameters,
		public readonly string $value,
		public readonly int $line = 0,
	) {
		$this->rawParameters = is_string($parameters) ? $parameters : (string) $parameters;
		$this->parsed = $parameters instanceof Parameters ? $parameters : null;
	}

	/**
	 * @param array<string, string|list<string>>|Parameters $parameters
	 */
	/**
	 * @param string $value the raw value; newlines are written as "\n" (use Text::escape() for TEXT)
	 * @param array<string, string|list<string>>|Parameters $parameters
	 * @throws InvalidArgumentException for an invalid property name
	 */
	public static function create(string $name, string $value, array|Parameters $parameters = []): self {
		if (!preg_match('/^[A-Za-z0-9-]+$/D', $name)) {
			throw new InvalidArgumentException("Invalid property name: $name");
		}
		return new self(strtoupper($name), is_array($parameters) ? Parameters::from($parameters) : $parameters, $value);
	}

	public static function fromContentLine(ContentLine $line): self {
		return new self($line->name, $line->rawParameters, $line->value, $line->line);
	}

	public function parameter(string $name): ?string {
		if ($this->parsed === null && ($this->rawParameters === '' || stripos($this->rawParameters, $name) === false)) {
			return null; // no need to parse the parameters
		}
		return $this->parameters->get($name);
	}

	public function withValue(string $value): self {
		return new self($this->name, $this->parameters, $value, $this->line);
	}

	/**
	 * @param string|list<string>|null $value null removes the parameter
	 */
	public function withParameter(string $name, string|array|null $value): self {
		return new self($this->name, $this->parameters->with($name, $value), $this->value, $this->line);
	}

	/**
	 * The unfolded content line, e.g. "DTSTART;TZID=Europe/Prague:20261010T100000".
	 */
	/**
	 * The unfolded content line; newlines of the value are written as "\n", so they cannot start
	 * another content line.
	 */
	public function __toString(): string {
		$value = str_contains($this->value, "\n") || str_contains($this->value, "\r")
			? str_replace(["\r\n", "\n", "\r"], '\\n', $this->value)
			: $this->value;
		return $this->name . ($this->rawParameters === '' ? '' : ';' . $this->rawParameters) . ':' . $value;
	}
}
