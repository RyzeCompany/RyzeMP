<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator\end\object;

use LogicException;
use PHPUnit\Framework\TestCase;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\world\dimension\generator\object\BlockManager;
use pocketmine\world\dimension\portal\PortalContent;
use pocketmine\world\dimension\portal\block\EndPortal;
use pocketmine\world\SimpleChunkManager;

final class ExitPortalTest extends TestCase{

	public function testActiveExitPortalContainsTwentyPortalBlocks() : void{
		try{
			PortalContent::endPortal();
		}catch(LogicException){
			PortalContent::registerBlock(BlockTypeIds::newId());
		}

		$manager = new BlockManager(new SimpleChunkManager(0, 256));
		(new ExitPortal())->generate($manager, 0, 64, 0);

		$portalBlocks = 0;
		$centerBedrock = false;
		foreach($manager->getBlocks() as $placed){
			if($placed->y === 64 && $placed->block instanceof EndPortal){
				++$portalBlocks;
			}
			if($placed->x === 0 && $placed->y === 64 && $placed->z === 0 && $placed->block->getTypeId() === VanillaBlocks::BEDROCK()->getTypeId()){
				$centerBedrock = true;
			}
		}

		self::assertSame(20, $portalBlocks);
		self::assertTrue($centerBedrock);
	}
}
