<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Tests\Unit;

use OCA\GoogleTwoWaySync\Service\CalendarTrash;
use PHPUnit\Framework\TestCase;

class CalendarTrashTest extends TestCase {
	public function testNextcloudDeletionTime(): void {
		$this->assertTrue(CalendarTrash::isInTrash([
			'{http://nextcloud.com/ns}deleted-at' => 1700000000,
		]));
	}

	public function testOwncloudDeletionTime(): void {
		$this->assertTrue(CalendarTrash::isInTrash([
			'{http://owncloud.org/ns}deleted-at' => 1700000000,
		]));
	}

	public function testMissingOrNullIsLive(): void {
		$this->assertFalse(CalendarTrash::isInTrash([]));
		$this->assertFalse(CalendarTrash::isInTrash([
			'{http://nextcloud.com/ns}deleted-at' => null,
		]));
	}
}
