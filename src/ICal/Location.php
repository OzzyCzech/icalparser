<?php
declare(strict_types=1);

namespace om\ICal;

/**
 * VLOCATION (RFC 9073, section 7.2): rich information about a location of an event or task,
 * e.g. the venue or the parking. Other properties, such as STRUCTURED-DATA, are available
 * through property() and $component.
 */
final class Location {
	/**
	 * @internal use Item::locations()
	 */
	public function __construct(
		public readonly Component $component,
		private readonly Calendar $calendar,
	) {
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
