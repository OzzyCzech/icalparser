<?php
declare(strict_types=1);

namespace om\ICal\Value;

/**
 * CLASS values (RFC 5545, section 3.8.1.3); other ones are IANA tokens or X- names.
 */
enum Classification: string {
	case Public = 'PUBLIC';
	case Private = 'PRIVATE';
	case Confidential = 'CONFIDENTIAL';
}
