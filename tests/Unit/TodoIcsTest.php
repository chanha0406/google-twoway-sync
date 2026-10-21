<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Tests\Unit;

use OCA\GoogleTwoWaySync\Service\TodoIcs;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class TodoIcsTest extends TestCase {
	private const IOS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Apple Inc.//iOS//EN\r\nBEGIN:VTODO\r\nUID:ABC-IOS-1\r\nSUMMARY:old\r\nDESCRIPTION:old notes\r\nPRIORITY:1\r\nCATEGORIES:home\r\nDTSTART;TZID=Asia/Seoul:20261021T090000\r\nDUE;TZID=Asia/Seoul:20261021T180000\r\nSTATUS:NEEDS-ACTION\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER;VALUE=DATE-TIME:19760401T005545Z\r\nX-APPLE-PROXIMITY:CONNECT\r\nEND:VALARM\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

	private static function shape(string $title, string $due, string $status = 'needsAction', string $notes = ''): array {
		return ['title' => $title, 'notes' => $notes, 'status' => $status, 'due' => $due];
	}

	private static function todo(string $ics) {
		$vcal = Reader::read($ics);
		self::assertInstanceOf(VCalendar::class, $vcal);
		return $vcal->VTODO;
	}

	public function testMergeKeepsLocalOnlyFields(): void {
		$todo = self::todo(TodoIcs::merge(self::IOS, self::shape('new', '2026-10-21')));
		$this->assertSame('new', (string)$todo->SUMMARY);
		$this->assertFalse(isset($todo->DESCRIPTION));
		$this->assertSame('ABC-IOS-1', (string)$todo->UID);
		$this->assertSame('1', (string)$todo->PRIORITY);
		$this->assertSame('home', (string)$todo->CATEGORIES);
		$this->assertSame('CONNECT', (string)$todo->VALARM->{'X-APPLE-PROXIMITY'});
		$this->assertSame('20261021T180000', (string)$todo->DUE->getValue());
	}

	public function testMergeMovesTimedDueByDays(): void {
		$todo = self::todo(TodoIcs::merge(self::IOS, self::shape('t', '2026-10-23', 'completed', 'n')));
		$this->assertSame('20261023T180000', (string)$todo->DUE->getValue());
		$this->assertSame('Asia/Seoul', (string)$todo->DUE['TZID']);
		$this->assertSame('COMPLETED', (string)$todo->STATUS);
		$this->assertTrue(isset($todo->COMPLETED));
		$this->assertSame('n', (string)$todo->DESCRIPTION);
	}

	public function testMergeRewritesBrokenDateDue(): void {
		$ics = str_replace(
			"DTSTART;TZID=Asia/Seoul:20261021T090000\r\nDUE;TZID=Asia/Seoul:20261021T180000",
			'DUE;VALUE=DATE:2026-10-21',
			self::IOS,
		);
		$todo = self::todo(TodoIcs::merge($ics, self::shape('t', '2026-10-21')));
		$this->assertSame('20261021', (string)$todo->DUE->getValue());
		$this->assertSame('DATE', (string)$todo->DUE['VALUE']);
	}

	public function testMergeReopensCompletedTask(): void {
		$done = TodoIcs::merge(self::IOS, self::shape('t', '2026-10-21', 'completed'));
		$todo = self::todo(TodoIcs::merge($done, self::shape('t', '2026-10-21')));
		$this->assertSame('NEEDS-ACTION', (string)$todo->STATUS);
		$this->assertFalse(isset($todo->COMPLETED));
	}

	public function testMergeRemovesDueWhenGoogleHasNone(): void {
		$todo = self::todo(TodoIcs::merge(self::IOS, self::shape('t', '')));
		$this->assertFalse(isset($todo->DUE));
	}

	public function testMergeReturnsNullWithoutTodo(): void {
		$this->assertNull(TodoIcs::merge('garbage', self::shape('t', '')));
		$this->assertNull(TodoIcs::merge("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n", self::shape('t', '')));
	}

	public function testSerializeWritesDateDue(): void {
		$todo = self::todo(TodoIcs::serialize(self::shape('t', '2026-10-21', 'completed'), 'gid1'));
		$this->assertSame('google-task-gid1', (string)$todo->UID);
		$this->assertSame('20261021', (string)$todo->DUE->getValue());
		$this->assertSame('COMPLETED', (string)$todo->STATUS);
	}

	public function testDueFromPropertyUsesUtcDate(): void {
		$todo = self::todo(self::IOS);
		$this->assertSame('2026-10-21', TodoIcs::dueFromProperty($todo->DUE));
		$this->assertSame('', TodoIcs::dueFromProperty(null));
	}

	public function testShapeReadsGoogleFields(): void {
		$this->assertSame(
			['title' => 'old', 'notes' => 'old notes', 'status' => 'needsAction', 'due' => '2026-10-21'],
			TodoIcs::shape(self::IOS),
		);
		$this->assertNull(TodoIcs::shape('garbage'));
	}

	public function testNeedsDueRepair(): void {
		$this->assertFalse(TodoIcs::needsDueRepair(self::IOS));
		$this->assertTrue(TodoIcs::needsDueRepair(str_replace('DUE;TZID=Asia/Seoul:20261021T180000', 'DUE;VALUE=DATE:2026-10-21', self::IOS)));
		$this->assertFalse(TodoIcs::needsDueRepair(''));
	}
}
