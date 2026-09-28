<?php
declare(strict_types=1);

namespace om\ICal\Value;

use InvalidArgumentException;
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
	 * An organizer or attendee: CN, ROLE, PARTSTAT, RSVP and CUTYPE parameters from the arguments.
	 * An e-mail address without a scheme becomes a "mailto:" URI.
	 *
	 *     CalAddress::create('mailto:a@example.org', name: 'A', role: 'CHAIR', status: 'ACCEPTED', rsvp: true)
	 *
	 * @param ?string $role ROLE, e.g. CHAIR, REQ-PARTICIPANT, OPT-PARTICIPANT, NON-PARTICIPANT
	 * @param ?string $status PARTSTAT, e.g. NEEDS-ACTION, ACCEPTED, DECLINED, TENTATIVE, DELEGATED
	 * @param ?string $type CUTYPE, e.g. INDIVIDUAL, GROUP, RESOURCE, ROOM
	 * @param array<string, string|list<string>> $parameters other parameters, e.g. DELEGATED-TO, SENT-BY, LANGUAGE
	 * @throws InvalidArgumentException for an empty URI
	 */
	public static function create(
		string $uri,
		?string $name = null,
		?string $role = null,
		?string $status = null,
		?bool $rsvp = null,
		?string $type = null,
		array $parameters = [],
	): self {
		$uri = trim($uri);
		if ($uri === '' || preg_match('/[\x00-\x20\x7F]/', $uri)) {
			throw new InvalidArgumentException("Invalid calendar user address: $uri");
		}
		if (!preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $uri) && str_contains($uri, '@')) {
			$uri = 'mailto:' . $uri;
		}
		$values = array_filter([
			'CN' => $name,
			'CUTYPE' => $type === null ? null : strtoupper($type),
			'ROLE' => $role === null ? null : strtoupper($role),
			'PARTSTAT' => $status === null ? null : strtoupper($status),
			'RSVP' => $rsvp === null ? null : ($rsvp ? 'TRUE' : 'FALSE'),
		], static fn(?string $value): bool => $value !== null);
		return new self($uri, Parameters::from([...$values, ...$parameters]));
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
