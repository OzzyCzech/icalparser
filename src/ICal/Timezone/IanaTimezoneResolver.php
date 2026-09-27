<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

use DateTimeZone;
use om\ICal\Component;

/**
 * Resolves IANA timezone names like "Europe/Prague" (case-insensitive).
 */
final class IanaTimezoneResolver implements TimezoneResolver {
	/** @var array<string, string>|null lower-case name => name */
	private static ?array $names = null;

	public function resolve(string $tzid, Component $calendar): ?ResolvedTimezone {
		self::$names ??= array_change_key_case(array_combine(
			$identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
			$identifiers,
		));
		$name = self::$names[strtolower(trim($tzid))] ?? null;
		return $name === null ? null : new ResolvedTimezone($tzid, new DateTimeZone($name), TimezoneSource::Iana);
	}
}
