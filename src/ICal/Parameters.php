<?php
declare(strict_types=1);

namespace om\ICal;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * Property parameters (RFC 5545, section 3.2). Names are case-insensitive,
 * a parameter may have several values (e.g. MEMBER="mailto:a","mailto:b").
 *
 * @implements IteratorAggregate<string, list<string>>
 */
final class Parameters implements IteratorAggregate, Countable {
	/**
	 * @param array<string, list<string>> $values
	 */
	private function __construct(private array $values = []) {
	}

	/**
	 * @param array<string, string|list<string>> $parameters
	 */
	public static function from(array $parameters): self {
		$values = [];
		foreach ($parameters as $name => $value) {
			$values[strtoupper(self::name($name))] = array_map('strval', (array) $value);
		}
		return new self($values);
	}

	/**
	 * Parse the parameter part of a content line, e.g. 'CN="Doe, John";ROLE=CHAIR'.
	 * Parts without "=" are ignored.
	 */
	public static function parse(string $raw): self {
		$values = [];
		$length = strlen($raw);
		$name = '';
		$value = '';
		$list = [];
		$inName = true;
		$quoted = false;
		for ($i = 0; $i <= $length; $i++) {
			$char = $i < $length ? $raw[$i] : ';';
			if ($quoted) {
				if ($char === '"') {
					$quoted = false;
				} else {
					$value .= $char;
				}
				continue;
			}
			if ($char === ';') {
				if (!$inName && trim($name) !== '') {
					$list[] = $value;
					$values[strtoupper(trim($name))] = array_map(self::decode(...), $list);
				}
				[$name, $value, $list, $inName] = ['', '', [], true];
			} elseif ($inName) {
				if ($char === '=') {
					$inName = false;
				} else {
					$name .= $char;
				}
			} elseif ($char === '"') {
				$quoted = true;
			} elseif ($char === ',') {
				$list[] = $value;
				$value = '';
			} else {
				$value .= $char;
			}
		}
		return new self($values);
	}

	/**
	 * Value of a parameter; several values are joined by a comma.
	 */
	public function get(string $name): ?string {
		$values = $this->values[strtoupper($name)] ?? null;
		return $values === null ? null : implode(',', $values);
	}

	/**
	 * @return list<string>
	 */
	public function values(string $name): array {
		return $this->values[strtoupper($name)] ?? [];
	}

	public function has(string $name): bool {
		return isset($this->values[strtoupper($name)]);
	}

	/**
	 * @param string|list<string>|null $value null removes the parameter
	 */
	public function with(string $name, string|array|null $value): self {
		$values = $this->values;
		if ($value === null) {
			unset($values[strtoupper($name)]);
		} else {
			$values[strtoupper(self::name($name))] = (array) $value;
		}
		return new self($values);
	}

	/**
	 * @return array<string, list<string>>
	 */
	public function all(): array {
		return $this->values;
	}

	public function count(): int {
		return count($this->values);
	}

	public function getIterator(): Traversable {
		yield from $this->values;
	}

	/**
	 * Serialized form without the leading semicolon. Values with ":", ";" or "," are quoted,
	 * DQUOTE, newlines and "^" are encoded as RFC 6868 requires, other control characters are removed.
	 */
	public function __toString(): string {
		$parts = [];
		foreach ($this->values as $name => $values) {
			$parts[] = $name . '=' . implode(',', array_map(self::encode(...), $values));
		}
		return implode(';', $parts);
	}

	private static function encode(string $value): string {
		$value = strtr($value, ['^' => '^^', "\r\n" => '^n', "\n" => '^n', "\r" => '^n', '"' => "^'"]);
		$value = (string) preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/', '', $value);
		return strpbrk($value, ':;,') === false ? $value : '"' . $value . '"';
	}

	/**
	 * RFC 6868: ^n is a newline, ^' a DQUOTE and ^^ a caret.
	 */
	private static function decode(string $value): string {
		return str_contains($value, '^') ? strtr($value, ['^^' => '^', '^n' => "\n", '^N' => "\n", "^'" => '"']) : $value;
	}

	private static function name(string $name): string {
		if (!preg_match('/^[A-Za-z0-9-]+$/D', $name)) {
			throw new InvalidArgumentException("Invalid parameter name: $name");
		}
		return $name;
	}
}
