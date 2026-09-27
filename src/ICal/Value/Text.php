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
	 *
	 * @return list<string>
	 */
	public static function split(string $value): array {
		$result = [];
		foreach (preg_split('/(?<!\\\\),/', $value) ?: [] as $item) {
			$item = trim(self::unescape($item));
			if ($item !== '') {
				$result[] = $item;
			}
		}
		return $result;
	}
}
