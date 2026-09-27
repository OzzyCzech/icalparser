<?php
declare(strict_types=1);

namespace om\ICal\Parser;

use Generator;
use om\ICal\ContentLine;

/**
 * Turns iCalendar data into content lines.
 *
 * Lines that are not valid content lines are reported through the $invalid callback
 * (with the line and its number) and skipped.
 */
final class Tokenizer {
	/**
	 * @param iterable<int, string> $lines unfolded lines, see LineReader
	 * @param ?callable(string, int): void $invalid
	 * @return Generator<int, ContentLine>
	 */
	public static function tokenize(iterable $lines, ?callable $invalid = null): Generator {
		foreach ($lines as $number => $line) {
			$contentLine = ContentLine::parse($line, $number);
			if ($contentLine !== null) {
				yield $contentLine;
			} elseif ($invalid !== null) {
				$invalid($line, $number);
			}
		}
	}

	/**
	 * Content lines as [name, raw parameters, raw value, line number] without creating objects.
	 *
	 * @internal used by the parser
	 * @param iterable<int, string> $lines
	 * @param ?callable(string, int): void $invalid
	 * @return Generator<int, array{string, string, string, int}>
	 */
	public static function rows(iterable $lines, ?callable $invalid = null): Generator {
		foreach ($lines as $number => $line) {
			$parts = ContentLine::split($line);
			if ($parts !== null) {
				$parts[] = $number;
				yield $parts;
			} elseif ($invalid !== null) {
				$invalid($line, $number);
			}
		}
	}

	/**
	 * @param ?callable(string, int): void $invalid
	 * @return Generator<int, ContentLine>
	 */
	public static function fromString(string $content, ?callable $invalid = null): Generator {
		return self::tokenize(LineReader::fromString($content), $invalid);
	}

	/**
	 * @param ?callable(string, int): void $invalid
	 * @return Generator<int, ContentLine>
	 */
	public static function fromFile(string $file, ?callable $invalid = null): Generator {
		return self::tokenize(LineReader::fromFile($file), $invalid);
	}

	/**
	 * @param resource $stream
	 * @param ?callable(string, int): void $invalid
	 * @return Generator<int, ContentLine>
	 */
	public static function fromStream($stream, ?callable $invalid = null): Generator {
		return self::tokenize(LineReader::fromStream($stream), $invalid);
	}
}
