<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Tests\Unit;

use OCA\GoogleTwoWaySync\Service\SyncDecision;
use OCA\GoogleTwoWaySync\Service\SyncReport;
use PHPUnit\Framework\TestCase;

class SyncDecisionTest extends TestCase {
	/**
	 * exists, deleted, googleChanged, localChanged, googleUpdated, localModified, writable, itemWritable => action
	 */
	public static function cases(): array {
		return [
			'gone on both' => [false, true, true, false, 0, 0, true, true, 'forget'],
			'local deleted, Google same' => [false, false, false, false, 0, 0, true, true, SyncReport::PUSH_DELETE],
			'local deleted, Google changed' => [false, false, true, false, 0, 0, true, true, SyncReport::PULL_CREATE],
			'local deleted in read-only' => [false, false, false, false, 0, 0, false, false, SyncReport::PULL_CREATE],
			'Google deleted, local same' => [true, true, true, false, 0, 0, true, true, SyncReport::PULL_DELETE],
			'Google deleted, local changed' => [true, true, true, true, 0, 0, true, true, SyncReport::PUSH_CREATE],
			'Google deleted, local changed, read-only' => [true, true, true, true, 0, 0, false, false, SyncReport::PULL_DELETE],
			'both changed, Google newer' => [true, false, true, true, 20, 10, true, true, SyncReport::PULL_UPDATE],
			'both changed, local newer' => [true, false, true, true, 10, 20, true, true, SyncReport::PUSH_UPDATE],
			'both changed, read-only item' => [true, false, true, true, 10, 20, true, false, SyncReport::PULL_UPDATE],
			'Google changed' => [true, false, true, false, 0, 0, true, true, SyncReport::PULL_UPDATE],
			'local changed' => [true, false, false, true, 0, 0, true, true, SyncReport::PUSH_UPDATE],
			'local changed, read-only reverts' => [true, false, false, true, 0, 0, true, false, SyncReport::PULL_UPDATE],
			'nothing changed' => [true, false, false, false, 0, 0, true, true, 'none'],
		];
	}

	/**
	 * @dataProvider cases
	 */
	public function testDecide(bool $exists, bool $deleted, bool $googleChanged, bool $localChanged, int $gUpdated, int $lModified, bool $writable, bool $itemWritable, string $action): void {
		$this->assertSame($action, SyncDecision::decide($exists, $deleted, $googleChanged, $localChanged, $gUpdated, $lModified, $writable, $itemWritable)['action']);
	}

	public function testNotes(): void {
		$this->assertSame('Restored because the calendar is read-only', SyncDecision::decide(false, false, false, false, 0, 0, false, false)['note']);
		$this->assertSame('', SyncDecision::decide(true, false, true, false, 0, 0)['note']);
	}
}
