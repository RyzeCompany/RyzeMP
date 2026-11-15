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

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\player\Player;
use pocketmine\world\particle\WindExplosionParticle;
use pocketmine\world\WindBurst;
use function max;
use function min;

/**
 * A heavy weapon whose smash attack (hitting while falling) deals more damage the further the attacker fell.
 *
 * Vanilla (https://minecraft.wiki/w/Mace): 6 attack damage, 500 durability on Java and 501 on Bedrock, repaired with
 * breeze rods, enchantability 15, no attack cooldown on Bedrock.
 * Smash attack: when falling more than 1.5 blocks, +4 damage per block for the first 3 blocks, +2 per block for the
 * next 5 and +1 per block beyond that; Density adds 0.5 per block per level. A smash resets the attacker's fall distance
 * and knocks back entities around the target; Wind Burst then launches the attacker. Unlike Java, Bedrock also smashes
 * while gliding with an elytra; creative flight doesn't fall, so it never smashes.
 */
class Mace extends Tool{

	public const SMASH_MIN_FALL_DISTANCE = 1.5;
	/** Fall distance above which the smash is "heavy" (stronger area knockback, heavy sound). */
	public const HEAVY_SMASH_FALL_DISTANCE = 5.0;
	/** Radius around the target in which other entities are knocked back by a smash (Java MaceItem). */
	public const SMASH_KNOCKBACK_RADIUS = 3.5;
	/** Breach: armor effectiveness lost per level (15 percentage points). */
	public const BREACH_ARMOR_REDUCTION_PER_LEVEL = 0.15;
	/** Wind Burst: knockback multiplier for levels I, II and III (https://minecraft.wiki/w/Wind_Burst). */
	private const WIND_BURST_MULTIPLIERS = [1 => 1.2, 2 => 1.75, 3 => 2.2];
	/** Wind Burst: burst radius (Java WindBurst enchantment effect). */
	public const WIND_BURST_RADIUS = 3.5;

	public function getMaxDurability() : int{
		return 501;
	}

	public function getAttackPoints() : int{
		return 6;
	}

	public function getEnchantability() : int{
		return 15;
	}

	/**
	 * Returns whether the given item can repair this mace in an anvil.
	 */
	public function isValidRepairMaterial(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::BREEZE_ROD;
	}

	/**
	 * Returns whether an attack made now by the player is a smash attack.
	 */
	public function canSmashAttack(Player $attacker) : bool{
		return $attacker->getFallDistance() > self::SMASH_MIN_FALL_DISTANCE && !$attacker->isFlying();
	}

	/**
	 * Returns the damage a smash attack adds for the given fall distance and Density level.
	 */
	public static function calculateSmashDamageBonus(float $fallDistance, int $densityLevel = 0) : float{
		if($fallDistance <= self::SMASH_MIN_FALL_DISTANCE){
			return 0.0;
		}
		$bonus = 4 * min($fallDistance, 3) + 2 * max(0, min($fallDistance, 8) - 3) + max(0, $fallDistance - 8);
		return $bonus + 0.5 * $densityLevel * $fallDistance;
	}

	/**
	 * Returns the fraction of armor effectiveness the target loses against this mace (Breach).
	 */
	public function getArmorEffectivenessReduction() : float{
		return self::BREACH_ARMOR_REDUCTION_PER_LEVEL * $this->getEnchantmentLevel(VanillaEnchantments::BREACH());
	}

	/**
	 * Returns the Wind Burst knockback multiplier, or 0 if the mace doesn't have Wind Burst.
	 */
	public function getWindBurstMultiplier() : float{
		$level = $this->getEnchantmentLevel(VanillaEnchantments::WIND_BURST());
		if($level <= 0){
			return 0.0;
		}
		//levels above III (plugins) keep growing by the III - II step
		return self::WIND_BURST_MULTIPLIERS[$level] ?? self::WIND_BURST_MULTIPLIERS[3] + ($level - 3) * 0.45;
	}

	/**
	 * Returns the velocity added to an entity at the given distance from the smash target (0 if out of range).
	 */
	public static function calculateSmashKnockback(float $distance, float $fallDistance, float $knockbackResistance = 0.0) : float{
		if($distance > self::SMASH_KNOCKBACK_RADIUS){
			return 0.0;
		}
		return (self::SMASH_KNOCKBACK_RADIUS - $distance) * 0.7 * ($fallDistance > self::HEAVY_SMASH_FALL_DISTANCE ? 2 : 1) * (1 - $knockbackResistance);
	}

	public function onAttackEntity(Entity $victim, array &$returnedItems) : bool{
		return $this->applyDamage(1);
	}

	public function onDestroyBlock(Block $block, array &$returnedItems) : bool{
		if(!$block->getBreakInfo()->breaksInstantly()){
			return $this->applyDamage(2);
		}
		return false;
	}

	/**
	 * Called after a smash attack hit: knocks back the entities around the target (not the attacker) and applies
	 * Wind Burst.
	 */
	public function onSmashAttack(Player $attacker, Entity $target, float $fallDistance) : void{
		$world = $target->getWorld();
		$targetPos = $target->getPosition();
		foreach($world->getNearbyEntities($target->getBoundingBox()->expandedCopy(self::SMASH_KNOCKBACK_RADIUS, self::SMASH_KNOCKBACK_RADIUS, self::SMASH_KNOCKBACK_RADIUS), $target) as $entity){
			if($entity === $attacker || !$entity instanceof Living || !$entity->isAlive() || ($entity instanceof Player && $entity->isSpectator())){
				continue;
			}
			$offset = $entity->getPosition()->subtractVector($targetPos);
			$strength = self::calculateSmashKnockback($offset->length(), $fallDistance);
			if($strength <= 0){
				continue;
			}
			$horizontal = $offset->withComponents(null, 0, null);
			$horizontalLength = $horizontal->length();
			$push = $horizontalLength > 0.0001 ? $horizontal->divide($horizontalLength)->multiply($strength) : $horizontal;
			if($entity->setMotion($entity->getMotion()->add($push->x, 0.7, $push->z)) && $entity instanceof Mob){
				$entity->carryPush();
			}
		}

		$windBurst = $this->getWindBurstMultiplier();
		if($windBurst > 0){
			$world->addParticle($attacker->getPosition(), new WindExplosionParticle());
			WindBurst::apply($world, $attacker->getPosition(), self::WIND_BURST_RADIUS, $windBurst, false, $target);
		}
	}
}
