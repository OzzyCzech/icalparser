<?php
declare(strict_types=1);

namespace om\ICal\Parser;

/**
 * Limits for untrusted input; exceeding one throws ResourceLimitException.
 */
final readonly class ParseLimits {

	public function __construct(
		public int $maxFileSize = 100 * 1024 * 1024,
		public int $maxLineLength = 1024 * 1024,
		public int $maxComponents = 1_000_000,
		public int $maxProperties = 10_000_000,
		public int $maxNestingDepth = 32,
	) {
	}

	public static function unlimited(): self {
		return new self(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX);
	}
}
