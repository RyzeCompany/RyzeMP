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

use pocketmine\block\BaseFire;
use pocketmine\block\Bed;
use pocketmine\block\Block;
use pocketmine\block\Cactus;
use pocketmine\block\Campfire;
use pocketmine\block\EndGateway;
use pocketmine\block\EndPortal;
use pocketmine\block\Lava;
use pocketmine\block\Leaves;
use pocketmine\block\Liquid;
use pocketmine\block\Magma;
use pocketmine\block\PowderSnow;
use pocketmine\block\RespawnAnchor;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\Trapdoor;
use pocketmine\block\WitherRose;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\utils\Random;
use pocketmine\world\format\Chunk;
use pocketmine\world\sound\RespawnAnchorDepleteSound;
use function cos;
use function count;
use function deg2rad;
use function is_int;
use function mt_rand;
use function sin;

/**
 * Where players stand up when they respawn: on a free spot next to their bed or respawn anchor (Java
 * BedBlock/RespawnAnchorBlock.findStandUpPosition and DismountHelper), or on a random surface spot within the
 * spawnradius game rule around the world spawn.
 *
 * Positions use the block corner convention of {@link World::getSafeSpawn()}: whole X/Z coordinates of the block the
 * player stands in (the respawn code adds 0.5) and the exact feet height.
 *
 * @internal
 */
final class SpawnLocator{
	/** The spawn point was set by a plugin or a command: it is used as it is. */
	public const SPAWN_BLOCK_NONE = 0;
	/** The spawn point was set by a bed: it only works while the bed is there. */
	public const SPAWN_BLOCK_BED = 1;
	/** The spawn point was set by a respawn anchor: it only works while the anchor has charges. */
	public const SPAWN_BLOCK_ANCHOR = 2;

	private const PLAYER_HALF_WIDTH = 0.3;
	private const PLAYER_HEIGHT = 1.8;

	/** Columns tried around the world spawn before giving up on the spawn radius. */
	private const RANDOM_SPAWN_TRIES = 10;
	/** How far down from the highest block of a column the ground is looked for (grass, flowers, snow layers). */
	private const SURFACE_SEARCH_DEPTH = 4;

	/**
	 * Offsets tried around a respawn anchor, in Java's order: the 8 blocks around it, the 8 below those, the 8 above
	 * those, then the top of the anchor.
	 *
	 * @var int[][]
	 * @phpstan-var list<array{int, int, int}>
	 */
	private const ANCHOR_OFFSETS = [
		[0, 0, -1], [-1, 0, 0], [0, 0, 1], [1, 0, 0], [-1, 0, -1], [1, 0, -1], [-1, 0, 1], [1, 0, 1],
		[0, -1, -1], [-1, -1, 0], [0, -1, 1], [1, -1, 0], [-1, -1, -1], [1, -1, -1], [-1, -1, 1], [1, -1, 1],
		[0, 1, -1], [-1, 1, 0], [0, 1, 1], [1, 1, 0], [-1, 1, -1], [1, 1, -1], [-1, 1, 1], [1, 1, 1],
		[0, 1, 0]
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns which kind of spawn block this is (SPAWN_BLOCK_* constant).
	 */
	public static function getSpawnBlockType(Block $block) : int{
		return match(true){
			$block instanceof Bed => self::SPAWN_BLOCK_BED,
			$block instanceof RespawnAnchor => self::SPAWN_BLOCK_ANCHOR,
			default => self::SPAWN_BLOCK_NONE
		};
	}

	/**
	 * Returns where a player respawning at this bed or respawn anchor stands up, or null if it can't be used (no
	 * longer there, out of charges or surrounded). Respawning at an anchor uses one of its charges.
	 */
	public static function useSpawnBlock(World $world, Vector3 $pos, float $yaw) : ?Vector3{
		$x = $pos->getFloorX();
		$z = $pos->getFloorZ();
		self::loadChunksAround($world, $x, $z, 2);
		$block = $world->getBlockAt($x, $pos->getFloorY(), $z);
		if($block instanceof RespawnAnchor){
			if($block->getCharges() <= 0){
				return null;
			}
			$standUp = self::findAnchorStandUpPosition($world, $block->getPosition());
			if($standUp !== null){
				$world->setBlock($block->getPosition(), $block->setCharges($block->getCharges() - 1));
				$world->addSound($block->getPosition()->add(0.5, 0.5, 0.5), new RespawnAnchorDepleteSound());
			}
			return $standUp;
		}
		if($block instanceof Bed){
			return self::findBedStandUpPosition($world, $block, $yaw);
		}
		return null;
	}

	/**
	 * Free spot around a respawn anchor (Java RespawnAnchorBlock.findStandUpPosition): safe spots first, then any.
	 */
	public static function findAnchorStandUpPosition(World $world, Vector3 $anchor) : ?Vector3{
		$x = $anchor->getFloorX();
		$y = $anchor->getFloorY();
		$z = $anchor->getFloorZ();
		foreach([true, false] as $checkDangerous){
			foreach(self::ANCHOR_OFFSETS as [$dx, $dy, $dz]){
				$standUp = self::findSafeDismountLocation($world, $x + $dx, $y + $dy, $z + $dz, $checkDangerous);
				if($standUp !== null){
					return $standUp;
				}
			}
		}
		return null;
	}

	/**
	 * Free spot around a bed (Java BedBlock.findStandUpPosition): around the bed starting on the side the player is
	 * not facing, then on top of it; safe spots first, then any. Bunk beds (a bed on a bed) also look around the
	 * lower bed.
	 *
	 * @param float $yaw the player's yaw, which decides the side tried first
	 */
	public static function findBedStandUpPosition(World $world, Bed $bed, float $yaw) : ?Vector3{
		$forward = $bed->getFacing(); //from the foot to the head
		$head = $bed->isHeadPart() ? $bed->getPosition() : $bed->getPosition()->getSide($forward);
		[$fx, , $fz] = Facing::OFFSET[$forward];
		$sideX = -$fz; //clockwise seen from above (north -> east)
		$sideZ = $fx;
		$yawRad = deg2rad($yaw);
		if($sideX * -sin($yawRad) + $sideZ * cos($yawRad) > 0){
			$sideX = -$sideX;
			$sideZ = -$sideZ;
		}
		$x = $head->getFloorX();
		$y = $head->getFloorY();
		$z = $head->getFloorZ();

		$surround = [
			[$sideX, $sideZ],
			[$sideX - $fx, $sideZ - $fz],
			[$sideX - $fx * 2, $sideZ - $fz * 2],
			[-$fx * 2, -$fz * 2],
			[-$sideX - $fx * 2, -$sideZ - $fz * 2],
			[-$sideX - $fx, -$sideZ - $fz],
			[-$sideX, -$sideZ],
			[-$sideX + $fx, -$sideZ + $fz],
			[$fx, $fz],
			[$sideX + $fx, $sideZ + $fz]
		];
		$above = [[0, 0], [-$fx, -$fz]];

		if($world->getBlockAt($x, $y - 1, $z) instanceof Bed){
			//bunk bed: around it, around the bed below, then on top
			foreach([true, false] as $checkDangerous){
				$standUp = self::findAtOffsets($world, $x, $y, $z, $surround, $checkDangerous) ??
					self::findAtOffsets($world, $x, $y - 1, $z, $surround, $checkDangerous) ??
					self::findAtOffsets($world, $x, $y, $z, $above, $checkDangerous);
				if($standUp !== null){
					return $standUp;
				}
			}
			return null;
		}

		$offsets = [...$surround, ...$above];
		return self::findAtOffsets($world, $x, $y, $z, $offsets, true) ?? self::findAtOffsets($world, $x, $y, $z, $offsets, false);
	}

	/**
	 * @param int[][] $offsets
	 * @phpstan-param list<array{int, int}> $offsets
	 */
	private static function findAtOffsets(World $world, int $x, int $y, int $z, array $offsets, bool $checkDangerous) : ?Vector3{
		foreach($offsets as [$dx, $dz]){
			$standUp = self::findSafeDismountLocation($world, $x + $dx, $y, $z + $dz, $checkDangerous);
			if($standUp !== null){
				return $standUp;
			}
		}
		return null;
	}

	/**
	 * Where a player can stand in this block (Java DismountHelper.findSafeDismountLocation): on its own floor (a
	 * slab, a carpet...) or on the block below, with room for the whole player and, if asked, nothing that hurts.
	 */
	public static function findSafeDismountLocation(World $world, int $x, int $y, int $z, bool $checkDangerous) : ?Vector3{
		if(!$world->isInWorld($x, $y, $z)){
			return null;
		}
		$block = $world->getBlockAt($x, $y, $z);
		if($checkDangerous && self::isDangerous($block)){
			return null;
		}
		$floor = self::getFloorHeight($block, $y);
		if($floor === null){
			$below = $world->getBlockAt($x, $y - 1, $z);
			$belowHeight = self::getFloorHeight($below, $y - 1);
			if($belowHeight === null || $belowHeight < 1.0){
				return null;
			}
			$floor = $belowHeight - 1.0;
			if($checkDangerous && self::isDangerous($below)){
				return null;
			}
		}
		if($floor >= 1.0){
			return null;
		}

		$feetY = $y + $floor;
		$box = new AxisAlignedBB(
			$x + 0.5 - self::PLAYER_HALF_WIDTH, $feetY, $z + 0.5 - self::PLAYER_HALF_WIDTH,
			$x + 0.5 + self::PLAYER_HALF_WIDTH, $feetY + self::PLAYER_HEIGHT, $z + 0.5 + self::PLAYER_HALF_WIDTH
		);
		if(count($world->getBlockCollisionBoxes($box)) > 0){
			return null;
		}
		if(self::isInvalidSpawnInside($block) || self::isInvalidSpawnInside($world->getBlockAt($x, $y + 1, $z))){
			return null;
		}
		return new Vector3($x, $feetY, $z);
	}

	/**
	 * Height of the top of the block's collision boxes above its base, or null if it has none (or can be climbed
	 * through: ladders, vines, open trapdoors).
	 */
	private static function getFloorHeight(Block $block, int $y) : ?float{
		if($block->canClimb() || ($block instanceof Trapdoor && $block->isOpen())){
			return null;
		}
		$top = null;
		foreach($block->getCollisionBoxes() as $box){
			if($top === null || $box->maxY > $top){
				$top = $box->maxY;
			}
		}
		return $top !== null ? $top - $y : null;
	}

	/**
	 * Blocks that hurt players standing in or on them (Java EntityType.isBlockDangerous for players).
	 */
	public static function isDangerous(Block $block) : bool{
		return $block instanceof BaseFire ||
			$block instanceof Lava ||
			$block instanceof Magma ||
			($block instanceof Campfire && $block->isLit()) ||
			$block instanceof WitherRose ||
			$block instanceof SweetBerryBush ||
			$block instanceof Cactus ||
			$block instanceof PowderSnow;
	}

	private static function isInvalidSpawnInside(Block $block) : bool{
		return $block instanceof EndPortal || $block instanceof EndGateway;
	}

	/**
	 * Loads the generated chunks within $radius blocks of the column (the stand-up spots may cross a chunk border).
	 */
	private static function loadChunksAround(World $world, int $x, int $z, int $radius) : void{
		for($cx = ($x - $radius) >> Chunk::COORD_BIT_SIZE; $cx <= ($x + $radius) >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = ($z - $radius) >> Chunk::COORD_BIT_SIZE; $cz <= ($z + $radius) >> Chunk::COORD_BIT_SIZE; ++$cz){
				if(!$world->isChunkLoaded($cx, $cz)){
					$world->loadChunk($cx, $cz);
				}
			}
		}
	}

	/**
	 * Requests where a player without a usable bed or anchor spawns: a random surface spot within the spawnradius
	 * game rule around the world spawn (a few columns are tried), or the world spawn itself (radius 0, worlds without
	 * a sky, nothing suitable found).
	 *
	 * @phpstan-return Promise<Position>
	 */
	public static function requestWorldSpawn(World $world, ?Random $random = null) : Promise{
		$radius = $world->getGameRule(GameRules::SPAWN_RADIUS);
		if(!is_int($radius) || $radius <= 0 || !$world->getDimension()->hasSkyLight()){
			return $world->requestSafeSpawn();
		}
		/** @phpstan-var PromiseResolver<Position> $resolver */
		$resolver = new PromiseResolver();
		self::tryRandomSpawn($world, $world->getSpawnLocation(), $radius, $random ?? new Random(mt_rand()), self::RANDOM_SPAWN_TRIES, $resolver);
		return $resolver->getPromise();
	}

	/**
	 * @phpstan-param PromiseResolver<Position> $resolver
	 */
	private static function tryRandomSpawn(World $world, Vector3 $spawn, int $radius, Random $random, int $triesLeft, PromiseResolver $resolver) : void{
		if($triesLeft <= 0){
			$world->requestSafeSpawn()->onCompletion(
				fn(Position $pos) => $resolver->resolve($pos),
				fn() => $resolver->reject()
			);
			return;
		}
		$x = $spawn->getFloorX() + $random->nextRange(-$radius, $radius);
		$z = $spawn->getFloorZ() + $random->nextRange(-$radius, $radius);
		$world->requestChunkPopulation($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE, null)->onCompletion(
			function() use ($world, $spawn, $radius, $random, $triesLeft, $resolver, $x, $z) : void{
				if(!$world->isLoaded()){
					$resolver->reject();
					return;
				}
				$pos = self::findSurfaceSpawn($world, $x, $z);
				if($pos !== null){
					$resolver->resolve(Position::fromObject($pos, $world));
				}else{
					self::tryRandomSpawn($world, $spawn, $radius, $random, $triesLeft - 1, $resolver);
				}
			},
			fn() => self::tryRandomSpawn($world, $spawn, $radius, $random, 0, $resolver)
		);
	}

	/**
	 * Safe spot on the ground at the top of this column (no liquid, leaves or harmful block underfoot), or null.
	 */
	public static function findSurfaceSpawn(World $world, int $x, int $z) : ?Vector3{
		try{
			$y = $world->getHighestBlockAt($x, $z);
		}catch(WorldException){
			return null;
		}
		if($y === null){
			return null;
		}
		$block = $world->getBlockAt($x, $y, $z);
		for($i = 0; $i < self::SURFACE_SEARCH_DEPTH && !$block->isSolid() && !($block instanceof Liquid) && $y > $world->getMinY(); ++$i){
			$block = $world->getBlockAt($x, --$y, $z); //grass, flowers, snow layers
		}
		if(!$block->isSolid() || $block instanceof Liquid || $block instanceof Leaves || self::isDangerous($block)){
			return null;
		}
		//on a partial block (slab, snow layers), else on top of it
		return self::findSafeDismountLocation($world, $x, $y, $z, true) ?? self::findSafeDismountLocation($world, $x, $y + 1, $z, true);
	}
}
