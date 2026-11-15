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

use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\NearestAttackableTargetGoal;
use pocketmine\entity\ai\goal\PickupItemsGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Living;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\world\GameRules;

/**
 * Illagers (pillager, vindicator, evoker): 0.6x1.9, hold their weapon, don't fight each other (hurt_by_target
 * ignores the "illager" family) and target players, adult villagers, wandering traders and iron and snow golems
 * (pillagers leave snow golems alone) within 16 blocks (vindicators 12, evokers 20). They run from creakings within 8
 * blocks.
 *
 * Illagers are raiders ({@link Raider}): they form patrols led by a captain wearing the ominous banner (see
 * {@link \pocketmine\world\mobs\PatrolSpawner}) and attack villages in raids (see {@link \pocketmine\world\raid\Raid}).
 * A raider without a banner picks up an ominous banner within 3 blocks and becomes a captain (Bedrock shareables
 * banner:15; only during raids like Java, outside raids a banner would make a captain dropping ominous bottles).
 */
abstract class Illager extends Monster implements Raider{
	use HeldItemsTrait;
	use RaiderTrait;

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6, 1.62);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->loadHeldItems($nbt);
		if($nbt->getListTag("Mainhand") === null){
			$this->pickSpawnEquipment();
		}
		$this->loadRaiderData($nbt);
	}

	/**
	 * Gives the illager its weapon when it spawns.
	 */
	protected function pickSpawnEquipment() : void{

	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveHeldItems($nbt);
		$this->saveRaiderData($nbt);
		return $nbt;
	}

	/**
	 * Adds the hurt_by_target goal and the player target goal: players within $range blocks, forgotten after
	 * $forgetTicks out of sight (Bedrock must_see_forget_duration), closer players reselected if $reselect.
	 */
	protected function registerTargetGoals(float $range = 16.0, int $forgetTicks = NearestAttackableTargetGoal::DEFAULT_FORGET_TICKS, bool $reselect = false) : void{
		$this->targetSelector->addGoal(1, new class($this) extends HurtByTargetGoal{
			public function canStart() : bool{
				return !$this->mob->getLastAttacker() instanceof Illager && parent::canStart();
			}
		});
		$this->targetSelector->addGoal(2, new NearestAttackableTargetGoal($this, $range, true, 10, forgetTicks: $forgetTicks, reselect: $reselect));
	}

	protected function registerCommonGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->registerRaiderGoals(4, 3, true);
		//behavior.pickup_items priority 7 with shareables banner:15
		$this->goalSelector->addGoal(7, new PickupItemsGoal(
			$this,
			$this->wantsOminousBanner(...),
			$this->pickUpOminousBanner(...),
			fn() : bool => $this->getRaidId() !== null && !$this->isPatrolLeader() && GameRules::allowsMobGriefing($this->getWorld()),
			MobShareables::PICKUP_DISTANCE
		));
	}

	/**
	 * Adds the vanilla avoid_mob_type entry for creakings: within 8 blocks, sprinting at 1.2 when it is close. Creakings
	 * are only looked for while the world has some.
	 */
	protected function registerCreakingAvoidance(int $priority) : void{
		$this->goalSelector->addGoal($priority, new class($this, fn(Entity $e) => $e instanceof Creaking, 8.0, 1.0, 1.2) extends AvoidEntityGoal{
			public function canStart() : bool{
				return $this->mob->getWorld()->hasEntitiesOfClass(Creaking::class) && parent::canStart();
			}
		});
	}

	/**
	 * Whether the illager takes the item: an ominous banner (shareables banner:15), while it takes part in a raid and
	 * isn't a captain (Java Raider.pickUpItem: only raiders of a raid pick it up, and become its captain).
	 */
	private function wantsOminousBanner(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::OMINOUS_BANNER && $this->isAlive() && $this->getRaidId() !== null && !$this->isPatrolLeader();
	}

	/**
	 * Picks an ominous banner up and becomes a captain, wearing it.
	 */
	private function pickUpOminousBanner(ItemEntity $entity) : bool{
		$item = $entity->getItem();
		if(!PickupItemsGoal::isAvailable($entity) || !$this->wantsOminousBanner($item)){
			return false;
		}
		$ev = new EntityItemPickupEvent($this, $entity, (clone $item)->setCount(1), $this->armorInventory);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		NetworkBroadcastUtils::broadcastEntityEvent(
			$entity->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onPickUpItem($recipients, $this, $entity)
		);
		if($item->getCount() > 1){
			$entity->setStackSize($item->getCount() - 1);
		}else{
			$entity->flagForDespawn();
		}
		//the chest slot may hold something else: a banner replaces it
		$old = $this->armorInventory->getChestplate();
		if(!$old->isNull()){
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), $old);
		}
		$this->setPatrolLeader(true);
		$this->setPersistent(true);
		return true;
	}

	public function isRaidVictim(Living $entity) : bool{
		return ($entity instanceof VillagerV2 && !$entity->isBaby()) || $entity instanceof WanderingTrader || $entity instanceof IronGolem || $entity instanceof SnowGolem;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$this->syncRaiderData($properties);
	}

	public function getXpDropAmount() : int{
		return $this->equipmentXp(5);
	}

	/**
	 * Returns whether the entity is on the illagers' side (other illagers, vexes, ravagers).
	 */
	public static function isAlly(Living $entity) : bool{
		return $entity instanceof Illager || $entity instanceof Vex || $entity instanceof Ravager;
	}
}
