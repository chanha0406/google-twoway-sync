<?php

declare(strict_types=1);

namespace OCA\GoogleTwoWaySync\Tests\Unit;

use OCA\GoogleTwoWaySync\Service\ContactConverter;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

class ContactPhotoBytesTest extends TestCase {
	private function bytes(string $vcf): ?string {
		$card = Reader::read($vcf);
		$this->assertInstanceOf(VCard::class, $card);
		return (new ContactConverter())->photoBytes($card);
	}

	public function testVersion3EncodedPhoto(): void {
		$vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nPHOTO;ENCODING=b;TYPE=JPEG:" . base64_encode('rawjpeg') . "\r\nEND:VCARD\r\n";
		$this->assertSame('rawjpeg', $this->bytes($vcf));
	}

	public function testVersion4DataUri(): void {
		$vcf = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:A\r\nPHOTO:data:image/png;base64," . base64_encode('rawpng') . "\r\nEND:VCARD\r\n";
		$this->assertSame('rawpng', $this->bytes($vcf));
	}

	public function testRoundTripThroughSetPhoto(): void {
		$conv = new ContactConverter();
		$card = new VCard(['VERSION' => '3.0', 'FN' => 'A']);
		$conv->setPhoto($card, 'abc', 'image/jpeg');
		$this->assertSame('abc', $conv->photoBytes(Reader::read($card->serialize())));
	}

	public function testNoPhotoOrUrlGivesNull(): void {
		$this->assertNull($this->bytes("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nEND:VCARD\r\n"));
		$this->assertNull($this->bytes("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nPHOTO;VALUE=URI:https://x/y.jpg\r\nEND:VCARD\r\n"));
	}
}
