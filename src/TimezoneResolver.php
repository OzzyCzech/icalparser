<?php
declare(strict_types=1);

namespace om;

use DateTimeZone;
use Exception;

/**
 * Resolves TZID and X-WR-TIMEZONE values to PHP timezones.
 *
 * Handles Windows names (e.g. "W. Europe Standard Time"), prefixed values
 * (e.g. "/mozilla.org/20070129_1/Europe/Paris") and multi-segment IANA zones
 * (e.g. "America/Argentina/Buenos_Aires"). Results are cached.
 */
final class TimezoneResolver {
	/** @var array<string, DateTimeZone|false> */
	private array $cache = [];

	/**
	 * @param array<string, string> $windowsTimezones Windows name => IANA name
	 */
	public function __construct(
		private readonly array $windowsTimezones = [],
	) {
	}

	public function resolve(string $value): ?DateTimeZone {
		$timezone = $this->cache[$value] ??= $this->find($value) ?? false;
		return $timezone ?: null;
	}

	private function find(string $value): ?DateTimeZone {
		$value = trim($value, " \t'\"");
		$parts = array_values(array_filter(preg_split('#[/\\\\]#', $value) ?: []));
		$count = count($parts);
		if ($count < 2) {
			return self::create($this->windowsTimezones[$value] ?? $value);
		}

		// try paths from the end, shortest first:
		// "/mozilla.org/20070129_1/Europe/Paris" -> "Europe/Paris" ✓
		// "America/Argentina/Buenos_Aires" -> "Argentina/Buenos_Aires" ✗ -> "America/Argentina/Buenos_Aires" ✓
		for ($length = 2; $length <= $count; $length++) {
			$candidate = implode('/', array_slice($parts, $count - $length));
			if ($timezone = self::create($this->windowsTimezones[$candidate] ?? $candidate)) {
				return $timezone;
			}
		}
		return null;
	}

	private static function create(string $name): ?DateTimeZone {
		try {
			return new DateTimeZone($name);
		} catch (Exception) {
			return null;
		}
	}
}
