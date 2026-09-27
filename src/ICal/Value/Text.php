<?php
declare(strict_types=1);

namespace om\ICal\Value;

/**
 * TEXT values (RFC 5545, section 3.3.11): escaping of backslash, semicolon, comma and newline.
 */
final class Text {
	private const array UNESCAPE = ['\\\\' => '\\', '\\N' => "\n", '\\n' => "\n", '\;' => ';', '\\,' => ','];

	public static function unescape(string $value): string {
		return strtr($value, self::UNESCAPE);
	}

	public static function escape(string $value): string {
		return strtr($value, ['\\' => '\\\\', "\r\n" => '\n', "\n" => '\n', ';' => '\;', ',' => '\,']);
	}

	/**
	 * Split a list of TEXT values on commas that are not escaped, and unescape them.
	 * Empty items are dropped.
	 *
	 * @return list<string>
	 */
	public static function split(string $value): array {
		$result = [];
		foreach (self::splitEscaped($value) as $item) {
			$item = trim(self::unescape($item));
			if ($item !== '') {
				$result[] = $item;
			}
		}
		return $result;
	}

	/**
	 * Split on a separator that is not escaped by a backslash; the parts stay escaped.
	 * "a\\,b" (an escaped backslash before the comma) gives "a\\" and "b".
	 *
	 * @return list<string>
	 */
	public static function splitEscaped(string $value, string $separator = ','): array {
		$parts = [];
		$current = '';
		$length = strlen($value);
		for ($i = 0; $i < $length; $i++) {
			$char = $value[$i];
			if ($char === '\\' && $i + 1 < $length) {
				$current .= $char . $value[++$i];
			} elseif ($char === $separator) {
				$parts[] = $current;
				$current = '';
			} else {
				$current .= $char;
			}
		}
		$parts[] = $current;
		return $parts;
	}
}
