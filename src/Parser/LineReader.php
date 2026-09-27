<?php
declare(strict_types=1);

namespace om\Parser;

use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads unfolded content lines (RFC 5545, section 3.1) from a stream, a file or a string.
 *
 * The input is processed in chunks, so memory use does not depend on its size.
 * CRLF, LF and CR line breaks are accepted, folded lines (continued by a space or a tab)
 * are joined, empty lines and a UTF-8 byte order mark are skipped.
 *
 * Keys of the generators are the numbers of the first physical line of each content line.
 *
 * @internal
 */
final class LineReader {
	private const int CHUNK = 65536;

	/**
	 * @return Generator<int, string>
	 */
	public static function fromString(string $content): Generator {
		return self::lines((static function () use ($content): Generator {
			for ($offset = 0, $length = strlen($content); $offset < $length; $offset += self::CHUNK) {
				yield substr($content, $offset, self::CHUNK);
			}
		})());
	}

	/**
	 * @return Generator<int, string>
	 * @throws RuntimeException when the file cannot be opened
	 */
	public static function fromFile(string $file): Generator {
		$stream = @fopen($file, 'rb');
		if ($stream === false) {
			throw new RuntimeException(sprintf('Cannot read iCalendar file "%s".', $file));
		}
		try {
			yield from self::fromStream($stream);
		} finally {
			fclose($stream);
		}
	}

	/**
	 * @param resource $stream
	 * @return Generator<int, string>
	 */
	public static function fromStream($stream): Generator {
		if (!is_resource($stream)) {
			throw new InvalidArgumentException('A stream resource is required.');
		}
		return self::lines((static function () use ($stream): Generator {
			while (!feof($stream)) {
				$chunk = fread($stream, self::CHUNK);
				if ($chunk === false) {
					throw new RuntimeException('Cannot read the iCalendar stream.');
				}
				yield $chunk;
			}
		})());
	}

	/**
	 * @param iterable<string> $chunks
	 * @return Generator<int, string>
	 */
	private static function lines(iterable $chunks): Generator {
		$buffer = '';
		$line = null; // the content line being unfolded
		$start = 0;   // its first physical line number
		$number = 0;
		$first = true;

		foreach (self::withEnd($chunks) as [$chunk, $end]) {
			$buffer .= $chunk;
			if ($first && $buffer !== '') {
				$buffer = str_starts_with($buffer, "\u{FEFF}") ? substr($buffer, 3) : $buffer;
				$first = false;
			}
			// keep the last (possibly incomplete) line, and a CR that may start a CRLF
			$parts = preg_split('/\r\n|\n|\r(?!$)/D', $buffer) ?: [];
			$buffer = $end ? '' : (string) array_pop($parts);
			if ($end && str_ends_with((string) end($parts), "\r")) {
				$parts[array_key_last($parts)] = substr((string) end($parts), 0, -1);
			}

			foreach ($parts as $physical) {
				$number++;
				if ($physical !== '' && ($physical[0] === ' ' || $physical[0] === "\t")) {
					if ($line !== null) {
						$line .= substr($physical, 1);
					}
					continue;
				}
				if ($line !== null && $line !== '') {
					yield $start => $line;
				}
				$line = $physical;
				$start = $number;
			}
		}
		if ($line !== null && $line !== '') {
			yield $start => $line;
		}
	}

	/**
	 * @param iterable<string> $chunks
	 * @return Generator<int, array{string, bool}> chunk and whether it is the last one
	 */
	private static function withEnd(iterable $chunks): Generator {
		$previous = null;
		foreach ($chunks as $chunk) {
			if ($previous !== null) {
				yield [$previous, false];
			}
			$previous = $chunk;
		}
		yield [$previous ?? '', true];
	}
}
