<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Tests\Unit;

use OCA\GoogleTwoWaySync\Service\Appearance;
use PHPUnit\Framework\TestCase;

class AppearanceTest extends TestCase {
	public function testFollowsRemoteWhileLocalIsUntouched(): void {
		$d = Appearance::decide('Old', '#111111', 'Old', '#111111', 'New', '#222222');
		$this->assertSame('New', $d['name']);
		$this->assertSame('#222222', $d['color']);
		$this->assertSame('New', $d['appliedName']);
		$this->assertSame('#222222', $d['appliedColor']);
		$this->assertTrue($d['changed']);
	}

	public function testKeepsLocalRename(): void {
		$d = Appearance::decide('Mine', '#333333', 'Old', '#111111', 'New', '#222222');
		$this->assertNull($d['name']);
		$this->assertNull($d['color']);
		$this->assertFalse($d['changed']);
	}

	public function testFirstRunSeedsWithRemoteAndKeepsLocalRename(): void {
		$d = Appearance::decide('Mine', '#333333', null, null, 'Google', '#222222');
		$this->assertNull($d['name']);
		$this->assertNull($d['color']);
		$this->assertSame('Google', $d['appliedName']);
		$this->assertSame('#222222', $d['appliedColor']);
		$this->assertTrue($d['changed']);
	}

	public function testFirstRunWithMatchingValuesWritesNothing(): void {
		$d = Appearance::decide('Google', '#222222', null, null, 'Google', '#222222');
		$this->assertNull($d['name']);
		$this->assertNull($d['color']);
		$this->assertTrue($d['changed']);
	}

	public function testForceOverwritesLocal(): void {
		$d = Appearance::decide('Mine', '#333333', 'Old', '#111111', 'New', '#222222', true, true);
		$this->assertSame('New', $d['name']);
		$this->assertSame('#222222', $d['color']);
		$this->assertSame('New', $d['appliedName']);
	}

	public function testLocalMatchedByHandUpdatesApplied(): void {
		$d = Appearance::decide('New', '#222222', 'Old', '#111111', 'New', '#222222');
		$this->assertNull($d['name']);
		$this->assertNull($d['color']);
		$this->assertSame('New', $d['appliedName']);
		$this->assertSame('#222222', $d['appliedColor']);
		$this->assertTrue($d['changed']);
	}

	public function testEmptyRemoteColorLeavesLocalColor(): void {
		$d = Appearance::decide('A', '#333333', 'A', '#111111', 'A', '', false, true);
		$this->assertNull($d['color']);
		$this->assertSame('#111111', $d['appliedColor']);
		$this->assertFalse($d['changed']);
	}

	public function testCustomized(): void {
		$this->assertSame(['nameCustomized' => true, 'colorCustomized' => false], Appearance::customized('Mine', '#222222', 'New', '#222222'));
		$this->assertSame(['nameCustomized' => false, 'colorCustomized' => false], Appearance::customized('New', '#333333', 'New', ''));
	}

	public function testNormalizeColor(): void {
		$this->assertSame('#aabbcc', Appearance::normalizeColor(' #ABC '));
		$this->assertSame('#112233', Appearance::normalizeColor('#112233FF'));
		$this->assertSame('', Appearance::normalizeColor(null));
		$this->assertSame('red', Appearance::normalizeColor('Red'));
	}
}
