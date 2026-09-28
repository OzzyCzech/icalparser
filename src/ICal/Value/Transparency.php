<?php
declare(strict_types=1);

namespace om\ICal\Value;

/**
 * TRANSP values (RFC 5545, section 3.8.2.7): OPAQUE blocks time (busy), TRANSPARENT does not (free).
 */
enum Transparency: string {
	case Opaque = 'OPAQUE';
	case Transparent = 'TRANSPARENT';
}
