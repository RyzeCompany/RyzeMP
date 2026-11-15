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

namespace pocketmine\world\dimension;

use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\generator\end\TheEnd;
use pocketmine\world\World;

/**
 * The exit portal (vanilla "end podium") at the centre of the End: a bowl of bedrock around a 5x5 pool of End portal
 * blocks, with a 4 block bedrock pillar in the middle carrying four torches. Before the dragon is killed the pool is
 * empty; afterwards its End portal blocks take players back to the Overworld. The dragon perches on top of the
 * pillar, and the dragon egg appears there.
 *
 * The shape follows vanilla's EndPodiumFeature: the origin is the centre of the portal layer; within 2.5 blocks of it
 * (counting the vertical distance, block corners to the origin) is portal (or bedrock one layer below), within 3.5
 * blocks is the bedrock rim.
 */
final class EndExitPortal{
	public const X = 0;
	public const Z = 0;
	/** Height of the bedrock pillar above the portal layer (the dragon egg sits on top of it). */
	public const PILLAR_HEIGHT = 4;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns where the portal goes when it was never built: on the surface of the island at 0,0 (the highest block
	 * of the column plus one), or at the generator's island height if the column is empty or not loaded.
	 */
	public static function findOrigin(World $world) : Vector3{
		$y = null;
		if($world->isChunkLoaded(self::X >> 4, self::Z >> 4)){
			$y = $world->getHighestBlockAt(self::X, self::Z);
		}
		if($y === null){
			$y = TheEnd::ISLAND_SURFACE_Y;
		}
		return new Vector3(self::X, $y + 1, self::Z);
	}

	/**
	 * Builds the portal around the given origin (centre of the portal layer). With $active false the portal pool is
	 * left empty (air).
	 */
	public static function build(World $world, Vector3 $origin, bool $active) : void{
		$ox = $origin->getFloorX();
		$oy = $origin->getFloorY();
		$oz = $origin->getFloorZ();
		$bedrock = VanillaBlocks::BEDROCK();
		$endStone = VanillaBlocks::END_STONE();
		$air = VanillaBlocks::AIR();
		$portal = VanillaBlocks::END_PORTAL();
		for($dx = -4; $dx <= 4; ++$dx){
			for($dz = -4; $dz <= 4; ++$dz){
				for($dy = -1; $dy <= 32; ++$dy){
					$y = $oy + $dy;
					if(!$world->isInWorld($ox + $dx, $y, $oz + $dz)){
						continue;
					}
					$distSq = $dx * $dx + $dy * $dy + $dz * $dz;
					$inner = $distSq < 2.5 * 2.5;
					if(!$inner && $distSq >= 3.5 * 3.5){
						continue;
					}
					if($dy < 0){
						$block = $inner ? $bedrock : $endStone;
					}elseif($dy > 0){
						$block = $air;
					}elseif(!$inner){
						$block = $bedrock;
					}else{
						$block = $active ? $portal : $air;
					}
					$world->setBlockAt($ox + $dx, $y, $oz + $dz, $block);
				}
			}
		}
		for($dy = 0; $dy < self::PILLAR_HEIGHT; ++$dy){
			$world->setBlockAt($ox, $oy + $dy, $oz, $bedrock);
		}
		foreach(Facing::HORIZONTAL as $facing){
			$torch = $origin->add(0, 2, 0)->getSide($facing);
			$world->setBlockAt($torch->getFloorX(), $torch->getFloorY(), $torch->getFloorZ(), VanillaBlocks::TORCH()->setFacing($facing));
		}
	}

	/**
	 * Position of the dragon egg / where the dragon perches: on top of the pillar.
	 */
	public static function getPillarTop(Vector3 $origin) : Vector3{
		return $origin->add(0, self::PILLAR_HEIGHT, 0);
	}

	public static function placeDragonEgg(World $world, Vector3 $origin) : void{
		$top = self::getPillarTop($origin);
		$world->setBlockAt($top->getFloorX(), $top->getFloorY(), $top->getFloorZ(), VanillaBlocks::DRAGON_EGG());
	}
}
