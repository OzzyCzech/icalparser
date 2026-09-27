<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

use DateTimeZone;
use Exception;
use IntlTimeZone;
use om\ICal\Component;
use om\TimezoneResolver as NameResolver;

/**
 * Resolves Windows timezone names ("W. Europe Standard Time"), Outlook display names
 * ("(UTC+01:00) Amsterdam, Berlin, ..."), prefixed names ("/mozilla.org/20070129_1/Europe/Paris"),
 * quoted names and IANA names without their first part ("Argentina/Buenos_Aires").
 *
 * The bundled map (CLDR plus aliases) is used first, so results do not depend on the ICU version;
 * IntlTimeZone::getIDForWindowsID() of the intl extension is a fallback for newer Windows names.
 */
final class AliasTimezoneResolver implements TimezoneResolver {
	private readonly NameResolver $names;

	/**
	 * @param ?array<string, string> $windowsTimezones Windows name => IANA name, the bundled mapping by default
	 */
	public function __construct(?array $windowsTimezones = null) {
		$this->names = new NameResolver($windowsTimezones ?? self::windowsTimezones());
	}

	public function resolve(string $tzid, Component $calendar): ?ResolvedTimezone {
		$timezone = $this->names->resolve($tzid) ?? self::fromIntl($tzid) ?? self::bySuffix($tzid);
		return $timezone === null ? null : new ResolvedTimezone($tzid, $timezone, TimezoneSource::Alias);
	}

	private static function fromIntl(string $tzid): ?DateTimeZone {
		if (!class_exists(IntlTimeZone::class)) {
			return null;
		}
		// returns false for unknown names, although the stubs say string
		$name = (string) IntlTimeZone::getIDForWindowsID(trim($tzid, " \t'\""));
		try {
			return $name !== '' ? new DateTimeZone($name) : null;
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * An IANA name missing its first part, e.g. "Argentina/Buenos_Aires", when the match is unique.
	 */
	private static function bySuffix(string $tzid): ?DateTimeZone {
		$suffix = '/' . strtolower(trim($tzid, " \t'\"/"));
		if (strlen($suffix) < 4) {
			return null;
		}
		$matches = array_filter(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), static fn(string $name): bool => str_ends_with(strtolower($name), $suffix));
		return count($matches) === 1 ? new DateTimeZone(reset($matches)) : null;
	}

	/**
	 * @return array<string, string>
	 */
	public static function windowsTimezones(): array {
		static $map;
		return $map ??= require __DIR__ . '/../../WindowsTimezones.php';
	}
}
