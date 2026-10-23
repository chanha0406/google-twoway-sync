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

	public function testPendingOverridesWinOverNewerGoogle(): void {
		$local = SyncDecision::pendingLocal(false, 10, 50, ['D:20261002'], true);
		$this->assertTrue($local['localChanged']);
		$this->assertGreaterThan(50, $local['localModified']);
		$decision = SyncDecision::decide(true, false, true, $local['localChanged'], 50, $local['localModified'], true, true);
		$this->assertSame(SyncReport::PUSH_UPDATE, $decision['action']);
	}

	public function testPendingOverridesStayPullWhenReadOnly(): void {
		$local = SyncDecision::pendingLocal(false, 10, 50, ['D:20261002'], false);
		$this->assertFalse($local['localChanged']);
		$this->assertSame(10, $local['localModified']);
		$decision = SyncDecision::decide(true, false, true, $local['localChanged'], 50, $local['localModified'], false, false);
		$this->assertSame(SyncReport::PULL_UPDATE, $decision['action']);
	}

	public function testNoPendingLeavesTheLocalChange(): void {
		$local = SyncDecision::pendingLocal(false, 10, 50, [], true);
		$this->assertSame(['localChanged' => false, 'localModified' => 10], $local);
	}

	public function testFailedOverridesStayPendingAndACleanPushClearsThem(): void {
		$pending = SyncDecision::overridePushState(['D:20261002', 'D:20261003']);
		$this->assertTrue($pending['keepEtag']);
		$this->assertSame('{"pendingOverrides":["D:20261002","D:20261003"]}', $pending['extra']);
		$this->assertSame(['D:20261002', 'D:20261003'], SyncDecision::pendingOverrideKeys($pending['extra']));

		$clean = SyncDecision::overridePushState([]);
		$this->assertFalse($clean['keepEtag']);
		$this->assertNull($clean['extra']);
		$this->assertSame([], SyncDecision::pendingOverrideKeys('{"photo":"x","retry":true}'));
		$this->assertSame(['D:20261002'], SyncDecision::pendingOverrideKeys('{"pendingOverrides":["D:20261002",1,""]}'));
	}
}
