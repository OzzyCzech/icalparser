<?php
declare(strict_types=1);

namespace om\ICal;

use InvalidArgumentException;
use om\ICal\Value\PropertyFactory;

/**
 * VLOCATION (RFC 9073, section 7.2): rich information about a location of an event or task,
 * e.g. the venue or the parking. Other properties, such as STRUCTURED-DATA, are available
 * through property() and $component.
 *
 * @phpstan-import-type PropertyList from PropertyFactory
 */
final class Location {
	/**
	 * @internal use Item::locations() or Location::new()
	 */
	public function __construct(
		public readonly Component $component,
		private readonly Calendar $calendar,
	) {
	}

	/**
	 * A new VLOCATION for the locations of Event::new() and Todo::new().
	 *
	 * @param ?string $uid UID, a random UUID when null
	 * @param array{float|int, float|int}|null $geo latitude and longitude
	 * @param iterable<string> $types LOCATION-TYPE values of RFC 4589, e.g. "parking"
	 * @param PropertyList $properties other properties, e.g. STRUCTURED-DATA
	 * @throws InvalidArgumentException
	 */
	public static function new(
		?string $uid = null,
		?string $name = null,
		?string $description = null,
		?array $geo = null,
		iterable $types = [],
		?string $url = null,
		array $properties = [],
	): self {
		$builder = (new ComponentBuilder('VLOCATION'))
			->text('UID', ComponentBuilder::uid($uid))
			->text('NAME', $name)
			->text('DESCRIPTION', $description)
			->add($geo === null ? null : PropertyFactory::geo($geo), PropertyFactory::texts('LOCATION-TYPE', $types))
			->uri('URL', $url);
		return new self($builder->build($properties), new Calendar());
	}

	public function property(string $name): ?Property {
		return $this->component->property($name);
	}

	/**
	 * Typed value of the first property with the name, see ValueParser::value().
	 */
	public function value(string $name): mixed {
		$property = $this->property($name);
		return $property === null ? null : $this->calendar->values()->value($property);
	}

	public function uid(): ?string {
		return $this->text('UID');
	}

	/** NAME, e.g. "The venue". */
	public function name(): ?string {
		return $this->text('NAME');
	}

	public function description(): ?string {
		return $this->text('DESCRIPTION');
	}

	/**
	 * LOCATION-TYPE values of RFC 4589, e.g. "hotel", "parking" or "restaurant".
	 *
	 * @return list<string>
	 */
	public function types(): array {
		$property = $this->property('LOCATION-TYPE');
		return $property === null ? [] : $this->calendar->values()->texts($property);
	}

	public function url(): ?string {
		$property = $this->property('URL');
		return $property === null ? null : $this->calendar->values()->uri($property);
	}

	private function text(string $name): ?string {
		$property = $this->property($name);
		return $property === null ? null : $this->calendar->values()->text($property);
	}
}
