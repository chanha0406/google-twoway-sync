<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Tests\Unit;

use OCA\GoogleTwoWaySync\Service\EventConverter;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class EventMergeTest extends TestCase {
	private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:series@test\r\nDTSTAMP:20261023T000000Z\r\nDTSTART;VALUE=DATE:20261001\r\nDTEND;VALUE=DATE:20261002\r\nRRULE:FREQ=DAILY;COUNT=5\r\nSUMMARY:Master\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:series@test\r\nDTSTAMP:20261023T000000Z\r\nDTSTART;VALUE=DATE:20261002\r\nDTEND;VALUE=DATE:20261003\r\nRECURRENCE-ID;VALUE=DATE:20261002\r\nSUMMARY:Keep me\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:series@test\r\nDTSTAMP:20261023T000000Z\r\nDTSTART;VALUE=DATE:20261003\r\nDTEND;VALUE=DATE:20261004\r\nRECURRENCE-ID;VALUE=DATE:20261003\r\nSUMMARY:Drop me\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

	private function calendar(): VCalendar {
		$vcal = Reader::read(self::ICS);
		self::assertInstanceOf(VCalendar::class, $vcal);
		return $vcal;
	}

	/**
	 * @return array{0: EventConverter, 1: VCalendar, 2: array{master: array<string, mixed>, overrides: array<string, array<string, mixed>>}}
	 */
	private function localOnly(): array {
		$converter = new EventConverter();
		$vcal = $this->calendar();
		$local = $converter->groupFromCalendar($vcal, 'Asia/Seoul');
		self::assertNotNull($local);
		$google = $local;
		$google['overrides'] = [];
		return [$converter, $vcal, $google];
	}

	public function testPullKeepsPendingLocalExceptions(): void {
		[$converter, $vcal, $google] = $this->localOnly();
		$google['master']['summary'] = 'From Google';
		$this->assertTrue($converter->mergeIntoCalendar($vcal, $google, ['updated' => '2026-10-23T01:02:03Z'], [], 'Asia/Seoul', ['D:20261002']));

		[$master, $overrides] = $converter->localEvents($vcal, 'Asia/Seoul');
		$this->assertNotNull($master);
		$this->assertSame('From Google', (string)$master->SUMMARY);
		$this->assertArrayHasKey('D:20261002', $overrides);
		$this->assertSame('Keep me', (string)$overrides['D:20261002']->SUMMARY);
		$this->assertArrayNotHasKey('D:20261003', $overrides);
	}

	public function testPullStillDropsLocalExceptionsThatAreNotPending(): void {
		[$converter, $vcal, $google] = $this->localOnly();
		$this->assertTrue($converter->mergeIntoCalendar($vcal, $google, ['updated' => '2026-10-23T01:02:03Z'], [], 'Asia/Seoul'));

		[, $overrides] = $converter->localEvents($vcal, 'Asia/Seoul');
		$this->assertSame([], $overrides);
	}
}
