<?php
declare(strict_types=1);

namespace om\ICal;

/**
 * Writes components as iCalendar data (RFC 5545, section 3.1): CRLF line breaks and
 * lines folded at 75 octets without splitting UTF-8 characters. Properties are written
 * with their raw values, so unknown and X- properties are kept unchanged.
 */
final class Serializer {
	public static function serialize(Component $component): string {
		$result = 'BEGIN:' . $component->name . "\r\n";
		foreach ($component->properties as $property) {
			$result .= self::fold((string) $property);
		}
		foreach ($component->components as $child) {
			$result .= self::serialize($child);
		}
		return $result . 'END:' . $component->name . "\r\n";
	}

	/**
	 * Fold a content line into chunks of at most 75 octets.
	 */
	public static function fold(string $line): string {
		$result = '';
		$limit = 75;
		while (strlen($line) > $limit) {
			$cut = $limit;
			while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
				$cut--; // do not split a multibyte character
			}
			$result .= substr($line, 0, $cut) . "\r\n ";
			$line = substr($line, $cut);
			$limit = 74; // the leading space counts
		}
		return $result . $line . "\r\n";
	}
}
