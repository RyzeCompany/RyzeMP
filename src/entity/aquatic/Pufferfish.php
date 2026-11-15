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

namespace pocketmine\entity\aquatic;

use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\animal\Goat;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\monster\AquaticMonster;
use pocketmine\entity\monster\PiglinBrute;
use pocketmine\entity\WaterAnimal;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;

/**
 * Bedrock values (entities/pufferfish.json): 3 health, 0.8x0.8 at scale 1.2, underwater speed 0.13.
 * Puffs up when a player or a non-aquatic mob (or an axolotl) comes within 2.5 blocks: half puffed, then fully
 * puffed if something is still within 2.5 blocks during the next 2 seconds (VARIANT 0/1/2). Fully puffed, it hurts what
 * touches it (2 damage every 0.5 s, not creative players) and poisons it for 10 seconds (not undead mobs); it deflates
 * back 3 seconds after nothing is within 2.9 blocks, then 2 seconds later.
 * Loot (loot_tables/entities/pufferfish.json): 1 pufferfish, 25% bone.
 */
class Pufferfish extends AbstractFish{
	public const PUFF_NONE = 0;
	public const PUFF_HALF = 1;
	public const PUFF_FULL = 2;

	private int $puffState = self::PUFF_NONE;
	private int $puffTimer = 0;
	private int $attackCooldown = 0;
	/** Half puffed for 2 seconds already, or after being fully puffed: a threat now puffs it up fully. */
	private bool $halfPuffSecondary = false;
	/** Fully puffed and deflating 3 seconds later (start_deflate). */
	private bool $deflating = false;

	public static function getNetworkTypeId() : string{ return EntityIds::PUFFERFISH; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.8, 0.8, 0.5);
	}

	public function getName() : string{
		return "Pufferfish";
	}

	protected function getSwimSpeed() : float{
		return 0.13;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setScale(1.2);
	}

	public function getPuffState() : int{
		return $this->puffState;
	}

	public function setPuffState(int $state) : void{
		if($state !== $this->puffState){
			$this->puffState = $state;
			$this->networkPropertiesDirty = true;
			$this->halfPuffSecondary = false;
			$this->deflating = false;
			$this->puffTimer = $state === self::PUFF_HALF ? 40 : 0;
		}
	}

	/**
	 * Whether the entity makes the pufferfish puff up (Bedrock entity_sensor filter: players, creative ones included,
	 * and mobs of the "mob" family that aren't aquatic, or axolotls).
	 */
	public static function isThreat(Entity $entity) : bool{
		if($entity instanceof Player){
			return !$entity->isSpectator() && $entity->isAlive();
		}
		return self::isNonAquaticMob($entity, false);
	}

	/**
	 * Whether the puffed up pufferfish hurts the entity touching it (area_attack filter: like the sensor, but not
	 * creative players), and whether it also poisons it (mob_effect filter: undead mobs aren't poisoned either).
	 */
	private static function canSting(Entity $entity, bool $poison) : bool{
		if($entity instanceof Player){
			return !$entity->isSpectator() && !$entity->isCreative() && $entity->isAlive();
		}
		return self::isNonAquaticMob($entity, $poison);
	}

	/**
	 * Bedrock filter "is_family mob AND (is_family axolotl OR NOT is_family aquatic)", optionally "AND NOT undead" for
	 * non-axolotls. Every living entity but players is in the "mob" family except goats and piglin brutes (their
	 * type_family lists no "mob"; armor stands do list it) and cod, salmon and tropical fish (aquatic anyway).
	 */
	private static function isNonAquaticMob(Entity $entity, bool $notUndead) : bool{
		if(!$entity instanceof Living || !$entity->isAlive() || $entity instanceof Goat || $entity instanceof PiglinBrute){
			return false;
		}
		if($entity instanceof Axolotl){
			return true;
		}
		$aquatic = $entity instanceof AbstractFish || $entity instanceof WaterAnimal || $entity instanceof Dolphin ||
			$entity instanceof Tadpole || $entity instanceof Turtle || $entity instanceof Nautilus || $entity instanceof AquaticMonster;
		return !$aquatic && (!$notUndead || !Wither::isUndead($entity));
	}

	private function hasThreatWithin(float $range) : bool{
		$bb = $this->boundingBox->expandedCopy($range, $range, $range);
		foreach($this->getWorld()->getNearbyEntities($bb, $this) as $entity){
			if(self::isThreat($entity)){
				return true;
			}
		}
		return false;
	}

	/**
	 * The Bedrock component groups, checked every 10 ticks instead of every tick:
	 * - normal_puff: a threat within 2.5 blocks starts half_puff_primary;
	 * - half_puff_primary: half puffed for 2 seconds, then half_puff_secondary;
	 * - half_puff_secondary: a threat within 2.5 blocks puffs it up fully, otherwise it deflates after 2 seconds;
	 * - full_puff: stings, and once nothing is within 2.9 blocks, deflates to half_puff_secondary 3 seconds later
	 *   without checking again (start_deflate).
	 */
	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive()){
			return $hasUpdate;
		}
		if($this->attackCooldown > 0){
			$this->attackCooldown -= $tickDiff;
		}
		if($this->puffTimer > 0){
			$this->puffTimer -= $tickDiff;
		}
		$check = ($this->server->getTick() + $this->id) % 10 === 0;
		switch($this->puffState){
			case self::PUFF_NONE:
				if($check && $this->hasThreatWithin(2.5)){
					$this->setPuffState(self::PUFF_HALF);
				}
				break;
			case self::PUFF_HALF:
				if(!$this->halfPuffSecondary){
					if($this->puffTimer <= 0){
						$this->halfPuffSecondary = true;
						$this->puffTimer = 40;
					}
				}elseif($check && $this->hasThreatWithin(2.5)){
					$this->setPuffState(self::PUFF_FULL);
				}elseif($this->puffTimer <= 0){
					$this->setPuffState(self::PUFF_NONE);
				}
				break;
			case self::PUFF_FULL:
				if($this->attackCooldown <= 0){
					$this->stingTouchingEntities();
				}
				if(!$this->deflating){
					if($check && !$this->hasThreatWithin(2.9)){
						$this->deflating = true;
						$this->puffTimer = 60;
					}
				}elseif($this->puffTimer <= 0){
					$this->setPuffState(self::PUFF_HALF);
					$this->halfPuffSecondary = true;
				}
				break;
		}
		return $hasUpdate;
	}

	private function stingTouchingEntities() : void{
		$bb = $this->boundingBox->expandedCopy(0.2, 0.2, 0.2);
		foreach($this->getWorld()->getNearbyEntities($bb, $this) as $entity){
			if(!$entity instanceof Living || !self::canSting($entity, false)){
				continue;
			}
			$ev = new EntityDamageByEntityEvent($this, $entity, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2);
			$entity->attack($ev);
			if(!$ev->isCancelled()){
				if(self::canSting($entity, true)){
					$entity->getEffects()->add(new EffectInstance(VanillaEffects::POISON(), 200));
				}
				$this->attackCooldown = 10;
			}
		}
	}

	protected function getBucketItem() : Item{
		return VanillaItems::PUFFERFISH_BUCKET();
	}

	protected function getFishDrops() : array{
		return [VanillaItems::PUFFERFISH()];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->puffState);
		$properties->setByte(EntityMetadataProperties::PUFFERFISH_SIZE, $this->puffState);
	}
}
