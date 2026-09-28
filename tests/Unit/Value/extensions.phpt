<?php
declare(strict_types=1);

/**
 * Typed getters and values of the RFC 5545 updates: RFC 7986, RFC 9073, RFC 9074 and RFC 9253.
 */

use om\ICal;
use om\ICal\Calendar;
use om\ICal\Exception\InvalidValueException;
use om\ICal\Parameters;
use om\ICal\Parser\ParserMode;
use om\ICal\Value\Duration;
use om\ICal\Value\Image;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

function calendar(string ...$lines): Calendar {
	return ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n" . implode("\r\n", $lines) . "\r\nEND:VCALENDAR\r\n");
}

test('Image with a URI or binary data (RFC 7986)', function () {
	$image = new Image('https://example.com/party.png', null, Parameters::parse('DISPLAY=badge,THUMBNAIL;FMTTYPE=image/png;ALTREP="https://example.com/party"'));
	Assert::false($image->isBinary());
	Assert::same(['BADGE', 'THUMBNAIL'], $image->display());
	Assert::same('image/png', $image->mediaType());
	Assert::same('https://example.com/party', $image->altRep());

	$binary = new Image(null, 'hello', Parameters::parse('ENCODING=BASE64;VALUE=BINARY'));
	Assert::true($binary->isBinary());
	Assert::same(['BADGE'], $binary->display(), 'BADGE is the default');
	Assert::null($binary->mediaType());
	Assert::null($binary->altRep());
});

test('color(), images(), source() and refreshInterval() of a calendar (RFC 7986)', function () {
	$calendar = calendar(
		'NAME:Holidays',
		'COLOR:turquoise',
		'SOURCE;VALUE=URI:https://example.com/holidays.ics',
		'REFRESH-INTERVAL;VALUE=DURATION:P1W',
		'IMAGE;VALUE=URI;DISPLAY=BADGE;FMTTYPE=image/png:https://example.com/logo.png',
		'IMAGE;ENCODING=BASE64;VALUE=BINARY;FMTTYPE=image/png:aGVsbG8=',
		'IMAGE;ENCODING=BASE64;VALUE=BINARY:***',
	);
	Assert::same('turquoise', $calendar->color());
	Assert::same('https://example.com/holidays.ics', $calendar->source());
	Assert::same('P7D', Duration::format($calendar->refreshInterval()));
	$images = $calendar->images();
	Assert::count(2, $images, 'an image with invalid data is skipped');
	Assert::same(['https://example.com/logo.png', null], [$images[0]->uri, $images[0]->data]);
	Assert::same([null, 'hello', 'image/png'], [$images[1]->uri, $images[1]->data, $images[1]->mediaType()]);

	$empty = calendar();
	Assert::same([null, [], null, null], [$empty->color(), $empty->images(), $empty->source(), $empty->refreshInterval()]);
	Assert::null(calendar('REFRESH-INTERVAL:weekly')->refreshInterval());
});

test('color() and images() of events, tasks and journal entries (RFC 7986)', function () {
	$calendar = calendar(
		'BEGIN:VEVENT', 'UID:1', 'DTSTAMP:20260101T000000Z', 'DTSTART:20260105T100000Z',
		'COLOR:tomato',
		'IMAGE;VALUE=URI;DISPLAY=FULLSIZE:https://example.com/concert.png',
		'IMAGE:https://example.com/badge.png',
		'END:VEVENT',
		'BEGIN:VTODO', 'UID:2', 'DTSTAMP:20260101T000000Z', 'COLOR:Navy', 'END:VTODO',
		'BEGIN:VJOURNAL', 'UID:3', 'DTSTAMP:20260101T000000Z', 'IMAGE;VALUE=BINARY;ENCODING=BASE64:aGVsbG8=', 'END:VJOURNAL',
	);
	$event = $calendar->events()[0];
	Assert::same('tomato', $event->color());
	Assert::same(['https://example.com/concert.png', 'https://example.com/badge.png'], array_map(fn(Image $image) => $image->uri, $event->images()));
	Assert::same([['FULLSIZE'], ['BADGE']], array_map(fn(Image $image) => $image->display(), $event->images()));
	Assert::same('Navy', $calendar->todos()[0]->color());
	Assert::same([], $calendar->todos()[0]->images());
	Assert::same('hello', $calendar->journals()[0]->images()[0]->data);
	Assert::null($calendar->journals()[0]->color());
});

test('Strict mode throws for an image with invalid data', function () {
	$calendar = new Calendar(Calendar::create()->component->withProperty('IMAGE', '***', ['VALUE' => 'BINARY', 'ENCODING' => 'BASE64']), strict: true);
	Assert::exception(fn() => $calendar->images(), InvalidValueException::class);
	Assert::exception(fn() => ICal::parser()->mode(ParserMode::Strict)->parse($calendar->serialize()), InvalidValueException::class);
});

test('conferences() of events and tasks (RFC 7986)', function () {
	$calendar = calendar(
		'BEGIN:VEVENT', 'UID:1', 'DTSTAMP:20260101T000000Z', 'DTSTART:20260105T100000Z',
		'CONFERENCE;VALUE=URI;FEATURE=PHONE,MODERATOR;LABEL=Moderator dial-in:tel:+1-412-555-0123,,,654321',
		'CONFERENCE;VALUE=URI;FEATURE=audio,VIDEO,X-RECORDING;LABEL="Web video chat, access code=76543":https://video-chat.example.com/;group-id=1234',
		'CONFERENCE: xmpp:chat-123@conference.example.com ',
		'END:VEVENT',
		'BEGIN:VTODO', 'UID:2', 'DTSTAMP:20260101T000000Z', 'END:VTODO',
	);
	[$moderator, $video, $chat] = $calendar->events()[0]->conferences();
	Assert::same('tel:+1-412-555-0123,,,654321', $moderator->uri);
	Assert::same(['PHONE', 'MODERATOR'], $moderator->features());
	Assert::same('Moderator dial-in', $moderator->label());
	Assert::same('https://video-chat.example.com/;group-id=1234', (string) $video);
	Assert::same(['AUDIO', 'VIDEO', 'X-RECORDING'], $video->features());
	Assert::same('Web video chat, access code=76543', $video->label());
	Assert::same('xmpp:chat-123@conference.example.com', $chat->uri, 'the URI is trimmed');
	Assert::same([], $chat->features());
	Assert::null($chat->label());
	Assert::same([], $calendar->todos()[0]->conferences());
});
