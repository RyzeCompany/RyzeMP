<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\types\login\clientdata\ClientData;

final class MultiVersionClientDataTest extends TestCase{

	public function testModernProfileHashIsMappedWithoutUnknownPropertyWarnings() : void{
		$unexpectedProperties = [];
		$mapper = new \JsonMapper();
		$mapper->undefinedPropertyHandler = static function(object $object, string $name, mixed $value) use (&$unexpectedProperties) : void{
			$unexpectedProperties[] = $name;
		};

		$clientData = $mapper->map((object) ['ProfileHash' => 'android-profile-hash'], new ClientData());

		self::assertSame('android-profile-hash', $clientData->ProfileHash);
		self::assertSame([], $unexpectedProperties);
	}

	public function testLegacyClientMayOmitProfileHash() : void{
		$mapper = new \JsonMapper();
		$clientData = $mapper->map((object) [], new ClientData());

		self::assertSame('', $clientData->ProfileHash);
	}
}
