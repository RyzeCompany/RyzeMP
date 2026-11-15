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

use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\hostile\goal\PhantomAttackGoal;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\animal\Ocelot;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function mt_rand;

/**
 * Bedrock values (phantom.json): 20 health, 0.9x0.5, attack 6, undead, burns in daylight, targets players within
 * 64 blocks (scanning 80 blocks down) whether it can see them or not: a player under a roof is still targeted. Circles
 * high above and swoops at its target every 10-20 seconds (see {@link PhantomAttackGoal}).
 * Loot: 0-1 phantom membrane (+0-1 per Looting level) when killed by a player. Experience 5.
 * Cats and ocelots within 16 blocks keep it from swooping (behavior.avoid_mob_type).
 * Spawns naturally above players who haven't slept for 3 days (insomnia, see
 * {@link \pocketmine\world\mobs\NaturalMobSpawner}). Not implemented: sizes.
 */
class Phantom extends FlyingMonster implements Undead{
	public const ATTACK_DAMAGE = 6.0;
	public const CAT_AVOID_RANGE = 16.0;

	private PhantomAttackGoal $attackGoal;

	public static function getNetworkTypeId() : string{ return EntityIds::PHANTOM; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.5, 0.9, 0.175);
	}

	public function getName() : string{
		return "Phantom";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(1.8);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
	}

	protected function getFlightAcceleration() : float{
		return 0.08;
	}

	protected function registerGoals() : void{
		$this->attackGoal = new PhantomAttackGoal($this);
		$this->goalSelector->addGoal(2, $this->attackGoal);

		//scan_interval 20, no must_see (Bedrock default false), must_see_forget_duration 0.5 seconds, reselect_targets
		$this->targetSelector->addGoal(1, new NearestAttackableTargetGoal($this, 64.0, false, 20, forgetTicks: 10, reselect: true));
	}

	public function isSwooping() : bool{
		return $this->attackGoal->isSwooping();
	}

	/**
	 * Called when the phantom starts a swoop.
	 */
	public function onSwoop() : void{
		$this->playMobSound(LevelSoundEvent::SWOOP);
	}

	/**
	 * Whether a cat or an ocelot is within 16 blocks (phantoms don't swoop near them, whether they can see them or
	 * not).
	 */
	public function isScaredByCats() : bool{
		$world = $this->getWorld();
		if(!$world->hasEntitiesOfClass(Cat::class) && !$world->hasEntitiesOfClass(Ocelot::class)){
			return false; //no search while the world has none of them (cached class counts)
		}
		$loc = $this->location;
		$r = self::CAT_AVOID_RANGE;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
			if(($entity instanceof Cat || $entity instanceof Ocelot) && $entity->isAlive() && $entity->getLocation()->distanceSquared($loc) <= $r * $r){
				return true;
			}
		}
		return false;
	}

	public function burnsInDaylight() : bool{
		return true;
	}

	public function canBreathe() : bool{
		return true;
	}

	public function getDrops() : array{
		if(!$this->wasRecentlyHitByPlayerDirectly()){
			return [];
		}
		return [VanillaItems::PHANTOM_MEMBRANE()->setCount(mt_rand(0, 1) + $this->lootingBonus())];
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 5 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::PHANTOM_SPAWN_EGG();
	}

	protected function destroyCycles() : void{
		unset($this->attackGoal);
		parent::destroyCycles();
	}

	protected function makesFlapVibrations() : bool{
		return true;
	}
}
