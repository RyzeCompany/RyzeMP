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

namespace pocketmine\world\mobs\spawn;

use pocketmine\block\BaseFire;
use pocketmine\block\Block;
use pocketmine\block\Campfire;
use pocketmine\block\Cobweb;
use pocketmine\block\Lava;
use pocketmine\block\Leaves;
use pocketmine\block\PowderSnow;
use pocketmine\block\SweetBerryBush;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\Water;
use pocketmine\block\WitherRose;
use pocketmine\math\Facing;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\World;
use function count;
use function str_contains;

/**
 * Classifies block states for mob spawning, by state ID (cached, so the column scans of the spawner read chunks
 * without creating Block objects):
 * - mobs spawn with their feet in a block without collision ({@link PASSABLE}: air, grass, flowers, snow layers...),
 *   or in water for aquatic mobs;
 * - on a block with a full, solid top face ({@link SOLID_TOP}): not carpets, bottom slabs, fences, glass or leaves;
 * - striders on lava.
 */
final class SpawnBlockInfo{
	public const PASSABLE = 1;
	public const SOLID_TOP = 2;
	public const WATER = 4;
	public const LAVA = 8;
	/** Hurts or traps mobs at their feet (fire, berry bushes, cobwebs, powder snow...). */
	public const DANGER = 16;
	/** Has a collision box or is lava: ends the "surface" of a column. */
	public const SOLID = 32;

	/** @phpstan-var array<int, int> */
	private static array $flags = [];
	/** @phpstan-var array<int, string> */
	private static array $names = [];

	private function __construct(){
		//NOOP
	}

	/**
	 * Flags of the state ID found at this position (the block is looked up there the first time, since collision boxes
	 * of some blocks depend on their neighbours).
	 */
	public static function flags(World $world, int $stateId, int $x, int $y, int $z) : int{
		return self::$flags[$stateId] ??= self::classify($world->getBlockAt($x, $y, $z, addToCache: false));
	}

	/**
	 * Bedrock name of the state ID, e.g. "minecraft:grass_block".
	 */
	public static function name(int $stateId) : string{
		if(!isset(self::$names[$stateId])){
			try{
				self::$names[$stateId] = GlobalBlockStateHandlers::getSerializer()->serialize($stateId)->getName();
			}catch(\Throwable){
				self::$names[$stateId] = "minecraft:unknown";
			}
		}
		return self::$names[$stateId];
	}

	public static function classify(Block $block) : int{
		$flags = 0;
		if($block instanceof Lava){
			return self::LAVA | self::SOLID | self::DANGER;
		}
		if($block instanceof Water || ($block instanceof Waterlogged && $block->isWaterlogged())){
			$flags |= self::WATER;
		}
		if($block instanceof BaseFire || $block instanceof SweetBerryBush || $block instanceof WitherRose || $block instanceof PowderSnow ||
			$block instanceof Cobweb || ($block instanceof Campfire && $block->isLit())
		){
			$flags |= self::DANGER;
		}
		if(count($block->getCollisionBoxes()) === 0){
			return $flags | self::PASSABLE;
		}
		$flags |= self::SOLID;
		if($block->getSupportType(Facing::UP) === SupportType::FULL && !$block instanceof Leaves && !str_contains(self::name($block->getStateId()), "glass")){
			$flags |= self::SOLID_TOP;
		}
		return $flags;
	}

	/**
	 * @internal for tests
	 */
	public static function clearCache() : void{
		self::$flags = [];
		self::$names = [];
	}
}
