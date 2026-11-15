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

use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\Aim;
use pocketmine\entity\ai\hostile\goal\CrossbowAttackGoal;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Living;
use pocketmine\item\Crossbow;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function mt_rand;

/**
 * Bedrock values (pillager.json): 24 health, movement 0.35, crossbow: loads it (25 ticks), then shoots within 8 blocks;
 * underwater it fights in melee (attack 3) until it leaves the water.
 * Loot: 0-2 arrows (+0-1 per Looting level) and its crossbow (8.5%); pillagers spawned by a raid add the raider drops
 * ({@link \pocketmine\world\raid\RaiderLoot}).
 */
class Pillager extends Illager{
	public const ATTACK_RANGE = 8.0;
	/** Attack damage of the melee mode (pillager.json minecraft:melee_attack) */
	public const MELEE_DAMAGE = 3.0;
	private const MODE_CHECK_INTERVAL = 10;

	private bool $charging = false;
	private bool $meleeMode = false;

	public static function getNetworkTypeId() : string{ return EntityIds::PILLAGER; }

	public function getName() : string{
		return "Pillager";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(24);
		$this->setMovementSpeed(0.35);
		$this->setFollowRange(64);
		parent::initEntity($nbt);
	}

	/**
	 * pillager_gear: a crossbow, 10% enchanted x regional difficulty.
	 */
	protected function pickSpawnEquipment() : void{
		$this->equipFromTable("pillager_gear");
	}

	protected function registerGoals() : void{
		$this->registerCommonGoals();
		$this->registerCreakingAvoidance(1);
		$this->goalSelector->addGoal(3, new class($this, 1.0, self::ATTACK_RANGE, 0.0, $this->setCharging(...), $this->shootCrossbow(...)) extends CrossbowAttackGoal{
			public function canStart() : bool{
				return !($this->mob instanceof Pillager && $this->mob->isMeleeMode()) && parent::canStart();
			}
		});
		//melee_mode: melee_box_attack with attack 3 while underwater
		$this->goalSelector->addGoal(3, new class($this, 1.0, self::MELEE_DAMAGE) extends MeleeAttackGoal{
			public function canStart() : bool{
				return $this->mob instanceof Pillager && $this->mob->isMeleeMode() && parent::canStart();
			}
		});
		$this->goalSelector->addGoal(8, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(9, new LookAtPlayerGoal($this, 15.0));
		$this->goalSelector->addGoal(10, new RandomLookAroundGoal($this));
		//within_radius 16, reselect_targets
		$this->registerTargetGoals(reselect: true);
	}

	/**
	 * Whether the pillager fights in melee: it switches to melee when it is underwater and back to its crossbow once
	 * out of the water (environment_sensor events melee_mode and ranged_mode).
	 */
	public function isMeleeMode() : bool{
		return $this->meleeMode;
	}

	/**
	 * Checks the environment sensor of the current attack mode (every 10 ticks while the pillager has a target).
	 */
	private function updateAttackMode() : void{
		if($this->meleeMode){
			if(!$this->isInWater() && !$this->isUnderwater()){
				$this->meleeMode = false;
			}
		}elseif($this->isUnderwater()){
			$this->meleeMode = true;
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ticksLived % self::MODE_CHECK_INTERVAL === 0 && ($this->meleeMode || $this->getAttackTarget() !== null) && $this->isAlive()){
			$this->updateAttackMode();
		}
		return $hasUpdate;
	}

	public function isCharging() : bool{
		return $this->charging;
	}

	public function setCharging(bool $charging) : void{
		if($charging === $this->charging){
			return;
		}
		$this->charging = $charging;
		$this->networkPropertiesDirty = true;
		$this->playMobSound($charging ? LevelSoundEvent::CROSSBOW_LOADING_START : LevelSoundEvent::CROSSBOW_LOADING_END);
		$crossbow = $this->getMainHandItem();
		if(!$charging && $crossbow instanceof Crossbow){
			$crossbow->setCharged(VanillaItems::ARROW());
			$this->setMainHandItem($crossbow);
		}
	}

	public function shootCrossbow(Living $target, float $distance) : void{
		Aim::shootArrow($this, $target);
		$this->playMobSound(LevelSoundEvent::CROSSBOW_SHOOT);
		$crossbow = $this->getMainHandItem();
		if($crossbow instanceof Crossbow){
			$crossbow->setCharged(null);
			$this->setMainHandItem($crossbow);
		}
	}

	public function getDrops() : array{
		return [
			VanillaItems::ARROW()->setCount(mt_rand(0, 2) + $this->lootingBonus()),
			...$this->getHeldItemDrops(),
			...$this->getRaiderDrops(),
			...$this->getRaidSpawnDrops()
		];
	}

	/**
	 * Pillagers leave snow golems alone (pillager.json targets players, iron golems, wandering traders and adult
	 * villagers).
	 */
	public function isRaidVictim(Living $entity) : bool{
		return !$entity instanceof SnowGolem && parent::isRaidVictim($entity);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::PILLAGER_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		//flag 43 is ActorFlags::CHARGING (query.is_charging), named CHARGE_ATTACK in BedrockProtocol
		$properties->setGenericFlag(EntityMetadataFlags::CHARGE_ATTACK, $this->charging);
	}
}
