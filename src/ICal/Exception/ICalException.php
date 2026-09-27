<?php
declare(strict_types=1);

namespace om\ICal\Exception;

use Throwable;

/**
 * Common interface of all exceptions of the library.
 *
 * Details are available when they are known: the line number, the property name,
 * the raw value and a stable machine readable error code (e.g. "syntax.invalid-line").
 */
interface ICalException extends Throwable {

	public function errorCode(): string;

	public function line(): ?int;

	public function property(): ?string;

	public function rawValue(): ?string;
}
