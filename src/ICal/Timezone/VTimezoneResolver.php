<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

use DateTimeImmutable;
use om\ICal\Component;
use om\ICal\Value\Text;
use om\TimezoneGuesser;
use WeakMap;

/**
 * Resolves a TZID defined by a VTIMEZONE component of the calendar.
 *
 * PHP cannot create a timezone from custom rules, so the IANA timezone with the same
 * transitions around the reference date is used (see TimezoneGuesser), preferring the
 * timezone of the same name. A definition without transitions becomes a fixed offset.
 */
final class VTimezoneResolver implements TimezoneResolver {
	/** @var WeakMap<Component, array<string, ?ResolvedTimezone>> */
	private WeakMap $cache;

	public function __construct(
		private readonly TimezoneResolver $names = new CompositeTimezoneResolver(new IanaTimezoneResolver(), new AliasTimezoneResolver()),
		private readonly ?DateTimeImmutable $reference = null,
	) {
		$this->cache = new WeakMap();
	}

	public function resolve(string $tzid, Component $calendar): ?ResolvedTimezone {
		$cache = $this->cache[$calendar] ?? [];
		if (!array_key_exists($tzid, $cache)) {
			$cache[$tzid] = $this->find($tzid, $calendar);
			$this->cache[$calendar] = $cache;
		}
		return $cache[$tzid];
	}

	private function find(string $tzid, Component $calendar): ?ResolvedTimezone {
		foreach ($calendar->components('VTIMEZONE') as $definition) {
			$defined = $definition->property('TZID')?->value;
			if ($defined === null || Text::unescape($defined) !== $tzid) {
				continue;
			}
			// X-LIC-LOCATION (Mozilla) and the TZID itself name the intended zone
			$location = $definition->property('X-LIC-LOCATION')?->value;
			$preferred = array_values(array_unique(array_filter([
				$location === null ? null : $this->names->resolve($location, $calendar)?->timezone->getName(),
				$this->names->resolve($tzid, $calendar)?->timezone->getName(),
				...array_values(AliasTimezoneResolver::windowsTimezones()),
			])));
			$timezone = TimezoneGuesser::guess(self::observances($definition), $this->reference, $preferred);
			return $timezone === null ? null : new ResolvedTimezone($tzid, $timezone, TimezoneSource::VTimezone);
		}
		return null;
	}

	/**
	 * @return list<array{start: string, offsetFrom: string, offsetTo: string, rrule: ?string, rdates: list<string>}>
	 */
	public static function observances(Component $definition): array {
		$observances = [];
		foreach ($definition->components as $observance) {
			if ($observance->name !== 'STANDARD' && $observance->name !== 'DAYLIGHT') {
				continue;
			}
			$rdates = [];
			foreach ($observance->properties('RDATE') as $rdate) {
				array_push($rdates, ...explode(',', $rdate->value));
			}
			$observances[] = [
				'start' => $observance->property('DTSTART')->value ?? '19700101T000000',
				'offsetFrom' => $observance->property('TZOFFSETFROM')->value ?? '+0000',
				'offsetTo' => $observance->property('TZOFFSETTO')->value ?? '+0000',
				'rrule' => $observance->property('RRULE')?->value,
				'rdates' => $rdates,
			];
		}
		return $observances;
	}
}
