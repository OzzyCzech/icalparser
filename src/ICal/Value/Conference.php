<?php
declare(strict_types=1);

namespace om\ICal\Value;

use om\ICal\Parameters;
use Stringable;

/**
 * CONFERENCE of an event or task (RFC 7986, section 5.11): a URI for accessing a conferencing system.
 */
final readonly class Conference implements Stringable {
	public function __construct(
		public string $uri,
		public Parameters $parameters,
	) {
	}

	/**
	 * FEATURE values in upper case: AUDIO, CHAT, FEED, MODERATOR, PHONE, SCREEN, VIDEO or an X- value.
	 *
	 * @return list<string>
	 */
	public function features(): array {
		return array_map(strtoupper(...), $this->parameters->values('FEATURE'));
	}

	/** Human-readable LABEL, e.g. "Attendee dial-in". */
	public function label(): ?string {
		return $this->parameters->get('LABEL');
	}

	public function __toString(): string {
		return $this->uri;
	}
}
