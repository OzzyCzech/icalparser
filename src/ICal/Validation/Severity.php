<?php
declare(strict_types=1);

namespace om\ICal\Validation;

enum Severity: int {
	/** a remark, e.g. a deprecated property */
	case Info = 0;
	/** a SHOULD of the RFC is violated, or data may be interpreted differently by other programs */
	case Warning = 1;
	/** a MUST of the RFC is violated */
	case Error = 2;
}
