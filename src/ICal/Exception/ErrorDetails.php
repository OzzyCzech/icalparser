<?php
declare(strict_types=1);

namespace om\ICal\Exception;

use Throwable;

/**
 * @internal implementation of ICalException
 */
trait ErrorDetails {
	private string $errorCode = '';
	private ?int $errorLine = null;
	private ?string $errorProperty = null;
	private ?string $errorValue = null;

	public static function create(
		string $code,
		string $message,
		?int $line = null,
		?string $property = null,
		?string $rawValue = null,
		?Throwable $previous = null,
	): static {
		$exception = new static($message . ($line ? " (line $line)" : ''), 0, $previous);
		$exception->errorCode = $code;
		$exception->errorLine = $line;
		$exception->errorProperty = $property;
		$exception->errorValue = $rawValue;
		return $exception;
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	public function line(): ?int {
		return $this->errorLine;
	}

	public function property(): ?string {
		return $this->errorProperty;
	}

	public function rawValue(): ?string {
		return $this->errorValue;
	}
}
