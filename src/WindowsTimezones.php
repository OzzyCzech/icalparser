<?php
declare(strict_types=1);

/**
 * Windows timezone names (CLDR) and display names used by Outlook and Exchange, mapped to
 * IANA timezones. See resources/timezones; update the CLDR part with "composer timezones".
 */
return [
	...require __DIR__ . '/../resources/timezones/aliases.php',
	...require __DIR__ . '/../resources/timezones/windows.php',
];
