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

namespace pocketmine\player;

use pocketmine\block\BlockTypeIds;
use pocketmine\entity\HungerManager;
use pocketmine\entity\Living;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\item\Durable;
use pocketmine\utils\Utils;
use pocketmine\world\sound\ItemBreakSound;
use function floor;
use function sqrt;

/**
 * What moving costs a player: hunger, and the wear of Soul Speed boots.
 *
 * @internal
 */
final class MovementCosts{

	/** Exhaustion per metre (player.json minecraft:exhaustion_values) */
	public const SPRINT_EXHAUSTION = 0.1;
	public const SWIM_EXHAUSTION = 0.01;
	public const WALK_EXHAUSTION = 0.0;

	/** Chance that Soul Speed boots lose 1 durability when the wearer enters a new block on soul sand or soil */
	public const SOUL_SPEED_WEAR_CHANCE = 0.04;

	private function __construct(){
		//NOOP
	}

	/**
	 * Exhausts a player for the distance it moved in one tick, like vanilla (Java ServerPlayer::checkMovementStatistics):
	 * swimming and moving with the eyes in water cost by the 3D distance, wading by the horizontal distance; sprinting
	 * on the ground costs by the horizontal distance, walking is free. Moving through the air (jumping, falling,
	 * gliding) costs nothing: a sprint jump is paid by its own jump exhaustion.
	 *
	 * @param bool $swimming swimming, or the eyes are in water
	 * @param bool $inWater  touching water (wading)
	 * @param bool $onGround on the ground after the move
	 */
	public static function exhaust(HungerManager $hunger, float $dx, float $dy, float $dz, bool $swimming, bool $inWater, bool $sprinting, bool $onGround) : void{
		if($swimming){
			$distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
			if($distance > 0){
				$hunger->exhaust(self::SWIM_EXHAUSTION * $distance, PlayerExhaustEvent::CAUSE_SWIMMING);
			}
			return;
		}
		$horizontalDistance = sqrt($dx * $dx + $dz * $dz);
		if($horizontalDistance <= 0){
			return;
		}
		if($inWater){
			$hunger->exhaust(self::SWIM_EXHAUSTION * $horizontalDistance, PlayerExhaustEvent::CAUSE_SWIMMING);
		}elseif(!$onGround){
			return;
		}elseif($sprinting){
			$hunger->exhaust(self::SPRINT_EXHAUSTION * $horizontalDistance, PlayerExhaustEvent::CAUSE_SPRINTING);
		}else{
			$hunger->exhaust(self::WALK_EXHAUSTION * $horizontalDistance, PlayerExhaustEvent::CAUSE_WALKING);
		}
	}

	/**
	 * Called when an entity wearing Soul Speed boots enters a new block while on the ground: on soul sand or soul soil,
	 * the boots lose 1 durability (reduced by Unbreaking) with a 4% chance (Java soul_speed.json location_changed).
	 * The speed boost itself is applied by the client.
	 */
	public static function wearSoulSpeedBoots(Living $entity) : void{
		$location = $entity->getLocation();
		//the block that affects movement: soul sand is lower than a full block
		$typeId = $entity->getWorld()->getBlockAt((int) floor($location->x), (int) floor($location->y - 0.5), (int) floor($location->z))->getTypeId();
		if(($typeId !== BlockTypeIds::SOUL_SAND && $typeId !== BlockTypeIds::SOUL_SOIL) || Utils::getRandomFloat() >= self::SOUL_SPEED_WEAR_CHANCE){
			return;
		}
		$inventory = $entity->getArmorInventory();
		$boots = $inventory->getBoots();
		if(!$boots instanceof Durable){
			return;
		}
		$damage = $boots->getDamage();
		$boots->applyDamage(1);
		if($boots->getDamage() === $damage){
			return; //Unbreaking
		}
		if($boots->isBroken()){
			$entity->broadcastSound(new ItemBreakSound());
		}
		$inventory->setBoots($boots);
	}
}
