<?php

declare(strict_types=1);

namespace pocketmine\item;

use PHPUnit\Framework\TestCase;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\generator\vanilla\feature\ArchaeologyContent;
use function strtolower;

/** Round-trip all VanillaMine pottery sherd variants in RyzeMP's item registry. */
final class PotterySherdTest extends TestCase{
	public function testAllVanillaSherdsSerializeAndDeserialize() : void{
		ArchaeologyContent::registerSherds();
		self::assertCount(23, PotterySherdType::cases());
		$serializer = GlobalItemDataHandlers::getSerializer();
		$deserializer = GlobalItemDataHandlers::getDeserializer();
		foreach(PotterySherdType::cases() as $type){
			$item = (new PotterySherd(new ItemIdentifier(ItemTypeIds::POTTERY_SHERD), "Pottery Sherd"))->setSherdType($type);
			$encoded = $serializer->serializeType($item);
			self::assertSame("minecraft:" . strtolower($type->name) . "_pottery_sherd", $encoded->getName());
			$restored = $deserializer->deserializeType($encoded);
			self::assertInstanceOf(PotterySherd::class, $restored);
			self::assertSame($type, $restored->getSherdType());
			self::assertSame($item->getTypeId(), $restored->getTypeId());
			self::assertSame($item->getName(), $restored->getName());
		}
	}
}
