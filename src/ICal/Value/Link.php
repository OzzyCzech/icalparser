<?php
declare(strict_types=1);

namespace om\ICal\Value;

use om\ICal\Parameters;
use Stringable;

/**
 * LINK of a component (RFC 9253, section 8.2): a reference to external information,
 * a serialization of a web link of RFC 8288.
 */
final readonly class Link implements Stringable {
	/**
	 * @param string $value a URI, an XML reference (a URI with an XPointer anchor) or a UID, see valueType()
	 */
	public function __construct(
		public string $value,
		public Parameters $parameters,
	) {
	}

	/**
	 * Type of the value: URI (default), XML-REFERENCE or UID.
	 */
	public function valueType(): string {
		return strtoupper($this->parameters->get('VALUE') ?? 'URI');
	}

	/**
	 * Link relation type (LINKREL): a registered type such as "latest-version" or a URI; there is no default.
	 */
	public function relation(): ?string {
		return $this->parameters->get('LINKREL');
	}

	/** Human-readable LABEL (the "title" of RFC 8288). */
	public function label(): ?string {
		return $this->parameters->get('LABEL');
	}

	/** Media type of the target (FMTTYPE). */
	public function mediaType(): ?string {
		return $this->parameters->get('FMTTYPE');
	}

	/** LANGUAGE of the target (the "hreflang" of RFC 8288). */
	public function language(): ?string {
		return $this->parameters->get('LANGUAGE');
	}

	public function __toString(): string {
		return $this->value;
	}
}
