<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Tests\Unit;

use OCA\GoogleTwoWaySync\Service\ContactConverter;
use PHPUnit\Framework\TestCase;

class ContactPhotoUrlTest extends TestCase {
	public function testThumbnailSizeIsRaised(): void {
		$this->assertSame('https://lh3.googleusercontent.com/contacts/AB=s0', ContactConverter::fullSizePhotoUrl('https://lh3.googleusercontent.com/contacts/AB=s100'));
		$this->assertSame('https://x/y=s0', ContactConverter::fullSizePhotoUrl('https://x/y=s100-c'));
	}

	public function testUrlWithoutSizeStaysAsIs(): void {
		$this->assertSame('https://x/y?z=1', ContactConverter::fullSizePhotoUrl('https://x/y?z=1'));
	}
}
