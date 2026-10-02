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
use pocketmine\entity\Location;
use pocketmine\entity\projectile\FishingHook;
use pocketmine\entity\projectile\FishingSettings;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\ThrowSound;

class FishingRod extends Durable{

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getMaxDurability() : int{
		return 384;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$settings = FishingSettings::fromConfig($player->getServer()->getConfigGroup());
		if(!$settings->enabled || !$player->isAlive() || $player->isSpectator()
			|| !$player->tryStartFishingAction($settings->cooldownTicks)){
			return ItemUseResult::FAIL;
		}
		$hook = $player->getFishingHook();
		if($hook !== null){
			$damage = $hook->reelIn($player);
			$player->setFishingHook(null);
			if(!$player->isCreative()){
				$this->applyDamage($damage);
			}
			return ItemUseResult::SUCCESS;
		}

		$location = $player->getLocation();
		$hook = new FishingHook(Location::fromObject($player->getEyePos(), $player->getWorld(), $location->yaw, $location->pitch), $player, $settings);
		$hook->setMotion($directionVector->normalize()->multiply(1.1));
		$player->setFishingHook($hook);
		$event = new ProjectileLaunchEvent($hook);
		$event->call();
		if($event->isCancelled() || !$hook->isValidFor($player) || $player->getFishingHook() !== $hook){
			$hook->flagForDespawn();
			if($player->getFishingHook() === $hook){
				$player->setFishingHook(null);
			}
			return ItemUseResult::FAIL;
		}
		$hook->spawnToAll();
		$player->getWorld()->addSound($location, new ThrowSound());
		return ItemUseResult::SUCCESS;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		return $this->onClickAir($player, $player->getDirectionVector(), $returnedItems);
	}
}
