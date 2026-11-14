<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\block\tile\Container;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\structure\piece\mineshaft\MineShaftCorridor;
use pocketmine\world\SimpleChunkManager;
use function getenv;
use function is_dir;
use function ksort;

/**
 * Mineshafts leave their chest minecarts (with the abandoned_mineshaft loot table and a seed) as pending entities of
 * the chunk, which the world spawns as {@link \pocketmine\entity\vehicle\MinecartChest}.
 */
final class MineshaftMinecartTest extends TestCase{
	private const SEED = 12345;

	public function testCorridorsHaveChestMinecarts() : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir . "/data/minecraft/worldgen/template_pool")){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no structure data (data format 5+)");
		}
		$generator = new VanillaGenerator(self::SEED, "data=$dir;backend=compiled;structure-positions=java;features=false");
		$manager = $generator->getStructureManager();
		self::assertNotNull($manager);
		//the mineshaft Java Edition 26.3 saves for seed 12345 (see StructureTest)
		$start = $manager->getStart("minecraft:mineshafts", -4, 1);
		self::assertNotNull($start);

		$chunks = [];
		foreach($start->pieces as $piece){
			if($piece instanceof MineShaftCorridor){
				for($cx = $piece->box->minX >> 4; $cx <= $piece->box->maxX >> 4; ++$cx){
					for($cz = $piece->box->minZ >> 4; $cz <= $piece->box->maxZ >> 4; ++$cz){
						$chunks["$cx:$cz"] = [$cx, $cz];
					}
				}
			}
		}
		ksort($chunks);
		self::assertNotEmpty($chunks);

		$world = new SimpleChunkManager(-64, 320);
		$generated = [];
		$carts = [];
		foreach($chunks as [$chunkX, $chunkZ]){
			for($x = $chunkX - 1; $x <= $chunkX + 1; ++$x){
				for($z = $chunkZ - 1; $z <= $chunkZ + 1; ++$z){
					if(!isset($generated["$x:$z"])){
						$generated["$x:$z"] = true;
						$world->setChunk($x, $z, new Chunk([], false));
						$generator->generateChunk($world, $x, $z);
					}
				}
			}
			$generator->populateChunk($world, $chunkX, $chunkZ);
			$chunk = $world->getChunk($chunkX, $chunkZ);
			self::assertNotNull($chunk);
			foreach($chunk->getPendingEntityNBT() as $nbt){
				if($nbt->getString(Chunk::PENDING_ENTITY_IDENTIFIER) === "minecraft:chest_minecart"){
					$carts[] = $nbt;
				}
			}
			if($carts !== []){
				break;
			}
		}
		self::assertNotEmpty($carts, "no chest minecart in the corridors of the mineshaft");
		foreach($carts as $nbt){
			self::assertSame("loot_tables/chests/abandoned_mineshaft.json", $nbt->getString(Container::TAG_LOOT_TABLE));
			self::assertNotNull($nbt->getTag(Container::TAG_LOOT_TABLE_SEED_LONG));
		}
	}
}
