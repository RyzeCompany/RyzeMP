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

use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\ai\raid\PatrolGoal;
use pocketmine\entity\ai\raid\RaidMoveGoal;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Living;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\SpawnReason;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\raid\RaiderLoot;
use pocketmine\world\RegionalDifficulty;
use function mt_rand;

/**
 * Implementation of {@link Raider}. The using class must call {@link RaiderTrait::loadRaiderData()},
 * {@link RaiderTrait::saveRaiderData()}, {@link RaiderTrait::syncRaiderData()} and
 * {@link RaiderTrait::registerRaiderGoals()} from initEntity(), saveNBT(), syncNetworkData() and registerGoals().
 *
 * Captains wear the ominous banner in the chest armor slot, which is where Bedrock puts it (Java uses the head).
 *
 * @phpstan-require-implements Raider
 */
trait RaiderTrait{
	private bool $patrolLeader = false;
	private bool $patrolling = false;
	private ?Vector3 $patrolTarget = null;
	private ?int $raidId = null;
	private int $raidWave = 0;
	private ?Vector3 $raidCenter = null;
	private bool $celebrating = false;

	protected function loadRaiderData(CompoundTag $nbt) : void{
		$this->patrolling = $nbt->getByte(Raiders::TAG_PATROLLING, 0) !== 0;
		$this->patrolTarget = Raiders::readVector($nbt->getListTag(Raiders::TAG_PATROL_TARGET));
		$raid = $nbt->getCompoundTag(Raiders::TAG_RAID);
		if($raid !== null){
			$center = Raiders::readVector($raid->getListTag(Raiders::TAG_RAID_CENTER));
			if($center !== null){
				$this->raidId = $raid->getInt(Raiders::TAG_RAID_ID);
				$this->raidWave = $raid->getInt(Raiders::TAG_RAID_WAVE, 0);
				$this->raidCenter = $center;
			}
		}
		if($nbt->getByte(Raiders::TAG_PATROL_LEADER, 0) !== 0 && $this->canBePatrolLeader()){
			$this->setPatrolLeader(true);
		}
		$this->updateRaidDoorBreaking();
	}

	/**
	 * Whether the raider breaks the doors on its way while it takes part in a raid (vanilla raid_configuration of
	 * vindicators: annotation.break_door and navigation can_break_doors).
	 */
	protected function breaksDoorsInRaids() : bool{
		return false;
	}

	/**
	 * Lets the paths of a raider which breaks doors in raids go through closed doors while it takes part in one.
	 */
	private function updateRaidDoorBreaking() : void{
		if($this->breaksDoorsInRaids()){
			$this->getNavigation()->getPathFinder()->setCanBreakDoors($this->raidId !== null);
		}
	}

	protected function saveRaiderData(CompoundTag $nbt) : void{
		$nbt->setByte(Raiders::TAG_PATROL_LEADER, $this->patrolLeader ? 1 : 0);
		$nbt->setByte(Raiders::TAG_PATROLLING, $this->patrolling ? 1 : 0);
		if($this->patrolTarget !== null){
			$nbt->setTag(Raiders::TAG_PATROL_TARGET, Raiders::vectorTag($this->patrolTarget));
		}
		if($this->raidId !== null && $this->raidCenter !== null){
			$nbt->setTag(Raiders::TAG_RAID, CompoundTag::create()
				->setInt(Raiders::TAG_RAID_ID, $this->raidId)
				->setInt(Raiders::TAG_RAID_WAVE, $this->raidWave)
				->setTag(Raiders::TAG_RAID_CENTER, Raiders::vectorTag($this->raidCenter))
			);
		}
	}

	protected function syncRaiderData(EntityMetadataCollection $properties) : void{
		//captains use variant 1 (vanilla component groups patrol_captain and illager_squad_captain)
		if($this->canBePatrolLeader()){
			$properties->setInt(EntityMetadataProperties::VARIANT, $this->patrolLeader ? 1 : 0);
		}
		$properties->setGenericFlag(EntityMetadataFlags::CELEBRATING, $this->celebrating);
	}

	/**
	 * Adds the patrol and raid goals: walking with the patrol or to the raided village at $movePriority, and attacking
	 * the raid victims ({@link Raider::isRaidVictim()}) within {@link RaiderTrait::getRaidVictimRange()} blocks,
	 * always (vanilla illagers and ravagers) or only while raiding; $targetPriority null for no victim goal (witches
	 * pick their targets themselves).
	 */
	protected function registerRaiderGoals(int $movePriority, ?int $targetPriority, bool $victimsOutsideRaids) : void{
		$this->goalSelector->addGoal($movePriority, new RaidMoveGoal($this));
		$this->goalSelector->addGoal($movePriority, new PatrolGoal($this));
		if($targetPriority !== null){
			//(the vanilla victims are adult villagers, wandering traders and golems: the search looks at nothing else, and
			//at nobody while the raider leaves them alone outside raids, unless a subclass changes isRaidVictim())
			$vanillaVictims = VanillaMethods::isDeclaredBy(static::class, "isRaidVictim", [Illager::class, Pillager::class, Ravager::class, Witch::class]);
			$this->targetSelector->addGoal($targetPriority, new NearestLivingTargetGoal($this, fn(Living $e) => ($victimsOutsideRaids || $this->raidId !== null) && $this->isRaidVictim($e), $this->getRaidVictimRange(),
				candidateClasses: $vanillaVictims ? [VillagerV2::class, WanderingTrader::class, IronGolem::class, SnowGolem::class] : null,
				filterCondition: $victimsOutsideRaids ? null : fn() : bool => $this->raidId !== null
			));
		}
	}

	/**
	 * How far the raid victims are looked for (Bedrock nearest_attackable_target max_dist of the villager, wandering
	 * trader and golem entries).
	 */
	protected function getRaidVictimRange() : float{
		return 16.0;
	}

	public function isPatrolLeader() : bool{
		return $this->patrolLeader;
	}

	public function canBePatrolLeader() : bool{
		return $this instanceof Illager;
	}

	public function setPatrolLeader(bool $leader) : void{
		if($leader && !$this->canBePatrolLeader()){
			throw new \InvalidArgumentException("This mob can't be a captain");
		}
		$this->patrolLeader = $leader;
		$this->networkPropertiesDirty = true;
		$this->armorInventory->setChestplate($leader ? VanillaItems::OMINOUS_BANNER() : VanillaItems::AIR());
	}

	public function isPatrolling() : bool{
		return $this->patrolling;
	}

	public function setPatrolling(bool $patrolling) : void{
		$this->patrolling = $patrolling;
		if(!$patrolling){
			$this->patrolTarget = null;
		}
	}

	public function getPatrolTarget() : ?Vector3{
		return $this->patrolTarget;
	}

	public function setPatrolTarget(?Vector3 $target) : void{
		$this->patrolTarget = $target;
	}

	public function getRaidId() : ?int{
		return $this->raidId;
	}

	public function getRaidWave() : int{
		return $this->raidWave;
	}

	public function getRaidCenter() : ?Vector3{
		return $this->raidCenter;
	}

	public function joinRaid(int $raidId, int $wave, Vector3 $center) : void{
		$this->raidId = $raidId;
		$this->raidWave = $wave;
		$this->raidCenter = $center->asVector3();
		$this->patrolling = false;
		$this->patrolTarget = null;
		$this->updateRaidDoorBreaking();
	}

	public function leaveRaid() : void{
		$this->raidId = null;
		$this->raidCenter = null;
		$this->setCelebrating(false);
		$this->updateRaidDoorBreaking();
	}

	public function isCelebrating() : bool{
		return $this->celebrating;
	}

	public function setCelebrating(bool $celebrating) : void{
		if($celebrating === $this->celebrating){
			return;
		}
		$this->celebrating = $celebrating;
		$this->networkPropertiesDirty = true;
		if($celebrating){
			$this->playMobSound(LevelSoundEvent::CELEBRATE);
		}
	}

	/**
	 * Raiders taking part in a raid never despawn until the raid is over (vanilla).
	 */
	public function canDespawn() : bool{
		return $this->raidId === null && parent::canDespawn();
	}

	protected function onDeath() : void{
		if($this->raidId !== null){
			$this->getWorld()->getRaidManager()->onRaiderDeath($this, $this->wasRecentlyHitByPlayer() ? $this->lastHurtByPlayer : null);
		}
		parent::onDeath();
	}

	/**
	 * Extra drops of a captain: its ominous banner, and an ominous bottle of a random level (Bad Omen I-V) when it was
	 * killed by a player outside a raid (1.21+; captains no longer give Bad Omen directly).
	 *
	 * @return Item[]
	 */
	protected function getRaiderDrops() : array{
		if(!$this->patrolLeader){
			return [];
		}
		$drops = [VanillaItems::OMINOUS_BANNER()];
		if($this->raidId === null && $this->wasRecentlyHitByPlayerDirectly()){
			$drops[] = VanillaItems::OMINOUS_BOTTLE()->setAmplifier(mt_rand(0, 4));
		}
		return $drops;
	}

	/**
	 * Raid loot of pillagers and vindicators spawned by a raid (Bedrock raid_configuration loot tables, see
	 * {@link RaiderLoot}). Mobs that only joined a raid keep their normal loot.
	 *
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	protected function getRaidSpawnDrops() : array{
		if($this->getSpawnReason() !== SpawnReason::RAID){
			return [];
		}
		$world = $this->getWorld();
		return RaiderLoot::roll($world->getDifficulty(), $this->getLootingLevel(), RegionalDifficulty::getSpecialMultiplierAt($world, $this->location));
	}
}
