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
use pocketmine\block\Conduit;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Trident as TridentEntity;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\player\Player;
use pocketmine\world\sound\TridentRiptideSound;
use pocketmine\world\sound\TridentThrowSound;

class Trident extends Tool implements Releasable{
	private const DAMAGE_ON_THROW = 1;
	/** minimum use time before a release throws (Java TridentItem.THROW_THRESHOLD_TIME) */
	private const MIN_USE_TICKS = 10;
	/** launch speed in blocks/tick, independent of the use time (Bedrock thrown_trident.json projectile power) */
	private const THROW_SPEED = 4.0;
	/** duration of the Riptide spin attack (Java startAutoSpinAttack(20, 8.0)) */
	private const SPIN_ATTACK_TICKS = 20;

	public function getMaxDurability() : int{
		return 251;
	}

	public function onReleaseUsing(Player $player, array &$returnedItems) : ItemUseResult{
		$location = $player->getLocation();

		$diff = $player->getItemUseDuration();
		if($diff < self::MIN_USE_TICKS){
			return ItemUseResult::FAIL;
		}

		$riptide = $this->getEnchantmentLevel(VanillaEnchantments::RIPTIDE());
		if($riptide > 0){
			return $this->releaseRiptide($player, $riptide);
		}

		$item = $this->pop();
		if($player->hasFiniteResources()){
			$item->applyDamage(self::DAMAGE_ON_THROW);
		}
		if($item->isNull()){
			//canStartUsingItem() will normally prevent this, but it's possible the item might've been modified between
			//the start action and the release, so it's best to account for this anyway
			return ItemUseResult::FAIL;
		}
		$entity = new TridentEntity(Location::fromObject(
			$player->getEyePos(),
			$player->getWorld(),
			($location->yaw > 180 ? 360 : 0) - $location->yaw,
			-$location->pitch
		), $item, $player);
		$entity->setMotion($player->getDirectionVector()->multiply(self::THROW_SPEED));

		$ev = new ProjectileLaunchEvent($entity);
		$ev->call();
		if($ev->isCancelled()){
			$ev->getEntity()->flagForDespawn();
			return ItemUseResult::FAIL;
		}
		$ev->getEntity()->spawnToAll();
		$location->getWorld()->addSound($location, new TridentThrowSound());

		return ItemUseResult::SUCCESS;
	}

	/**
	 * Riptide: the trident is never thrown. In water or rain the client launches the player itself; the server keeps
	 * the trident in hand, takes 1 durability, plays the riptide sound and starts the spin attack that hurts the first
	 * mob touched. Elsewhere (or riding) nothing happens.
	 */
	private function releaseRiptide(Player $player, int $level) : ItemUseResult{
		if($player->getVehicle() !== null || !Conduit::isInWaterOrRain($player)){
			return ItemUseResult::FAIL;
		}
		if($player->hasFiniteResources()){
			$this->applyDamage(1);
		}
		$player->startSpinAttack(clone $this, self::SPIN_ATTACK_TICKS);
		$player->getWorld()->addSound($player->getPosition(), new TridentRiptideSound($level));

		return ItemUseResult::SUCCESS;
	}

	public function getAttackPoints() : int{
		return 9;
	}

	public function canStartUsingItem(Player $player) : bool{
		if($this->damage >= $this->getMaxDurability() - self::DAMAGE_ON_THROW){
			return false;
		}
		//a Riptide trident can only be used in water or rain
		return !$this->hasEnchantment(VanillaEnchantments::RIPTIDE()) || Conduit::isInWaterOrRain($player);
	}

	public function onAttackEntity(Entity $victim, array &$returnedItems) : bool{
		return $this->applyDamage(1);
	}

	public function onDestroyBlock(Block $block, array &$returnedItems) : bool{
		if(!$block->getBreakInfo()->breaksInstantly()){
			return $this->applyDamage(2);
		}
		return false;
	}
}
