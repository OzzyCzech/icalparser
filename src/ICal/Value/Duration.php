<?php
declare(strict_types=1);

namespace om\ICal\Value;

use DateInterval;

/**
 * DURATION values (RFC 5545, section 3.3.6), e.g. "PT1H30M", "-P1W" or "P1DT12H".
 */
final class Duration {

	public static function parse(string $value): ?DateInterval {
		$value = strtoupper(trim($value));
		if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/D', $value, $match) || !preg_match('/\d/', $value)) {
			return null;
		}
		$days = (int) ($match[2] ?? 0) * 7 + (int) ($match[3] ?? 0);
		$interval = new DateInterval(sprintf('P%dDT%dH%dM%dS', $days, (int) ($match[4] ?? 0), (int) ($match[5] ?? 0), (int) ($match[6] ?? 0)));
		$interval->invert = ($match[1] ?? '') === '-' ? 1 : 0;
		return $interval;
	}

	/**
	 * Format an interval; months and years are not allowed by the RFC and are converted
	 * to days only when the interval was created by DateTime::diff().
	 */
	public static function format(DateInterval $interval): string {
		$days = $interval->days !== false && ($interval->y || $interval->m) ? $interval->days : $interval->d;
		$time = ($interval->h ? $interval->h . 'H' : '') . ($interval->i ? $interval->i . 'M' : '') . ($interval->s ? $interval->s . 'S' : '');
		$result = 'P' . ($days ? $days . 'D' : '') . ($time !== '' ? 'T' . $time : '');
		return ($interval->invert ? '-' : '') . ($result === 'P' ? 'PT0S' : $result);
	}
}
