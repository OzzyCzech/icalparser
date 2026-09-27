<?php
declare(strict_types=1);

/**
 * Examples from RFC 5545, section 3.8.5.3 (Recurrence Rule).
 */

use om\RRule\Expander;
use om\RRule\Rule;
use Tester\Assert;
use function tests\test;

require_once __DIR__ . '/../../bootstrap.php';

/**
 * @return list<string> local date-times in America/New_York
 */
function rrule(string $rule, string $start = '19970902T090000', int $take = 1000, string $zone = 'America/New_York'): array {
	$timezone = new DateTimeZone($zone);
	$expander = new Expander(Rule::fromString($rule), new DateTimeImmutable($start, $timezone));
	$result = [];
	foreach ($expander as $timestamp) {
		$result[] = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y-m-d H:i');
		if (count($result) >= $take) {
			break;
		}
	}
	return $result;
}

test('Daily for 10 occurrences', function () {
	Assert::same([
		'1997-09-02 09:00', '1997-09-03 09:00', '1997-09-04 09:00', '1997-09-05 09:00', '1997-09-06 09:00',
		'1997-09-07 09:00', '1997-09-08 09:00', '1997-09-09 09:00', '1997-09-10 09:00', '1997-09-11 09:00',
	], rrule('FREQ=DAILY;COUNT=10'));
});

test('Daily until December 24, 1997 keeps local time over the DST change', function () {
	$result = rrule('FREQ=DAILY;UNTIL=19971224T000000Z');
	Assert::count(113, $result);
	Assert::same('1997-10-26 09:00', $result[54]);
	Assert::same('1997-12-23 09:00', end($result));
});

test('Every other day', function () {
	Assert::same(['1997-09-02 09:00', '1997-09-04 09:00', '1997-09-06 09:00'], rrule('FREQ=DAILY;INTERVAL=2', take: 3));
});

test('Every 10 days, 5 occurrences', function () {
	Assert::same(['1997-09-02 09:00', '1997-09-12 09:00', '1997-09-22 09:00', '1997-10-02 09:00', '1997-10-12 09:00'], rrule('FREQ=DAILY;INTERVAL=10;COUNT=5'));
});

test('Every day in January, for 3 years (yearly and daily forms)', function () {
	foreach (['FREQ=YEARLY;UNTIL=20000131T140000Z;BYMONTH=1;BYDAY=SU,MO,TU,WE,TH,FR,SA', 'FREQ=DAILY;UNTIL=20000131T140000Z;BYMONTH=1'] as $rule) {
		$result = rrule($rule, '19980101T090000');
		Assert::count(93, $result);
		Assert::same(['1998-01-01 09:00', '1998-01-31 09:00', '1999-01-01 09:00', '2000-01-31 09:00'], [$result[0], $result[30], $result[31], $result[92]]);
	}
});

test('Weekly for 10 occurrences and weekly until December 24, 1997', function () {
	Assert::same([
		'1997-09-02 09:00', '1997-09-09 09:00', '1997-09-16 09:00', '1997-09-23 09:00', '1997-09-30 09:00',
		'1997-10-07 09:00', '1997-10-14 09:00', '1997-10-21 09:00', '1997-10-28 09:00', '1997-11-04 09:00',
	], rrule('FREQ=WEEKLY;COUNT=10'));
	$result = rrule('FREQ=WEEKLY;UNTIL=19971224T000000Z');
	Assert::count(17, $result);
	Assert::same('1997-12-23 09:00', end($result));
});

test('Every other week, WKST=SU', function () {
	Assert::same(['1997-09-02 09:00', '1997-09-16 09:00', '1997-09-30 09:00'], rrule('FREQ=WEEKLY;INTERVAL=2;WKST=SU', take: 3));
});

test('Weekly on Tuesday and Thursday for five weeks', function () {
	$expected = [
		'1997-09-02 09:00', '1997-09-04 09:00', '1997-09-09 09:00', '1997-09-11 09:00', '1997-09-16 09:00',
		'1997-09-18 09:00', '1997-09-23 09:00', '1997-09-25 09:00', '1997-09-30 09:00', '1997-10-02 09:00',
	];
	Assert::same($expected, rrule('FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH'));
	Assert::same($expected, rrule('FREQ=WEEKLY;COUNT=10;WKST=SU;BYDAY=TU,TH'));
});

test('Every other week on Monday, Wednesday and Friday until December 24, 1997', function () {
	$result = rrule('FREQ=WEEKLY;INTERVAL=2;UNTIL=19971224T000000Z;WKST=SU;BYDAY=MO,WE,FR', '19970901T090000');
	Assert::count(25, $result);
	Assert::same(['1997-09-01 09:00', '1997-09-03 09:00', '1997-09-05 09:00', '1997-09-15 09:00'], array_slice($result, 0, 4));
	Assert::same('1997-12-22 09:00', end($result));
});

test('Every other week on Tuesday and Thursday, for 8 occurrences', function () {
	Assert::same([
		'1997-09-02 09:00', '1997-09-04 09:00', '1997-09-16 09:00', '1997-09-18 09:00',
		'1997-09-30 09:00', '1997-10-02 09:00', '1997-10-14 09:00', '1997-10-16 09:00',
	], rrule('FREQ=WEEKLY;INTERVAL=2;COUNT=8;WKST=SU;BYDAY=TU,TH'));
});

test('Monthly on the first Friday for 10 occurrences', function () {
	Assert::same([
		'1997-09-05 09:00', '1997-10-03 09:00', '1997-11-07 09:00', '1997-12-05 09:00', '1998-01-02 09:00',
		'1998-02-06 09:00', '1998-03-06 09:00', '1998-04-03 09:00', '1998-05-01 09:00', '1998-06-05 09:00',
	], rrule('FREQ=MONTHLY;COUNT=10;BYDAY=1FR', '19970905T090000'));
});

test('Every other month on the first and last Sunday of the month for 10 occurrences', function () {
	Assert::same([
		'1997-09-07 09:00', '1997-09-28 09:00', '1997-11-02 09:00', '1997-11-30 09:00', '1998-01-04 09:00',
		'1998-01-25 09:00', '1998-03-01 09:00', '1998-03-29 09:00', '1998-05-03 09:00', '1998-05-31 09:00',
	], rrule('FREQ=MONTHLY;INTERVAL=2;COUNT=10;BYDAY=1SU,-1SU', '19970907T090000'));
});

test('Monthly on the second-to-last Monday of the month for 6 months', function () {
	Assert::same([
		'1997-09-22 09:00', '1997-10-20 09:00', '1997-11-17 09:00', '1997-12-22 09:00', '1998-01-19 09:00', '1998-02-16 09:00',
	], rrule('FREQ=MONTHLY;COUNT=6;BYDAY=-2MO', '19970922T090000'));
});

test('Monthly on the third-to-the-last day of the month', function () {
	Assert::same(['1997-09-28 09:00', '1997-10-29 09:00', '1997-11-28 09:00', '1997-12-29 09:00', '1998-01-29 09:00', '1998-02-26 09:00'], rrule('FREQ=MONTHLY;BYMONTHDAY=-3', '19970928T090000', 6));
});

test('Monthly on the 2nd and 15th, and on the first and last day of the month', function () {
	Assert::same([
		'1997-09-02 09:00', '1997-09-15 09:00', '1997-10-02 09:00', '1997-10-15 09:00', '1997-11-02 09:00',
		'1997-11-15 09:00', '1997-12-02 09:00', '1997-12-15 09:00', '1998-01-02 09:00', '1998-01-15 09:00',
	], rrule('FREQ=MONTHLY;COUNT=10;BYMONTHDAY=2,15'));
	Assert::same([
		'1997-09-30 09:00', '1997-10-01 09:00', '1997-10-31 09:00', '1997-11-01 09:00', '1997-11-30 09:00',
		'1997-12-01 09:00', '1997-12-31 09:00', '1998-01-01 09:00', '1998-01-31 09:00', '1998-02-01 09:00',
	], rrule('FREQ=MONTHLY;COUNT=10;BYMONTHDAY=1,-1', '19970930T090000'));
});

test('Every 18 months on the 10th thru 15th of the month for 10 occurrences', function () {
	Assert::same([
		'1997-09-10 09:00', '1997-09-11 09:00', '1997-09-12 09:00', '1997-09-13 09:00', '1997-09-14 09:00',
		'1997-09-15 09:00', '1999-03-10 09:00', '1999-03-11 09:00', '1999-03-12 09:00', '1999-03-13 09:00',
	], rrule('FREQ=MONTHLY;INTERVAL=18;COUNT=10;BYMONTHDAY=10,11,12,13,14,15', '19970910T090000'));
});

test('Every Tuesday, every other month', function () {
	Assert::same([
		'1997-09-02 09:00', '1997-09-09 09:00', '1997-09-16 09:00', '1997-09-23 09:00', '1997-09-30 09:00',
		'1997-11-04 09:00', '1997-11-11 09:00',
	], rrule('FREQ=MONTHLY;INTERVAL=2;BYDAY=TU', take: 7));
});

test('Yearly in June and July for 10 occurrences', function () {
	Assert::same([
		'1997-06-10 09:00', '1997-07-10 09:00', '1998-06-10 09:00', '1998-07-10 09:00', '1999-06-10 09:00',
		'1999-07-10 09:00', '2000-06-10 09:00', '2000-07-10 09:00', '2001-06-10 09:00', '2001-07-10 09:00',
	], rrule('FREQ=YEARLY;COUNT=10;BYMONTH=6,7', '19970610T090000'));
});

test('Every other year on January, February, and March for 10 occurrences', function () {
	Assert::same([
		'1997-03-10 09:00', '1999-01-10 09:00', '1999-02-10 09:00', '1999-03-10 09:00', '2001-01-10 09:00',
		'2001-02-10 09:00', '2001-03-10 09:00', '2003-01-10 09:00', '2003-02-10 09:00', '2003-03-10 09:00',
	], rrule('FREQ=YEARLY;INTERVAL=2;COUNT=10;BYMONTH=1,2,3', '19970310T090000'));
});

test('Every third year on the 1st, 100th, and 200th day for 10 occurrences', function () {
	Assert::same([
		'1997-01-01 09:00', '1997-04-10 09:00', '1997-07-19 09:00', '2000-01-01 09:00', '2000-04-09 09:00',
		'2000-07-18 09:00', '2003-01-01 09:00', '2003-04-10 09:00', '2003-07-19 09:00', '2006-01-01 09:00',
	], rrule('FREQ=YEARLY;INTERVAL=3;COUNT=10;BYYEARDAY=1,100,200', '19970101T090000'));
});

test('Every 20th Monday of the year', function () {
	Assert::same(['1997-05-19 09:00', '1998-05-18 09:00', '1999-05-17 09:00'], rrule('FREQ=YEARLY;BYDAY=20MO', '19970519T090000', 3));
});

test('Monday of week number 20 (where the default start of the week is Monday)', function () {
	Assert::same(['1997-05-12 09:00', '1998-05-11 09:00', '1999-05-17 09:00'], rrule('FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO', '19970512T090000', 3));
});

test('Every Thursday in March', function () {
	Assert::same([
		'1997-03-13 09:00', '1997-03-20 09:00', '1997-03-27 09:00', '1998-03-05 09:00', '1998-03-12 09:00',
		'1998-03-19 09:00', '1998-03-26 09:00', '1999-03-04 09:00',
	], rrule('FREQ=YEARLY;BYMONTH=3;BYDAY=TH', '19970313T090000', 8));
});

test('Every Thursday, but only during June, July, and August', function () {
	$result = rrule('FREQ=YEARLY;BYDAY=TH;BYMONTH=6,7,8', '19970605T090000', 14);
	Assert::same('1997-06-05 09:00', $result[0]);
	Assert::same('1997-08-28 09:00', $result[12]);
	Assert::same('1998-06-04 09:00', $result[13]);
});

test('Every Friday the 13th', function () {
	Assert::same(['1997-09-02 09:00', '1998-02-13 09:00', '1998-03-13 09:00', '1998-11-13 09:00', '1999-08-13 09:00'], rrule('FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13', take: 5));
});

test('The first Saturday that follows the first Sunday of the month', function () {
	Assert::same(['1997-09-13 09:00', '1997-10-11 09:00', '1997-11-08 09:00', '1997-12-13 09:00'], rrule('FREQ=MONTHLY;BYDAY=SA;BYMONTHDAY=7,8,9,10,11,12,13', '19970913T090000', 4));
});

test('Every 4 years, the first Tuesday after a Monday in November (U.S. Presidential Election day)', function () {
	Assert::same(['1996-11-05 09:00', '2000-11-07 09:00', '2004-11-02 09:00'], rrule('FREQ=YEARLY;INTERVAL=4;BYMONTH=11;BYDAY=TU;BYMONTHDAY=2,3,4,5,6,7,8', '19961105T090000', 3));
});

test('The third instance into the month of one of Tuesday, Wednesday, or Thursday (BYSETPOS)', function () {
	Assert::same(['1997-09-04 09:00', '1997-10-07 09:00', '1997-11-06 09:00'], rrule('FREQ=MONTHLY;COUNT=3;BYDAY=TU,WE,TH;BYSETPOS=3', '19970904T090000'));
});

test('The second-to-last weekday of the month (BYSETPOS)', function () {
	Assert::same(['1997-09-29 09:00', '1997-10-30 09:00', '1997-11-27 09:00', '1997-12-30 09:00', '1998-01-29 09:00'], rrule('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-2', '19970929T090000', 5));
});

test('Every 3 hours from 9:00 AM to 5:00 PM on a specific day (UNTIL corrected per RFC errata)', function () {
	Assert::same(['1997-09-02 09:00', '1997-09-02 12:00', '1997-09-02 15:00'], rrule('FREQ=HOURLY;INTERVAL=3;UNTIL=19970902T210000Z'));
});

test('Every 15 minutes for 6 occurrences and every hour and a half for 4 occurrences', function () {
	Assert::same(['1997-09-02 09:00', '1997-09-02 09:15', '1997-09-02 09:30', '1997-09-02 09:45', '1997-09-02 10:00', '1997-09-02 10:15'], rrule('FREQ=MINUTELY;INTERVAL=15;COUNT=6'));
	Assert::same(['1997-09-02 09:00', '1997-09-02 10:30', '1997-09-02 12:00', '1997-09-02 13:30'], rrule('FREQ=MINUTELY;INTERVAL=90;COUNT=4'));
});

test('Every 20 minutes from 9:00 AM to 4:40 PM every day', function () {
	$expected = ['1997-09-02 09:00', '1997-09-02 09:20', '1997-09-02 09:40', '1997-09-02 10:00'];
	$daily = rrule('FREQ=DAILY;BYHOUR=9,10,11,12,13,14,15,16;BYMINUTE=0,20,40', take: 25);
	$minutely = rrule('FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,10,11,12,13,14,15,16', take: 25);
	Assert::same($expected, array_slice($daily, 0, 4));
	Assert::same('1997-09-02 16:40', $daily[23]);
	Assert::same('1997-09-03 09:00', $daily[24]);
	Assert::same($daily, $minutely);
});

test('WKST changes the result of weekly rules with an interval', function () {
	Assert::same(['1997-08-05 09:00', '1997-08-10 09:00', '1997-08-19 09:00', '1997-08-24 09:00'], rrule('FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO', '19970805T090000'));
	Assert::same(['1997-08-05 09:00', '1997-08-17 09:00', '1997-08-19 09:00', '1997-08-31 09:00'], rrule('FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU', '19970805T090000'));
});

test('Invalid dates such as February 30 are ignored', function () {
	Assert::same(['2007-01-15 09:00', '2007-01-30 09:00', '2007-02-15 09:00', '2007-03-15 09:00', '2007-03-30 09:00'], rrule('FREQ=MONTHLY;BYMONTHDAY=15,30;COUNT=5', '20070115T090000'));
});
