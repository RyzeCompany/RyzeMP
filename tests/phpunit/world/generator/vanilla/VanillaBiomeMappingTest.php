<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;

/**
 * Prevents mapping upstream Java worldgen biomes into numeric values that
 * RyzeMP's own BedrockData cannot serialize. This tests the current data
 * mapping, not the full old-client protocol downgrade matrix.
 */
final class VanillaBiomeMappingTest extends TestCase{

	public function testEveryMappedJavaBiomeHasRyzeBedrockDataRepresentation() : void{
		$map = LegacyBiomeIdToStringIdMap::getInstance();
		foreach(BiomeMapping::getJavaNames() as $name){
			$bedrock = BiomeMapping::toBedrock($name);
			self::assertNotNull($map->legacyToString($bedrock), "Mapped Java biome $name has unsupported Bedrock ID $bedrock");
		}
	}

	public function testUnknownBiomeFailsInsteadOfLeakingInvalidProtocolID() : void{
		$this->expectException(\InvalidArgumentException::class);
		BiomeMapping::toBedrock("minecraft:future_dimension_biome_unknown");
	}
}
