<?php
declare(strict_types=1);

namespace om\ICal\Value;

use om\ICal\Parameters;
use Stringable;

/**
 * CAL-ADDRESS value of ORGANIZER and ATTENDEE (RFC 5545, sections 3.3.3 and 3.8.4).
 */
final readonly class CalAddress implements Stringable {
	public function __construct(
		public string $uri,
		public Parameters $parameters,
	) {
	}

	/**
	 * E-mail address of a "mailto:" URI.
	 */
	public function email(): ?string {
		return stripos($this->uri, 'mailto:') === 0 ? substr($this->uri, 7) : null;
	}

	/** Common name (CN). */
	public function name(): ?string {
		return $this->parameters->get('CN');
	}

	/** ROLE, default REQ-PARTICIPANT. */
	public function role(): string {
		return strtoupper($this->parameters->get('ROLE') ?? 'REQ-PARTICIPANT');
	}

	/** Participation status (PARTSTAT), default NEEDS-ACTION. */
	public function status(): string {
		return strtoupper($this->parameters->get('PARTSTAT') ?? 'NEEDS-ACTION');
	}

	/** Calendar user type (CUTYPE), default INDIVIDUAL. */
	public function type(): string {
		return strtoupper($this->parameters->get('CUTYPE') ?? 'INDIVIDUAL');
	}

	public function rsvp(): bool {
		return strtoupper($this->parameters->get('RSVP') ?? 'FALSE') === 'TRUE';
	}

	public function __toString(): string {
		return $this->uri;
	}
}
