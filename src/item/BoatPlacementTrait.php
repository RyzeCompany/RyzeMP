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

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\Water;
use pocketmine\entity\Location;
use pocketmine\entity\vehicle\Boat as BoatEntity;
use pocketmine\entity\vehicle\BoatVariant;
use pocketmine\entity\vehicle\ChestBoat as ChestBoatEntity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function count;
use function fmod;

/**
 * Places a boat where the player points (vanilla BoatItem.use()): on the water surface, or on a block (Bedrock boats
 * can be placed on land too). The boat faces the way the player looks.
 */
trait BoatPlacementTrait{
	/** Ticks after placing a boat during which the same player cannot place another one. */
	private const BOAT_PLACE_COOLDOWN_TICKS = 4;

	/**
	 * Server tick of the last boat each player placed.
	 * @phpstan-var \WeakMap<Player, int>|null
	 */
	private static ?\WeakMap $lastBoatPlaced = null;

	/**
	 * The wood of the boat this item places.
	 */
	abstract public function getBoatVariant() : BoatVariant;

	/**
	 * Whether this item places a boat with chest.
	 */
	abstract public function placesChestBoat() : bool;

	public function getMaxStackSize() : int{
		return 1;
	}

	/**
	 * Creates and spawns the boat of this item at the given position (its bottom centre). Does not consume the item.
	 *
	 * @param float $yaw Bedrock yaw of the boat: the player's yaw + 90 for a boat facing where the player looks
	 */
	public function spawnBoat(World $world, Vector3 $pos, float $yaw = 0.0) : BoatEntity{
		$yaw = fmod($yaw, 360);
		if($yaw < 0){
			$yaw += 360;
		}
		$location = Location::fromObject($pos, $world, $yaw, 0.0);
		$boat = $this->placesChestBoat() ? new ChestBoatEntity($location) : new BoatEntity($location);
		$boat->setVariant($this->getBoatVariant());
		if($this->hasCustomName()){
			$boat->setNameTag($this->getCustomName());
		}
		$boat->spawnToAll();
		return $boat;
	}

	/**
	 * Returns whether a boat fits at the given position (no block in the way).
	 */
	public static function canPlaceBoatAt(World $world, Vector3 $pos) : bool{
		$bb = new AxisAlignedBB($pos->x - 0.7, $pos->y, $pos->z - 0.7, $pos->x + 0.7, $pos->y + 0.455, $pos->z + 0.7);
		return count($world->getCollisionBlocks($bb)) === 0;
	}

	/**
	 * Returns the height of the water surface in the column of the given water block (its top water block).
	 */
	private static function getWaterSurface(World $world, int $x, int $y, int $z) : float{
		while($world->getBlockAt($x, $y + 1, $z) instanceof Water){
			++$y;
		}
		$block = $world->getBlockAt($x, $y, $z);
		$height = $block instanceof Water && !$block->isFalling() ? (8 - $block->getDecay()) / 9 : 8 / 9;
		return $y + $height;
	}

	private function placeBoat(Player $player, Vector3 $pos) : ItemUseResult{
		$world = $player->getWorld();
		//one tap on water reaches the server as a use on the block and a use in the air: in creative the item is not
		//used up by the first, so without this the second placed another boat
		$tick = $player->getServer()->getTick();
		$lastPlaced = self::$lastBoatPlaced ??= new \WeakMap();
		if(isset($lastPlaced[$player]) && $tick - $lastPlaced[$player] < self::BOAT_PLACE_COOLDOWN_TICKS){
			return ItemUseResult::NONE;
		}
		if(!self::canPlaceBoatAt($world, $pos)){
			return ItemUseResult::FAIL;
		}
		$lastPlaced[$player] = $tick;
		$this->spawnBoat($world, $pos, $player->getLocation()->yaw + 90);
		$world->addGameEvent($pos, GameEvent::ENTITY_PLACE, $player);
		$this->pop();
		return ItemUseResult::SUCCESS;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		$world = $player->getWorld();
		$clicked = $blockClicked->getPosition();
		if($blockClicked instanceof Water || $blockReplace instanceof Water){
			$water = $blockClicked instanceof Water ? $clicked : $blockReplace->getPosition();
			$surface = self::getWaterSurface($world, $water->getFloorX(), $water->getFloorY(), $water->getFloorZ());
			return $this->placeBoat($player, new Vector3($water->x + $clickVector->x, $surface, $water->z + $clickVector->z));
		}
		//the point the player clicks (vanilla), or the middle of the free block in front of that face
		$hit = $clicked->addVector($clickVector);
		if(self::canPlaceBoatAt($world, $hit)){
			return $this->placeBoat($player, $hit);
		}
		$replace = $blockReplace->getPosition();
		return $this->placeBoat($player, new Vector3($replace->x + 0.5, $replace->y, $replace->z + 0.5));
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		//clients may not target water: look for it along the line of sight (vanilla ray traces fluid sources)
		$world = $player->getWorld();
		$eye = $player->getEyePos();
		foreach(VoxelRayTrace::inDirection($eye, $directionVector, 5.0) as $pos){
			$x = $pos->getFloorX();
			$y = $pos->getFloorY();
			$z = $pos->getFloorZ();
			$block = $world->getBlockAt($x, $y, $z);
			if($block instanceof Water){
				$surface = self::getWaterSurface($world, $x, $y, $z);
				$point = new Vector3($x + 0.5, $surface, $z + 0.5);
				if($directionVector->y < -0.01){
					$t = ($surface - $eye->y) / $directionVector->y;
					$hit = $eye->addVector($directionVector->multiply($t));
					if($hit->distanceSquared($eye) <= 36){
						$point = new Vector3($hit->x, $surface, $hit->z);
					}
				}
				return $this->placeBoat($player, $point);
			}
			if(count($block->getCollisionBoxes()) > 0){
				break;
			}
		}
		return ItemUseResult::NONE;
	}
}
