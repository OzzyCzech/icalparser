<?php
declare(strict_types=1);

/**
 * Alarm::display(), audio() and email() (RFC 5545, section 3.6.6).
 */

use om\ICal\Alarm;
use om\ICal\Property;
use om\ICal\Serializer;
use om\ICal\Value\CalAddress;
use om\ICal\Value\DateTimeValue;
use om\ICal\Value\Duration;
use Tester\Assert;

use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

function lines(Alarm $alarm): array {
	return explode("\r\n", trim(Serializer::serialize($alarm->component)));
}

test('A display alarm with a relative trigger', function () {
	$alarm = Alarm::display('Standup, in 5 minutes', trigger: '-PT5M');
	Assert::same(['BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT5M', 'DESCRIPTION:Standup\, in 5 minutes', 'END:VALARM'], lines($alarm));
	Assert::same('DISPLAY', $alarm->action());
	Assert::same('-PT5M', Duration::format($alarm->trigger()));
	Assert::same('START', $alarm->related());
	Assert::same('Standup, in 5 minutes', $alarm->description());

	$negative = new DateInterval('PT10M');
	$negative->invert = 1;
	Assert::same('TRIGGER:-PT10M', (string) Alarm::display('x', $negative)->property('TRIGGER'));
});

test('A trigger related to the end, repetitions and UID', function () {
	$alarm = Alarm::display('Ends soon', trigger: '-PT15M', related: 'end', repeat: 2, duration: new DateInterval('PT5M'), uid: 'alarm-1');
	Assert::same(['BEGIN:VALARM', 'UID:alarm-1', 'ACTION:DISPLAY', 'TRIGGER;RELATED=END:-PT15M', 'DESCRIPTION:Ends soon', 'REPEAT:2', 'DURATION:PT5M', 'END:VALARM'], lines($alarm));
	Assert::same(['END', 2, 'PT5M', 'alarm-1'], [$alarm->related(), $alarm->repeat(), Duration::format($alarm->duration()), $alarm->uid()]);
});

test('An absolute trigger is written in UTC', function () {
	$alarm = Alarm::audio(new DateTimeImmutable('2026-01-05 09:00', new DateTimeZone('Europe/Prague')), sound: 'https://example.org/ding.wav');
	Assert::same(['BEGIN:VALARM', 'ACTION:AUDIO', 'TRIGGER;VALUE=DATE-TIME:20260105T080000Z', 'ATTACH:https://example.org/ding.wav', 'END:VALARM'], lines($alarm));
	Assert::true($alarm->trigger() instanceof DateTimeValue && $alarm->trigger()->isUtc());
	Assert::same('TRIGGER;VALUE=DATE-TIME:20260105T080000Z', (string) Alarm::audio('20260105T080000Z')->property('TRIGGER'));
	Assert::same(['BEGIN:VALARM', 'ACTION:AUDIO', 'TRIGGER:PT0S', 'END:VALARM'], lines(Alarm::audio(new DateInterval('PT0S'))));
});

test('An e-mail alarm has a summary, a description and attendees', function () {
	$alarm = Alarm::email('Reminder', "Line 1\nLine 2", [CalAddress::create('mailto:a@example.org', name: 'A'), 'b@example.org'], trigger: '-P1D', attachments: ['https://example.org/agenda.pdf']);
	Assert::same([
		'BEGIN:VALARM', 'ACTION:EMAIL', 'TRIGGER:-P1D', 'DESCRIPTION:Line 1\nLine 2', 'SUMMARY:Reminder',
		'ATTENDEE;CN=A:mailto:a@example.org', 'ATTENDEE:mailto:b@example.org', 'ATTACH:https://example.org/agenda.pdf', 'END:VALARM',
	], lines($alarm));
	Assert::same(['a@example.org', 'b@example.org'], array_map(fn(CalAddress $a) => $a->email(), $alarm->attendees()));
	Assert::same("Line 1\nLine 2", $alarm->description());
	Assert::same('Reminder', $alarm->summary());
});

test('Other properties of an alarm', function () {
	$alarm = Alarm::display('x', '-PT5M', properties: ['ACKNOWLEDGED' => new DateTimeImmutable('2026-01-05 08:55', new DateTimeZone('UTC')), Property::create('X-WR-ALARMUID', 'a')]);
	Assert::same('20260105T085500Z', (string) $alarm->acknowledged());
	Assert::same('a', $alarm->property('X-WR-ALARMUID')->value);
});

test('Invalid alarms are rejected', function () {
	Assert::exception(fn() => Alarm::email('s', 'd', [], '-PT5M'), InvalidArgumentException::class, '~at least one attendee~');
	Assert::exception(fn() => Alarm::display('x', '-PT5M', repeat: 2), InvalidArgumentException::class, '~given together~');
	Assert::exception(fn() => Alarm::display('x', '-PT5M', duration: 'PT5M'), InvalidArgumentException::class, '~given together~');
	Assert::exception(fn() => Alarm::display('x', '-PT5M', repeat: 0, duration: 'PT5M'), InvalidArgumentException::class, '~REPEAT must be~');
	Assert::exception(fn() => Alarm::display('x', '-PT5M', repeat: 1, duration: '-PT5M'), InvalidArgumentException::class, '~must not be negative~');
	Assert::exception(fn() => Alarm::display('x', 'soon'), InvalidArgumentException::class, '~duration or a UTC time~');
	Assert::exception(fn() => Alarm::display('x', '20260105T080000'), InvalidArgumentException::class, '~duration or a UTC time~');
	Assert::exception(fn() => Alarm::display('x', DateTimeValue::floating(new DateTimeImmutable('2026-01-05 08:00'))), InvalidArgumentException::class, '~must be an instant~');
	Assert::exception(fn() => Alarm::display('x', '20260105T080000Z', related: 'END'), InvalidArgumentException::class, '~only for a relative trigger~');
	Assert::exception(fn() => Alarm::display('x', '-PT5M', related: 'MIDDLE'), InvalidArgumentException::class, '~START or END~');
	Assert::exception(fn() => Alarm::display('x', '-P1M'), InvalidArgumentException::class);
	Assert::exception(fn() => Alarm::display('x', '-PT5M', properties: ['ACTION' => 'AUDIO']), InvalidArgumentException::class, '~already set~');
	Assert::exception(fn() => Alarm::display('x', '-PT5M', uid: ''), InvalidArgumentException::class, '~UID~');
});
