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

namespace pocketmine\entity\animal;

use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function mt_rand;

/**
 * Bedrock values (entities/polar_bear.json): 30 health, 1.4x1.4, movement 0.25, follow range 48, attack 6. Neutral:
 * adults attack whoever hurts them and hunt foxes. A wild cub that notices a player within 16 blocks, or is hurt, calls
 * every polar bear within 41 blocks (baby_wild nearest_attackable_target, on_scared: baby_scared broadcast_anger): the
 * adults go after that player or attacker. Bears, cubs included, only panic from fire-like damage, never when hit. Not
 * breedable.
 * Loot (loot_tables/entities/polar_bear.json): 0-2 raw cod and 0-2 raw salmon (cooked when burning), each +0-1 per
 * Looting level; XP 1-3.
 */
class PolarBear extends Animal{
	/** baby_scared angry broadcast_range. */
	public const ANGER_BROADCAST_RANGE = 41.0;
	/** baby_scared angry duration (1 s): a scared cub calls the bears again at most this often. */
	private const SCARED_TICKS = 20;

	/** A cub calls the bears again for the same target after this many ticks (performance, see callBears()). */
	private const REPEAT_CALL_TICKS = 100;
	/** ... and after this many when there was no adult bear around last time. */
	private const REPEAT_CALL_NO_BEARS_TICKS = 200;

	private int $lastBroadcastTick = -1000;
	private int $lastBroadcastTargetId = -1;
	private bool $lastBroadcastFoundBears = false;

	public static function getNetworkTypeId() : string{ return EntityIds::POLAR_BEAR; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.4, 1.4, 1.2);
	}

	public function canFreeze() : bool{
		return false; //vanilla freeze_immune_entity_types
	}

	public function getName() : string{
		return "Polar Bear";
	}

	public function getSpawnBabyChance() : int{
		return 10; //entity_spawned: 9 adult / 1 baby
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(30);
		$this->setMovementSpeed(0.25);
		$this->setFollowRange(48);
		parent::initEntity($nbt);
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		//Bedrock: only fire-like damage, never hits (damage_sources, ignore_mob_damage), babies included
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25, null, PanicGoal::FIRE_CAUSES, true));
		$this->goalSelector->addGoal(1, new MeleeAttackGoal($this, 1.25, 6.0, 48.0, reachMultiplier: 2.0)); //stomp_attack
		$this->goalSelector->addGoal(4, new FollowParentGoal($this, 1.25));
		$this->goalSelector->addGoal(5, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(6, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(7, new RandomLookAroundGoal($this));
		$this->targetSelector->addGoal(2, new HurtByTargetGoal($this, 48.0));
		//baby_wild: players within 16 blocks of the cub (must_see false), adult_wild: foxes
		$this->targetSelector->addGoal(4, new NearestAttackableTargetGoal($this, 16.0, false, 10, fn() => $this->isBaby()));
		$this->targetSelector->addGoal(4, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Fox, 16.0, false, 10, fn() => !$this->isBaby(), Fox::class));
	}

	public function setAttackTarget(?Living $target) : void{
		if($this->isBaby()){
			//a cub never attacks: acquiring a target scares it and it calls the bears around (on_scared)
			if($target !== null){
				$this->callBears($target);
			}
			$target = null;
		}
		parent::setAttackTarget($target);
	}

	/**
	 * baby_scared broadcast_anger: the polar bears within 41 blocks that have no target go after the cub's target (the
	 * adults through their HurtByTargetGoal, adult_wild on_friendly_anger). Once per second at most (angry duration).
	 */
	private function callBears(Living $target) : void{
		$now = $this->server->getTick();
		$elapsed = $now - $this->lastBroadcastTick;
		if($elapsed < self::SCARED_TICKS){
			return;
		}
		//the cub keeps finding the same player while it stays near: calling again soon changes nothing (the adults
		//called already chase it, follow range 48), so the scan is skipped for a while; a new target or a hit calls again
		if($target->getId() === $this->lastBroadcastTargetId && $elapsed < ($this->lastBroadcastFoundBears ? self::REPEAT_CALL_TICKS : self::REPEAT_CALL_NO_BEARS_TICKS)){
			return;
		}
		$this->lastBroadcastTick = $now;
		$this->lastBroadcastTargetId = $target->getId();
		$found = false;
		$loc = $this->location;
		$r = self::ANGER_BROADCAST_RANGE;
		foreach($this->getWorld()->getNearbyEntities(new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r), $this) as $entity){
			if($entity instanceof PolarBear && !$entity->isBaby() && $entity->isAlive() && $entity->location->distanceSquared($loc) <= $r * $r){
				$found = true;
				if($entity->getAttackTarget() === null){
					$entity->alertTo($target);
				}
			}
		}
		$this->lastBroadcastFoundBears = $found;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $this->isBaby()){
			$this->lastBroadcastTargetId = -1; //a hit cub calls the bears again (on_hurt: on_scared)
		}
	}

	protected function isAngryWhenTargeting() : bool{
		//adult_wild: on_target_acquired adds adult_hostile (minecraft:angry)
		return true;
	}

	public function isBreedingItem(Item $item) : bool{
		return false;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new PolarBear($location);
	}

	protected function getAdultDrops() : array{
		$drops = [];
		$cod = mt_rand(0, 2) + $this->lootingBonus();
		if($cod > 0){
			$drops[] = $this->cookedIfBurning(VanillaItems::RAW_FISH(), VanillaItems::COOKED_FISH())->setCount($cod);
		}
		$salmon = mt_rand(0, 2) + $this->lootingBonus();
		if($salmon > 0){
			$drops[] = $this->cookedIfBurning(VanillaItems::RAW_SALMON(), VanillaItems::COOKED_SALMON())->setCount($salmon);
		}
		return $drops;
	}
}
