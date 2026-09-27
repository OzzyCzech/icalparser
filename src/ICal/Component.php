<?php
declare(strict_types=1);

namespace om\ICal;

/**
 * An immutable calendar component (RFC 5545, section 3.6): a name, properties and child
 * components. Any component is represented, including unknown and X- components.
 *
 * "with" methods return a modified copy.
 */
final readonly class Component {

	/**
	 * @param list<Property> $properties
	 * @param list<Component> $components
	 */
	public function __construct(
		public string $name,
		public array $properties = [],
		public array $components = [],
	) {
	}

	/**
	 * First property with the name, e.g. property('X-APPLE-STRUCTURED-LOCATION').
	 */
	public function property(string $name): ?Property {
		$name = strtoupper($name);
		foreach ($this->properties as $property) {
			if ($property->name === $name) {
				return $property;
			}
		}
		return null;
	}

	/**
	 * All properties with the name.
	 *
	 * @return list<Property>
	 */
	public function properties(string $name): array {
		$name = strtoupper($name);
		return array_values(array_filter($this->properties, static fn(Property $property): bool => $property->name === $name));
	}

	public function has(string $name): bool {
		return $this->property($name) !== null;
	}

	/**
	 * First child component with the name.
	 */
	public function component(string $name): ?self {
		$name = strtoupper($name);
		foreach ($this->components as $component) {
			if ($component->name === $name) {
				return $component;
			}
		}
		return null;
	}

	/**
	 * Child components with the name.
	 *
	 * @return list<Component>
	 */
	public function components(string $name): array {
		$name = strtoupper($name);
		return array_values(array_filter($this->components, static fn(Component $component): bool => $component->name === $name));
	}

	/**
	 * Add a property.
	 *
	 * @param array<string, string|list<string>>|Parameters $parameters
	 */
	public function withProperty(Property|string $property, string $value = '', array|Parameters $parameters = []): self {
		$property = $property instanceof Property ? $property : Property::create($property, $value, $parameters);
		return new self($this->name, [...$this->properties, $property], $this->components);
	}

	/**
	 * Replace all properties with the name by the given one.
	 *
	 * @param array<string, string|list<string>>|Parameters $parameters
	 */
	public function withReplacedProperty(Property|string $property, string $value = '', array|Parameters $parameters = []): self {
		$property = $property instanceof Property ? $property : Property::create($property, $value, $parameters);
		return $this->withoutProperties($property->name)->withProperty($property);
	}

	public function withoutProperties(string $name): self {
		$name = strtoupper($name);
		return new self($this->name, array_values(array_filter($this->properties, static fn(Property $property): bool => $property->name !== $name)), $this->components);
	}

	public function withComponent(self $component): self {
		return new self($this->name, $this->properties, [...$this->components, $component]);
	}

	/**
	 * @param list<Component> $components
	 */
	public function withComponents(array $components): self {
		return new self($this->name, $this->properties, $components);
	}
}
