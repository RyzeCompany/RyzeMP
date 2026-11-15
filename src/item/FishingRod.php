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

use pocketmine\entity\Location;
use pocketmine\entity\projectile\FishingHook;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\ThrowSound;
use pocketmine\world\vibration\GameEvent;

/**
 * Fishing rod: using it casts a bobber (see {@link FishingHook}); using it again reels the line in.
 */
class FishingRod extends Durable{
	/** Speed of the cast bobber (vanilla FishingHook: 0.6 blocks per tick). */
	public const CAST_SPEED = 0.6;

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getMaxDurability() : int{
		return 385;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		// Keep the existing RyzeMP fishing.enabled flag when upgrading to VanillaMine fishing.
		if(!$player->getServer()->getConfigGroup()->getPropertyBool("fishing.enabled", true)){
			return ItemUseResult::FAIL;
		}
		$world = $player->getWorld();
		$hook = FishingHook::getActive($player);
		if($hook !== null){
			$damage = $hook->retrieve($player);
			if($damage > 0 && $player->hasFiniteResources()){
				$this->applyDamage($damage);
			}
			$world->addSound($player->getPosition(), new ThrowSound());
			return ItemUseResult::SUCCESS;
		}

		$location = $player->getLocation();
		$hook = new FishingHook(Location::fromObject($player->getEyePos(), $world, $location->yaw, $location->pitch), $player, $this);
		$hook->setMotion($directionVector->multiply(self::CAST_SPEED));
		$ev = new ProjectileLaunchEvent($hook);
		$ev->call();
		if($ev->isCancelled()){
			$hook->flagForDespawn();
			return ItemUseResult::FAIL;
		}
		$hook->spawnToAll();
		$world->addSound($location, new ThrowSound());
		$world->addGameEvent($location, GameEvent::PROJECTILE_SHOOT, $player);
		return ItemUseResult::SUCCESS;
	}

	public function getFuelTime() : int{
		return 300;
	}
}
