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
}
