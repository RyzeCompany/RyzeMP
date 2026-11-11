<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;

final class WaterFreezingRulesTest extends TestCase{
	private function biome(string $name) : int{
		foreach(LegacyBiomeIdToStringIdMap::getInstance()->getLegacyToStringMap() as $id => $idName){
			if($idName === $name){ return $id; }
		}
		self::fail("Missing Bedrock biome " . $name);
	}

	public function testColdSourceAndLightRestrictions() : void{
		$water = VanillaBlocks::WATER();
		$cold = $this->biome("ice_plains");
		$warm = $this->biome("plains");
		self::assertTrue(Weather::canFreezeSourceWater($water, $cold, 80, 0));
		self::assertFalse(Weather::canFreezeSourceWater($water, $warm, 80, 0));
		self::assertFalse(Weather::canFreezeSourceWater($water, $cold, 80, 12));
	}
}
