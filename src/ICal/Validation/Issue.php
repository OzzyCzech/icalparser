<?php
declare(strict_types=1);

namespace om\ICal\Validation;

use Stringable;

final readonly class Issue implements Stringable {
	/**
	 * @param string $code stable identifier, e.g. "event.dtend-and-duration"
	 * @param string $component name of the component, e.g. VEVENT
	 */
	public function __construct(
		public Severity $severity,
		public string $code,
		public string $message,
		public string $component,
		public ?string $property = null,
		public ?int $line = null,
		public ?string $uid = null,
	) {
	}

	public function __toString(): string {
		return sprintf(
			'%s %s%s%s: %s [%s]',
			strtoupper($this->severity->name),
			$this->component,
			$this->property !== null ? ".$this->property" : '',
			$this->line !== null ? " (line $this->line)" : '',
			$this->message,
			$this->code,
		);
	}
}
