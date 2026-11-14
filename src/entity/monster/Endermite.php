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

namespace pocketmine\entity\monster;

use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;

/**
 * Bedrock values (endermite.json): 8 health, 0.4x0.3, movement 0.25, attack 2, targets players and endermen it can see
 * within 16 blocks (endermen hunt it too). No hurt_by_target, look_at_player or random_look_around. Unlike Java it has
 * no 2-minute lifetime: it stays until it despawns like other monsters. No loot, 3 experience.
 * Thrown ender pearls spawn one 5% of the time where they land (see {@link \pocketmine\entity\projectile\EnderPearl}).
 */
class Endermite extends Monster{
	public const ATTACK_DAMAGE = 2.0;

	public static function getNetworkTypeId() : string{ return EntityIds::ENDERMITE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.3, 0.4, 0.13);
	}

	public function getName() : string{
		return "Endermite";
	}

	protected function canStandOnPowderSnow() : bool{
		return true; //can_stand_on_powder_snow
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(8);
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(3, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, 16.0));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 1.0));

		//nearest_attackable_target: player and enderman families, must_see, within_radius 16
		$this->targetSelector->addGoal(5, new NearestLivingTargetGoal($this, fn(Living $e) => $e instanceof Player || $e instanceof Enderman, 16.0, true, 10, candidateClasses: [Player::class, Enderman::class]));
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 3 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ENDERMITE_SPAWN_EGG();
	}
}
