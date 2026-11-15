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

use pocketmine\entity\ai\goal\BreakDoorGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\World;
use function mt_rand;

/**
 * Bedrock values (vindicator.json): 24 health, movement 0.35, iron axe, attack 8, targets players within 12 blocks.
 * A vindicator named "Johnny" attacks every mob except illagers.
 * Loot: 0-1 emerald (+0-1 per Looting level) when killed by a player, and its axe (8.5%); vindicators spawned by a raid
 * add the raider drops ({@link \pocketmine\world\raid\RaiderLoot}).
 */
class Vindicator extends Illager{
	public const ATTACK_DAMAGE = 8.0;
	/** Ticks to break a door in a raid (vanilla raid_configuration annotation.break_door break_time 30 seconds) */
	private const RAID_DOOR_BREAK_TICKS = 600;

	public static function getNetworkTypeId() : string{ return EntityIds::VINDICATOR; }

	public function getName() : string{
		return "Vindicator";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(24);
		$this->setMovementSpeed(0.35);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
	}

	/**
	 * vindicator_gear: an iron axe, 25% enchanted x regional difficulty.
	 */
	protected function pickSpawnEquipment() : void{
		$this->equipFromTable("vindicator_gear");
	}

	protected function registerGoals() : void{
		$this->registerCommonGoals();
		$this->registerCreakingAvoidance(2);
		//raid_configuration: annotation.break_door break_time 30, min_difficulty normal
		$this->goalSelector->addGoal(1, new BreakDoorGoal($this, self::RAID_DOOR_BREAK_TICKS, World::DIFFICULTY_NORMAL, fn() => $this->getRaidId() !== null));
		$this->goalSelector->addGoal(3, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, 32.0));
		$this->goalSelector->addGoal(9, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(10, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(11, new RandomLookAroundGoal($this));
		//within_radius 12, must_see_forget_duration 40 seconds
		$this->registerTargetGoals(12.0, 40 * 20);
		$this->targetSelector->addGoal(3, new NearestLivingTargetGoal($this, fn(Living $e) => $this->isJohnny() && !Illager::isAlly($e), 12.0, forgetTicks: 40 * 20, filterCondition: $this->isJohnny(...)));
	}

	protected function getRaidVictimRange() : float{
		return 12.0;
	}

	protected function breaksDoorsInRaids() : bool{
		return true;
	}

	protected function isAngryWhenTargeting() : bool{
		//vindicator_aggro (minecraft:angry) from on_target_acquired to on_target_escape: clients raise its arms and show
		//its axe only while it is angry (query.is_angry)
		return true;
	}

	/**
	 * A vindicator named Johnny attacks every mob but illagers.
	 */
	public function isJohnny() : bool{
		return $this->getNameTag() === "Johnny";
	}

	public function getDrops() : array{
		$drops = $this->getHeldItemDrops();
		if($this->wasRecentlyHitByPlayerDirectly()){
			$drops[] = VanillaItems::EMERALD()->setCount(mt_rand(0, 1) + $this->lootingBonus());
		}
		foreach($this->getRaiderDrops() as $drop){
			$drops[] = $drop;
		}
		foreach($this->getRaidSpawnDrops() as $drop){
			$drops[] = $drop;
		}
		return $drops;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::VINDICATOR_SPAWN_EGG();
	}
}
