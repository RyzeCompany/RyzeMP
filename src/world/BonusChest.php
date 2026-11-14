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

namespace pocketmine\world;

use pocketmine\block\Leaves;
use pocketmine\block\Liquid;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\utils\Random;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\data\BedrockWorldData;
use function count;
use function range;

/**
 * The bonus chest of the "bonus chest" world option: a chest on the surface of the spawn chunk, filled from
 * chests/spawn_bonus_chest when it is first opened, with torches around it (Java BonusChestFeature).
 *
 * @internal
 */
final class BonusChest{
	public const LOOT_TABLE = "minecraft:chests/spawn_bonus_chest";

	/** Bedrock level.dat flags: the world wants a bonus chest / it was placed (or there was no room for it) */
	public const TAG_ENABLED = "bonusChestEnabled";
	public const TAG_SPAWNED = "bonusChestSpawned";

	private function __construct(){
		//NOOP
	}

	/**
	 * Called when a world is loaded or generated: places the bonus chest if the world asks for one that is not placed
	 * yet (Bedrock level.dat flags), so a server stopped before the spawn chunk was ready tries again on the next load.
	 * Formats without these flags only get the chest when they are created with the option ($newWithBonusChest).
	 */
	public static function onWorldLoad(World $world, bool $newWithBonusChest) : void{
		if($world->getDimension() !== Dimension::OVERWORLD){
			return;
		}
		$data = $world->getProvider()->getWorldData();
		if($data instanceof BedrockWorldData){
			$tag = $data->getCompoundTag();
			if($tag->getByte(self::TAG_ENABLED, 0) === 0 || $tag->getByte(self::TAG_SPAWNED, 0) !== 0){
				return;
			}
		}elseif(!$newWithBonusChest){
			return;
		}
		self::schedule($world);
	}

	/**
	 * Orders the population of the spawn chunk and places the bonus chest in it once it is ready.
	 */
	private static function schedule(World $world) : void{
		$spawn = $world->getSpawnLocation();
		$world->orderChunkPopulation($spawn->getFloorX() >> Chunk::COORD_BIT_SIZE, $spawn->getFloorZ() >> Chunk::COORD_BIT_SIZE, null)->onCompletion(
			static function() use ($world, $spawn) : void{
				if(!$world->isLoaded()){
					return;
				}
				$pos = self::place($world, $spawn->getFloorX(), $spawn->getFloorZ(), new Random($world->getSeed()));
				if($pos === null){
					$world->getLogger()->debug("No room for the bonus chest in the spawn chunk");
				}
				//the placement only depends on the seed and the spawn chunk: no room now means no room on the next load
				$data = $world->getProvider()->getWorldData();
				if($data instanceof BedrockWorldData){
					$data->getCompoundTag()->setByte(self::TAG_SPAWNED, 1);
				}
			},
			static function() : void{
				//NOOP: the world was unloaded before the chunk was ready; the flag stays unset and the next load retries
			}
		);
	}

	/**
	 * Places the bonus chest on the surface of the chunk containing the given column, on the first suitable column in
	 * a random order. Returns where it was placed, or null if no column has room for it.
	 */
	public static function place(World $world, int $x, int $z, Random $random) : ?Vector3{
		$minX = $x & ~Chunk::COORD_MASK;
		$minZ = $z & ~Chunk::COORD_MASK;
		if($world->loadChunk($minX >> Chunk::COORD_BIT_SIZE, $minZ >> Chunk::COORD_BIT_SIZE) === null){
			return null;
		}
		$xs = self::shuffled($minX, $minX + Chunk::EDGE_LENGTH - 1, $random);
		$zs = self::shuffled($minZ, $minZ + Chunk::EDGE_LENGTH - 1, $random);
		foreach($xs as $chestX){
			foreach($zs as $chestZ){
				$chestY = self::surfaceY($world, $chestX, $chestZ);
				if($chestY === null || $chestY >= $world->getMaxY()){
					continue;
				}
				$replaced = $world->getBlockAt($chestX, $chestY, $chestZ);
				if(count($replaced->getCollisionBoxes()) > 0 || $replaced instanceof Liquid){
					continue;
				}
				$pos = new Vector3($chestX, $chestY, $chestZ);
				$world->setBlock($pos, VanillaBlocks::CHEST());
				$tile = $world->getTile($pos);
				if($tile instanceof TileChest){
					$tile->setLootTable(self::LOOT_TABLE, $random->nextInt());
				}
				foreach(Facing::HORIZONTAL as $face){
					self::placeTorch($world, $pos->getSide($face));
				}
				return $pos;
			}
		}
		return null;
	}

	/**
	 * Y above the highest block of the column that blocks motion, leaves excepted (Java MOTION_BLOCKING_NO_LEAVES
	 * heightmap), or null if the column is empty.
	 */
	private static function surfaceY(World $world, int $x, int $z) : ?int{
		$top = $world->getHighestBlockAt($x, $z);
		if($top === null){
			return null;
		}
		$minY = $world->getMinY();
		for($y = $top; $y >= $minY; --$y){
			$block = $world->getBlockAt($x, $y, $z);
			if(!$block instanceof Leaves && ($block instanceof Liquid || count($block->getCollisionBoxes()) > 0)){
				return $y + 1;
			}
		}
		return null;
	}

	private static function placeTorch(World $world, Vector3 $pos) : void{
		if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) || !$world->isInLoadedTerrain($pos)){
			return;
		}
		$replaced = $world->getBlock($pos);
		//Java overwrites whatever is there; only free spots are used here so no terrain is cut
		if(!$replaced->canBeReplaced() || $replaced instanceof Liquid){
			return;
		}
		if(!$world->getBlock($pos->down())->getSupportType(Facing::UP)->hasCenterSupport()){
			return;
		}
		$world->setBlock($pos, VanillaBlocks::TORCH()->setFacing(Facing::UP));
	}

	/**
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	private static function shuffled(int $min, int $max, Random $random) : array{
		$values = range($min, $max);
		for($i = count($values) - 1; $i > 0; --$i){
			$j = $random->nextBoundedInt($i + 1);
			[$values[$i], $values[$j]] = [$values[$j], $values[$i]];
		}
		return $values;
	}
}
