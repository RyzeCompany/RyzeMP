<?php

declare(strict_types=1);

namespace pocketmine\world\weather;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;

/** Uses supported Bedrock biome mapping, never hard-coded numeric IDs. */
final class BiomeClimateTest extends TestCase{
	private function biomeId(string $name) : int{
		foreach(LegacyBiomeIdToStringIdMap::getInstance()->getLegacyToStringMap() as $id => $biomeName){
			if($name === $biomeName){ return $id; }
		}
		self::fail("Missing Bedrock biome $name");
	}

	public function testDryTemperateColdBiomes() : void{
		self::assertSame(Precipitation::NONE, BiomeClimate::getPrecipitation($this->biomeId("desert"), 80));
		self::assertSame(Precipitation::RAIN, BiomeClimate::getPrecipitation($this->biomeId("plains"), 80));
		self::assertSame(Precipitation::SNOW, BiomeClimate::getPrecipitation($this->biomeId("ice_plains"), 80));
	}

	public function testAltitudeCooling() : void{
		$id = $this->biomeId("extreme_hills");
		self::assertLessThan(BiomeClimate::getTemperatureAt($id, 80), BiomeClimate::getTemperatureAt($id, 180));
	}

	public function testUnknownIdDoesNotInventPrecipitation() : void{
		self::assertSame(Precipitation::NONE, BiomeClimate::getPrecipitation(999999, 80));
	}
}
