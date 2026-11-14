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

use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function mt_rand;

/**
 * Bedrock values (wither_skeleton.json): 20 health, movement 0.25 (attacks at 1.25x), 0.72x2.01 (scale 1.2), attack 4
 * with 10 seconds of Wither, stone sword, fire immune, doesn't burn in daylight. Attacks players, piglins and piglin
 * brutes (not on Peaceful), iron golems and baby turtles out of water.
 * Some pick up better swords, tools and armor within 3 blocks (see {@link MobShareables::witherSkeleton()}).
 * Loot: 0-1 coal, 0-2 bones (+0-1 each per Looting level), 2.5% (+2% per Looting level) wither skeleton skull when
 * killed by a player.
 */
class WitherSkeleton extends Monster implements Undead{
	use HeldItemsTrait;

	public const ATTACK_DAMAGE = 4.0;
	public const WITHER_TICKS = 200;

	public static function getNetworkTypeId() : string{ return EntityIds::WITHER_SKELETON; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.675, 0.6, 1.45);
	}

	public function getName() : string{
		return "Wither Skeleton";
	}

	public function isImmuneToEffect(Effect $effect) : bool{
		return $effect === VanillaEffects::WITHER() || parent::isImmuneToEffect($effect); //vanilla wither_immune_to
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(20);
		$this->setMovementSpeed(0.25);
		$this->setScale(1.2); //before the navigator is made: it needs room for the scaled height
		parent::initEntity($nbt);
		$this->loadHeldItems($nbt);
		if($nbt->getListTag("Mainhand") === null){
			$this->setMainHandItem(VanillaItems::STONE_SWORD());
		}
		$this->initItemPickup($nbt, MobShareables::witherSkeleton(), 5);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->saveHeldItems($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 1.25, self::ATTACK_DAMAGE, 32.0));
		$this->goalSelector->addGoal(6, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(7, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(8, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		//players, piglins and piglin brutes (not on Peaceful), iron golems and baby turtles out of water (must_see,
		//reselect_targets); players only while none of the others is within range
		$nearby = MonsterTargets::nearbyTargets($this, [Piglin::class, PiglinBrute::class, IronGolem::class, Turtle::class], MonsterTargets::isWitherSkeletonTarget(...), 16.0);
		$this->targetSelector->addGoal(2, MonsterTargets::extraTargetGoal($this, MonsterTargets::isWitherSkeletonTarget(...), $nearby, null, 16.0, true, 10, true));
		$this->targetSelector->addGoal(2, MonsterTargets::playerTargetGoal($this, $nearby, null, 16.0, true, 10, true));
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$hit = parent::attackMelee($target, $baseDamage);
		if($hit && $target->isAlive()){
			$target->getEffects()->add(new EffectInstance(VanillaEffects::WITHER(), self::WITHER_TICKS));
		}
		return $hit;
	}

	public function isFireProof() : bool{
		return true;
	}

	public function canBreathe() : bool{
		return true;
	}

	public function getDrops() : array{
		$drops = [
			VanillaItems::COAL()->setCount(mt_rand(0, 1) + $this->lootingBonus()),
			VanillaItems::BONE()->setCount(mt_rand(0, 2) + $this->lootingBonus())
		];
		if($this->wasRecentlyHitByPlayerDirectly() && mt_rand(0, 999) < 25 + 20 * $this->getLootingLevel()){
			$drops[] = VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::WITHER_SKELETON)->asItem();
		}
		$head = ChargedCreeperHeads::getHeadDrop($this);
		if($head !== null){
			$drops[] = $head;
		}
		return [...$drops, ...$this->getEquipmentDrops()];
	}

	public function getXpDropAmount() : int{
		return $this->equipmentXp(5);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::WITHER_SKELETON_SPAWN_EGG();
	}
}
