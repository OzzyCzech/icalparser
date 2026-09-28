<?php
declare(strict_types=1);

namespace om\ICal\Value;

use om\ICal\Parameters;

/**
 * IMAGE of a calendar, event, task or journal entry (RFC 7986, section 5.10):
 * a URI or inline binary data.
 */
final readonly class Image {
	/**
	 * @param ?string $uri the URI, null for inline data
	 * @param ?string $data the decoded data of VALUE=BINARY, null for a URI
	 */
	public function __construct(
		public ?string $uri,
		public ?string $data,
		public Parameters $parameters,
	) {
	}

	public function isBinary(): bool {
		return $this->data !== null;
	}

	/**
	 * DISPLAY modes in upper case, e.g. BADGE (default), GRAPHIC, FULLSIZE, THUMBNAIL.
	 *
	 * @return list<string>
	 */
	public function display(): array {
		$display = array_map(strtoupper(...), $this->parameters->values('DISPLAY'));
		return $display === [] ? ['BADGE'] : $display;
	}

	/** Media type (FMTTYPE), e.g. image/png. */
	public function mediaType(): ?string {
		return $this->parameters->get('FMTTYPE');
	}

	/** URI launched by a click on the image (ALTREP). */
	public function altRep(): ?string {
		return $this->parameters->get('ALTREP');
	}
}
