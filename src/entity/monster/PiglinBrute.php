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

use pocketmine\entity\ai\goal\GoHomeGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\OpenDoorGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\goal\AngerTargetGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;

/**
 * Bedrock values (piglin_brute.json): 50 health, movement 0.35, golden axe, attack 7. Always hostile to players within
 * 12 blocks (gold armor doesn't help) and to wither skeletons and the wither; hurting it angers the piglins within 16 blocks.
 * Turns into a zombified piglin after 15 seconds outside the Nether. Experience 20; loot: its axe (8.5%).
 * Unlike piglins it never barters, picks up gold or hunts hoglins, and opening containers or mining gold doesn't anger
 * it (it is hostile anyway); it joins the fights of the piglins around.
 * It walks back to where it spawned (its bastion) from time to time (go_back_to_spawn: behavior.go_home, 1 in 200
 * chance per tick while more than 4 blocks away, speed 0.6); once it can't find its way back it stops trying.
 */
class PiglinBrute extends Monster implements Angerable{
	use HeldItemsTrait;
	use AngerTrait;
	use ZombifiesOutsideNetherTrait;

	public const ATTACK_DAMAGE = 7.0;
	public const ANGER_TICKS = 600;
	/** follow_range: how far a target is chased */
	private const FOLLOW_RANGE = 64.0;
	public const TAG_GO_HOME_FAILED = "GoBackToSpawnFailed"; //TAG_Byte (PocketMine: go_back_to_spawn_failed removed the group)

	private bool $goHomeFailed = false;
	private bool $countedByPiglinAi = false;

	public static function getNetworkTypeId() : string{ return EntityIds::PIGLIN_BRUTE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.9, 0.6, 1.62);
	}

	public function getName() : string{
		return "Piglin Brute";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(50);
		$this->setMovementSpeed(0.35);
		$this->setFollowRange(self::FOLLOW_RANGE);
		parent::initEntity($nbt);
		$this->loadHeldItems($nbt);
		$this->loadZombificationImmunity($nbt);
		$this->goHomeFailed = $nbt->getByte(self::TAG_GO_HOME_FAILED, 0) !== 0;
		if($this->getHomePos() === null){
			$this->setHomeHere(); //minecraft:home: where it spawned
		}
		if($nbt->getListTag("Mainhand") === null){
			$this->setMainHandItem(VanillaItems::GOLDEN_AXE());
		}
		PiglinAi::onPiglinCreated();
		$this->countedByPiglinAi = true;
	}

	protected function onDispose() : void{
		if($this->countedByPiglinAi){
			$this->countedByPiglinAi = false;
			PiglinAi::onPiglinDisposed();
		}
		parent::onDispose();
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveHeldItems($nbt);
		$this->saveZombificationImmunity($nbt);
		if($this->goHomeFailed){
			$nbt->setByte(self::TAG_GO_HOME_FAILED, 1);
		}
		return $nbt;
	}

	protected function registerGoals() : void{
		//annotation.open_door: opens the wooden doors on its paths
		$this->goalSelector->addGoal(0, new OpenDoorGoal($this));
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 1.0, self::ATTACK_DAMAGE, self::FOLLOW_RANGE));
		$this->goalSelector->addGoal(6, new GoHomeGoal($this, 0.6, 200, 4.0, null, function() : void{
			$this->goHomeFailed = true;
		}, fn() => !$this->goHomeFailed));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 0.6));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new AngerTargetGoal($this, $this->getAngerTarget(...), self::FOLLOW_RANGE));
		//family "wither": wither skeletons and the wither
		$this->targetSelector->addGoal(3, new NearestLivingTargetGoal($this, fn(Living $e) => $e instanceof Player || $e instanceof WitherSkeleton || $e instanceof Wither, 12.0, candidateClasses: [Player::class, WitherSkeleton::class, Wither::class]));
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if($source->isCancelled() || !$source instanceof EntityDamageByEntityEvent){
			return;
		}
		$damager = $source->getDamager();
		if($damager instanceof Living && !$damager instanceof Piglin && !$damager instanceof PiglinBrute && (!$damager instanceof Player || !$damager->isCreative())){
			$this->setAngryAt($damager, self::ANGER_TICKS);
			$this->alertNearby($damager, 16.0, Piglin::class, Piglin::ANGER_TICKS);
			$this->alertNearby($damager, 16.0, PiglinBrute::class, self::ANGER_TICKS);
		}
	}

	protected function despawnsInPeaceful() : bool{
		//spawn rules difficulty_filter min peaceful: they stay (and deal no damage)
		return false;
	}

	protected function isAngryWhenTargeting() : bool{
		//on_target_acquired adds the angry group (minecraft:angry)
		return true;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		$this->tickAnger($tickDiff);
		$this->tickZombification($tickDiff);
		return $hasUpdate;
	}

	protected function zombify() : void{
		$zombified = new ZombifiedPiglin($this->location->asLocation());
		$zombified->setMainHandItem($this->getMainHandItem());
		MobConversion::copyState($this, $zombified);
		$zombified->spawnToAll();
		$zombified->playMobSound(LevelSoundEvent::CONVERTED_TO_ZOMBIFIED);
		$this->flagForDespawn();
	}

	public function getDrops() : array{
		return $this->getHeldItemDrops();
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 20 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::PIGLIN_BRUTE_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::VIBRATING, $this->isZombifying());
	}
}
