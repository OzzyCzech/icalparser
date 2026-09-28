<?php
declare(strict_types=1);

namespace om\ICal\Value;

/**
 * STATUS values (RFC 5545, section 3.8.1.11); events, tasks and journal entries allow different ones.
 */
enum Status: string {
	case Tentative = 'TENTATIVE';
	case Confirmed = 'CONFIRMED';
	case Cancelled = 'CANCELLED';
	case NeedsAction = 'NEEDS-ACTION';
	case Completed = 'COMPLETED';
	case InProcess = 'IN-PROCESS';
	case Draft = 'DRAFT';
	case Final = 'FINAL';

	/**
	 * Values allowed in the component.
	 *
	 * @return list<self>
	 */
	public static function of(string $component): array {
		return match (strtoupper($component)) {
			'VEVENT' => [self::Tentative, self::Confirmed, self::Cancelled],
			'VTODO' => [self::NeedsAction, self::Completed, self::InProcess, self::Cancelled],
			'VJOURNAL' => [self::Draft, self::Final, self::Cancelled],
			default => [],
		};
	}
}
