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

use pocketmine\entity\Ageable;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\goal\NearestLivingTargetGoal;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use function mt_rand;

/**
 * Bedrock values (zoglin.json): 40 health, 1.4x1.4, movement 0.25 (attacks at 1.15x), knockback resistance 0.6, fire
 * immune, undead. Attacks every mob but zoglins and creepers within 16 blocks, for 3-8 damage (babies 0.5), tossing
 * them up. 5% of those spawned (spawn eggs) are babies.
 * Loot: 1-3 rotten flesh (+0-1 per Looting level). Experience 5.
 */
class Zoglin extends Monster implements Ageable, Undead{
	public const TAG_IS_BABY = "IsBaby"; //TAG_Byte

	private bool $baby = false;

	public static function getNetworkTypeId() : string{ return EntityIds::ZOGLIN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.4, 1.4, 1.2);
	}

	public function canBeLeashed() : bool{
		return true;
	}

	public function getName() : string{
		return "Zoglin";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(40);
		$this->setMovementSpeed(0.25);
		parent::initEntity($nbt);
		$this->knockbackResistanceAttr->setValue(0.6);
		$this->setBaby($nbt->getByte(self::TAG_IS_BABY, 0) !== 0);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_IS_BABY, $this->baby ? 1 : 0);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 1.15, 1.0, 32.0));
		$this->goalSelector->addGoal(7, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(8, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		$this->targetSelector->addGoal(3, new NearestLivingTargetGoal($this, fn(Living $e) => !$e instanceof Zoglin && !$e instanceof Creeper, 16.0));
	}

	public function isBaby() : bool{
		return $this->baby;
	}

	public function setBaby(bool $baby) : void{
		$this->baby = $baby;
		$this->setScale($baby ? 0.5 : 1.0);
		if($baby){
			$this->setSize((new EntitySizeInfo(1.7, 1.5, 1.457))->scale(0.5)); //baby collision box 1.5 x 1.7 at scale 0.5
		}
		$this->networkPropertiesDirty = true;
	}

	public function getSpawnBabyChance() : int{
		return 5; //entity_spawned: 95 adult / 5 baby
	}

	public function makeSpawnBaby() : bool{
		$this->setBaby(true);
		return true;
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$hit = parent::attackMelee($target, $this->baby ? 0.5 : (float) mt_rand(3, 8));
		if($hit && !$this->baby && $this->lastMeleeHitHasDefaultKnockback()){
			Hoglin::tossUp($target);
		}
		return $hit;
	}

	protected function isAngryWhenTargeting() : bool{
		//on_target_acquired adds angry_zoglin (minecraft:angry)
		return true;
	}

	public function isFireProof() : bool{
		return true;
	}

	public function canBreathe() : bool{
		return true;
	}

	public function getDrops() : array{
		return [VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(1, 3) + $this->lootingBonus())];
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? 5 : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ZOGLIN_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->baby);
	}
}
