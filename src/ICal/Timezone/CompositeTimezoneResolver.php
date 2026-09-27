<?php
declare(strict_types=1);

namespace om\ICal\Timezone;

use DateTimeZone;
use om\ICal\Component;

/**
 * Asks the resolvers in order and returns the first result.
 */
final readonly class CompositeTimezoneResolver implements TimezoneResolver {
	/** @var list<TimezoneResolver> */
	private array $resolvers;

	public function __construct(TimezoneResolver ...$resolvers) {
		$this->resolvers = array_values($resolvers);
	}

	/**
	 * VTIMEZONE of the calendar, IANA names, aliases, then the optional fallback.
	 */
	public static function default(?DateTimeZone $fallback = null): self {
		$names = new self(new IanaTimezoneResolver(), new AliasTimezoneResolver());
		return new self(new VTimezoneResolver($names), $names, ...($fallback === null ? [] : [new FallbackTimezoneResolver($fallback)]));
	}

	public function resolve(string $tzid, Component $calendar): ?ResolvedTimezone {
		foreach ($this->resolvers as $resolver) {
			if (($resolved = $resolver->resolve($tzid, $calendar)) !== null) {
				return $resolved;
			}
		}
		return null;
	}
}
