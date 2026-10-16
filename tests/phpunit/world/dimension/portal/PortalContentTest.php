<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use pocketmine\crafting\CraftingManager;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\StringToItemParser;
use pocketmine\scheduler\AsyncPool;
use pocketmine\world\format\io\GlobalItemDataHandlers;

final class PortalContentTest extends TestCase{

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testOnlyOneFunctionalEnderEyeInCreative() : void{
		$pool = $this->createMock(AsyncPool::class);
		$crafting = $this->createMock(CraftingManager::class);
		PortalContent::register($pool, $crafting);
		PortalContent::register($pool, $crafting);
		$count = 0;
		foreach(CreativeInventory::getInstance()->getAll() as $item){
			if(GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName() === ItemTypeNames::ENDER_EYE){
				++$count;
				self::assertInstanceOf(item\EnderEye::class, $item);
			}
		}
		self::assertSame(1, $count);
		self::assertEquals(PortalContent::enderEye(), StringToItemParser::getInstance()->parse("ender_eye"));
	}
}
