<?php
declare(strict_types=1);

namespace om\Parser;

/**
 * Splitting of unfolded content lines (RFC 5545, section 3.1):
 *
 *     name *(";" param) ":" value
 *
 * @internal
 */
final class ContentLine {
	private const string NAME_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

	/**
	 * Split a line into its upper-cased name, raw parameters and value.
	 *
	 * @return array{string, string, string}|null null when the line is not a property
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
	 * Parse 'NAME=value;NAME2="quoted;value"' into [NAME => value]. Parameter names are
	 * case-insensitive, quotes around values are removed and parts without "=" are skipped.
	 *
	 * @return array<string, string>
	 */
	public static function parameters(string $parameters): array {
		preg_match_all('/([^=;]+)=((?:"[^"]*"|[^";])*)/', $parameters, $matches, PREG_SET_ORDER);
		$result = [];
		foreach ($matches as [, $name, $value]) {
			if (str_contains($value, '"')) {
				$value = str_replace('"', '', $value);
			}
			$result[strtoupper(trim($name))] = $value;
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
