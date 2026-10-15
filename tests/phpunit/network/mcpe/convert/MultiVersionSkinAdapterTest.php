<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\entity\InvalidSkinException;
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;

final class MultiVersionSkinAdapterTest extends TestCase{

	/** @return array<string, array{int, int, int, int}> */
	public static function clientSkinSizes() : array{
		return [
			'legacy 64x32' => [64, 32, 64, 32],
			'classic 64x64' => [64, 64, 64, 64],
			'HD 128x128' => [128, 128, 128, 128],
			'Windows 254x254' => [254, 254, 128, 128],
			'Windows 256x256' => [256, 256, 128, 128]
		];
	}

	#[DataProvider('clientSkinSizes')]
	public function testValidWindowsAndLegacySkinsAreAcceptedAndBroadcastSafely(int $width, int $height, int $expectedWidth, int $expectedHeight) : void{
		$firstPixel = "\x11\x22\x33\xff";
		$lastPixel = "\xaa\xbb\xcc\xdd";
		$imageData = str_repeat("\x01\x02\x03\xff", $width * $height);
		$imageData = substr_replace($imageData, $firstPixel, 0, 4);
		$sourceX = intdiv(($expectedWidth - 1) * $width, $expectedWidth);
		$sourceY = intdiv(($expectedHeight - 1) * $height, $expectedHeight);
		$imageData = substr_replace($imageData, $lastPixel, ($sourceY * $width + $sourceX) * 4, 4);

		$incoming = new SkinData(
			'windows-skin',
			'',
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			new SkinImage($height, $width, $imageData),
			geometryData: '{}'
		);

		$adapter = new LegacySkinAdapter();
		$skin = $adapter->fromSkinData($incoming);
		self::assertSame($expectedWidth * $expectedHeight * 4, strlen($skin->getSkinData()));
		self::assertSame($firstPixel, substr($skin->getSkinData(), 0, 4));
		self::assertSame($lastPixel, substr($skin->getSkinData(), -4));

		$outgoing = $adapter->toSkinData($skin);
		self::assertSame($expectedWidth, $outgoing->getSkinImage()->getWidth());
		self::assertSame($expectedHeight, $outgoing->getSkinImage()->getHeight());
	}

	public function testUnsupportedOversizedSkinIsStillRejected() : void{
		$incoming = new SkinData(
			'invalid-skin',
			'',
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			new SkinImage(512, 512, str_repeat("\x00", 512 * 512 * 4)),
			geometryData: '{}'
		);

		$this->expectException(InvalidSkinException::class);
		(new LegacySkinAdapter())->fromSkinData($incoming);
	}

	/** @return array<string, array{int}> */
	public static function supportedProtocols() : array{
		return [
			'26.30' => [ProtocolInfo::PROTOCOL_1_26_30],
			'26.40 and 26.44' => [ProtocolInfo::PROTOCOL_1_26_40],
			'26.45' => [ProtocolInfo::PROTOCOL_1_26_45]
		];
	}

	#[DataProvider('supportedProtocols')]
	public function testEmptyGeometryIsReplacedWithValidJsonBeforeBroadcast(int $protocolId) : void{
		$skin = new Skin('standard-skin', str_repeat("\xff", 64 * 64 * 4));
		$networkSkin = (new LegacySkinAdapter())->toSkinData($skin);
		self::assertSame('{}', $networkSkin->getGeometryData());

		$out = new ByteBufferWriter();
		CommonTypes::putSkin($out, $networkSkin, $protocolId);
		$decoded = CommonTypes::getSkin(new ByteBufferReader($out->getData()), $protocolId);
		self::assertSame('{}', $decoded->getGeometryData());
	}
	public function testLegacyPersonaFallsBackToClassicFor2193() : void{
		$imageData = str_repeat("\x44\x55\x66\xff", 256 * 256);
		$imageData = substr_replace($imageData, "\x11\x22\x33\xff", 0, 4);
		$imageData = substr_replace($imageData, "\xaa\xbb\xcc\xdd", ((254 * 256) + 254) * 4, 4);

		$incoming = new SkinData(
			'legacy-persona',
			'',
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			new SkinImage(256, 256, $imageData),
			geometryData: '{}',
			persona: true,
			trustedSkinFlag: "true",
			profileHash: str_repeat("a", 44)
		);

		$adapter = new LegacySkinAdapter();
		$skin = $adapter->fromSkinDataForProtocol($incoming, ProtocolInfo::PROTOCOL_1_26_45);
		$outgoing = $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_26_50);

		self::assertFalse($outgoing->isPersona());
		self::assertSame("false", $outgoing->getTrustedSkinFlag());
		self::assertSame("", $outgoing->getProfileHash());
		self::assertSame(128, $outgoing->getSkinImage()->getWidth());
		self::assertSame(128, $outgoing->getSkinImage()->getHeight());
		self::assertSame(128 * 128 * 4, strlen($outgoing->getSkinImage()->getData()));
		self::assertSame("\x11\x22\x33\xff", substr($outgoing->getSkinImage()->getData(), 0, 4));
		self::assertSame("\xaa\xbb\xcc\xdd", substr($outgoing->getSkinImage()->getData(), -4));
	}

	public function testLegacyPersonaIsPreservedForLegacyRecipient() : void{
		$incoming = new SkinData(
			'legacy-persona',
			'',
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			new SkinImage(256, 256, str_repeat("\x44\x55\x66\xff", 256 * 256)),
			geometryData: '{}',
			persona: true,
			trustedSkinFlag: "true",
			profileHash: str_repeat("a", 44)
		);

		$adapter = new LegacySkinAdapter();
		$skin = $adapter->fromSkinDataForProtocol($incoming, ProtocolInfo::PROTOCOL_1_26_45);

		self::assertSame($incoming, $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_26_45));
	}

	public function testModernPersonaIsPreservedFor2193Recipient() : void{
		$incoming = new SkinData(
			'modern-persona',
			'',
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			new SkinImage(256, 256, str_repeat("\x44\x55\x66\xff", 256 * 256)),
			geometryData: '{}',
			persona: true,
			trustedSkinFlag: "true",
			profileHash: str_repeat("b", 44)
		);

		$adapter = new LegacySkinAdapter();
		$skin = $adapter->fromSkinDataForProtocol($incoming, ProtocolInfo::PROTOCOL_1_26_50);

		self::assertSame($incoming, $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_26_50));
	}

	public function testLegacyOverrideSignatureRemainsCompatible() : void{
		$adapter = new class extends LegacySkinAdapter{
			public function toSkinData(Skin $skin) : SkinData{
				return parent::toSkinData($skin);
			}

			public function fromSkinData(SkinData $data) : Skin{
				return parent::fromSkinData($data);
			}
		};

		$skin = new Skin('plugin-compatible', str_repeat("\xff", 64 * 64 * 4));
		$outgoing = $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_26_50);

		self::assertSame(64, $outgoing->getSkinImage()->getWidth());
		self::assertSame(64, $outgoing->getSkinImage()->getHeight());
	}

	public function testUnsupportedPersonaImageFallsBackDeterministically() : void{
		$incoming = new SkinData(
			'odd-persona',
			'',
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			new SkinImage(96, 96, str_repeat("\x01\x02\x03\xff", 96 * 96)),
			geometryData: '{}',
			persona: true
		);

		$adapter = new LegacySkinAdapter();
		$skin = $adapter->fromSkinDataForProtocol($incoming, ProtocolInfo::PROTOCOL_1_26_45);
		$outgoing = $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_26_50);

		self::assertFalse($outgoing->isPersona());
		self::assertSame(64, $outgoing->getSkinImage()->getWidth());
		self::assertSame(64, $outgoing->getSkinImage()->getHeight());
		self::assertSame(str_repeat("\x7f\x7f\x7f\xff", 64 * 64), $outgoing->getSkinImage()->getData());
	}

	/** @return array<string, array{int, int, bool}> */
	public static function personaProtocolMatrix() : array{
		return [
			'1.20.0 -> 1.20.80 same legacy family' => [ProtocolInfo::PROTOCOL_1_20_0, ProtocolInfo::PROTOCOL_1_20_80, false],
			'1.21.80 -> 1.26.30 same legacy family' => [ProtocolInfo::PROTOCOL_1_21_80, ProtocolInfo::PROTOCOL_1_26_30, false],
			'1.26.30 -> 1.26.40 crosses legacy to 2168 family' => [ProtocolInfo::PROTOCOL_1_26_30, ProtocolInfo::PROTOCOL_1_26_40, true],
			'1.26.40 -> 1.26.45 same 2168/2169 family' => [ProtocolInfo::PROTOCOL_1_26_40, ProtocolInfo::PROTOCOL_1_26_45, false],
			'1.26.45 -> 1.26.52 crosses into 2193 family' => [ProtocolInfo::PROTOCOL_1_26_45, ProtocolInfo::PROTOCOL_1_26_50, true],
			'1.26.52 -> 1.26.45 crosses backwards out of 2193' => [ProtocolInfo::PROTOCOL_1_26_50, ProtocolInfo::PROTOCOL_1_26_45, true],
			'1.26.52 -> 1.26.30 crosses modern to legacy' => [ProtocolInfo::PROTOCOL_1_26_50, ProtocolInfo::PROTOCOL_1_26_30, true],
			'1.26.52 -> 1.26.52 same modern family' => [ProtocolInfo::PROTOCOL_1_26_50, ProtocolInfo::PROTOCOL_1_26_50, false],
		];
	}

	#[DataProvider('personaProtocolMatrix')]
	public function testPersonaCompatibilityFamilies(int $sourceProtocol, int $targetProtocol, bool $expectClassicFallback) : void{
		$incoming = new SkinData(
			'persona-matrix',
			'',
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			new SkinImage(256, 256, str_repeat("\x21\x43\x65\xff", 256 * 256)),
			geometryData: '{}',
			persona: true,
			trustedSkinFlag: "true",
			profileHash: str_repeat("c", 44)
		);

		$adapter = new LegacySkinAdapter();
		$skin = $adapter->fromSkinDataForProtocol($incoming, $sourceProtocol);
		$outgoing = $adapter->toSkinDataForProtocol($skin, $targetProtocol);

		self::assertSame(!$expectClassicFallback, $outgoing->isPersona());
		if($expectClassicFallback){
			self::assertSame("false", $outgoing->getTrustedSkinFlag());
			self::assertSame("", $outgoing->getProfileHash());
			self::assertContains($outgoing->getSkinImage()->getWidth(), [64, 128]);
			self::assertSame($outgoing->getSkinImage()->getWidth(), $outgoing->getSkinImage()->getHeight());
		}else{
			self::assertSame($incoming, $outgoing);
		}
	}


	public function testModernClassicSkinRetainsLoginMetadataFor2193Viewers() : void{
		$incoming = new SkinData(
			"hd-custom-2193",
			"playfab-identity",
			'{"geometry":{"default":"geometry.humanoid.customSlim"}}',
			new SkinImage(64, 64, str_repeat("\x21\x43\x65\xff", 64 * 64)),
			geometryData: '{"format_version":"1.12.0","minecraft:geometry":[]}',
			geometryDataEngineVersion: "1.26.52",
			fullSkinId: "modern-full-skin-identifier",
			armSize: SkinData::ARM_SIZE_SLIM,
			skinColor: "#FFFFFFFF",
			isVerified: false,
			trustedSkinFlag: "false",
			profileHash: "modern-profile-hash"
		);

		$adapter = new LegacySkinAdapter();
		$skin = $adapter->fromSkinDataForProtocol($incoming, ProtocolInfo::PROTOCOL_1_26_50);
		$after = $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_26_50);
		self::assertSame($incoming, $after, "All fields from the login must survive for same-protocol viewers");
		self::assertSame("modern-profile-hash", $after->getProfileHash());
		self::assertSame("modern-full-skin-identifier", $after->getFullSkinId());
		self::assertSame(SkinData::ARM_SIZE_SLIM, $after->getArmSize());

		$out = new ByteBufferWriter();
		CommonTypes::putSkin($out, $after, ProtocolInfo::PROTOCOL_1_26_50);
		$decoded = CommonTypes::getSkin(new ByteBufferReader($out->getData()), ProtocolInfo::PROTOCOL_1_26_50);
		self::assertSame("modern-profile-hash", $decoded->getProfileHash());
		self::assertSame("modern-full-skin-identifier", $decoded->getFullSkinId());
	}

	public function test2193SkinIsDowngradedFor121114WithoutMutating2193Original() : void{
		$incoming = new SkinData(
			"modern-classic",
			"playfab-identity",
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			new SkinImage(64, 64, str_repeat("\x45\x55\x65\xff", 64 * 64)),
			geometryData: "{}",
			geometryDataEngineVersion: "1.26.52",
			fullSkinId: "source-full-id",
			isVerified: true,
			profileHash: "modern-profile-hash"
		);
		$adapter = new LegacySkinAdapter();
		$skin = $adapter->fromSkinDataForProtocol($incoming, ProtocolInfo::PROTOCOL_1_26_50);
		$legacy = $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_21_111);

		self::assertNotSame($incoming, $legacy);
		self::assertSame("1.20.0", $legacy->getGeometryDataEngineVersion());
		self::assertSame("", $legacy->getProfileHash());
		self::assertSame($incoming->getSkinImage()->getData(), $legacy->getSkinImage()->getData());

		$out = new ByteBufferWriter();
		CommonTypes::putSkin($out, $legacy, ProtocolInfo::PROTOCOL_1_21_111);
		$decoded = CommonTypes::getSkin(new ByteBufferReader($out->getData()), ProtocolInfo::PROTOCOL_1_21_111);
		self::assertSame("1.20.0", $decoded->getGeometryDataEngineVersion());
		self::assertSame($incoming, $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_26_50));
	}

	public function testPluginSkinUsesBackwardCompatibleEngineVersionForOldClients() : void{
		$adapter = new LegacySkinAdapter();
		$skin = new Skin("plugin-classic", str_repeat("\x22\x33\x44\xff", 64 * 64));
		$skinData = $adapter->toSkinDataForProtocol($skin, ProtocolInfo::PROTOCOL_1_21_111);

		self::assertSame("1.20.0", $skinData->getGeometryDataEngineVersion());
		$out = new ByteBufferWriter();
		CommonTypes::putSkin($out, $skinData, ProtocolInfo::PROTOCOL_1_21_111);
		$decoded = CommonTypes::getSkin(new ByteBufferReader($out->getData()), ProtocolInfo::PROTOCOL_1_21_111);
		self::assertSame("1.20.0", $decoded->getGeometryDataEngineVersion());
	}

}
