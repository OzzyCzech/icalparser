<?php
declare(strict_types=1);

namespace om\ICal\Value;

use DateInterval;
use om\ICal\Parameters;
use Stringable;

/**
 * RELATED-TO of a component (RFC 5545, section 3.8.4.5, updated by RFC 9253, section 9.1).
 */
final readonly class Relation implements Stringable {
	/**
	 * @param string $value the UID of the related component, or a URI, see valueType()
	 */
	public function __construct(
		public string $value,
		public Parameters $parameters,
	) {
	}

	/**
	 * Type of the value: UID (default), URI or TEXT.
	 */
	public function valueType(): string {
		return strtoupper($this->parameters->get('VALUE') ?? 'UID');
	}

	/**
	 * RELTYPE in upper case: PARENT (default), CHILD, SIBLING, the RFC 9253 types FINISHTOSTART, FINISHTOFINISH,
	 * STARTTOFINISH, STARTTOSTART, FIRST, NEXT, DEPENDS-ON, REFID, CONCEPT, SNOOZE of RFC 9074, or an X- value.
	 */
	public function type(): string {
		return strtoupper($this->parameters->get('RELTYPE') ?? 'PARENT');
	}

	/**
	 * GAP (RFC 9253): the lag (positive) or lead (negative) time between the components of a temporal relation.
	 */
	public function gap(): ?DateInterval {
		$gap = $this->parameters->get('GAP');
		return $gap === null ? null : Duration::parse($gap);
	}

	public function __toString(): string {
		return $this->value;
	}
}
