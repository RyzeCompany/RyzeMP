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
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Planks;
use pocketmine\entity\Entity;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\player\Player;

/**
 * Spears (https://minecraft.wiki/w/Spear). Jab = normal attack; holding "use" charges and hits whatever the spear
 * points at, dealing damage proportional to the relative speed.
 *
 * Bedrock stats per tier (jab damage / durability / enchantability = the tier's sword damage - 3 / tool durability /
 * tool enchantability):
 * wooden 2/60/15, stone 3/132/5, copper 3/191/13, iron 4/251/14, golden 2/33/22, diamond 5/1562/10,
 * netherite 6/2032/15. The jab starts a use cooldown shared by all spears (13/15/17/19/19/21/23 ticks).
 *
 * Charge attack: after an activation delay the spear is "engaged" (damage + knockback, and the target is knocked off
 * its mount), then "tired" (damage + knockback), then "disengaged" (damage only), then the charge
 * ends. Damage = tier multiplier * relative speed in blocks/second, not affected by Strength or Weakness; Bedrock waits
 * 10 ticks between charge hits.
 * Not documented for Bedrock, so Java values are used: minimum speed for damage 4.6 b/s and for knockback 5.1 b/s
 * (wooden spear values, applied to every tier); durability loss per hit (1, like swords).
 *
 * Lunge (https://minecraft.wiki/w/Lunge): each jab pushes the player 0.458 blocks/tick per level horizontally along
 * where it looks, costs 4 exhaustion per level and 1 durability; needs at least 7 hunger points and doesn't work
 * while gliding or in water.
 */
class Spear extends TieredTool implements Releasable{

	public const CHARGE_HIT_INTERVAL_TICKS = 10;
	public const CHARGE_MIN_DAMAGE_SPEED = 4.6;
	public const CHARGE_MIN_KNOCKBACK_SPEED = 5.1;
	public const CHARGE_REACH = 4.0;

	public const LUNGE_IMPULSE_PER_LEVEL = 0.458;
	public const LUNGE_EXHAUSTION_PER_LEVEL = 4.0;
	public const LUNGE_MIN_FOOD = 7;

	public const STAGE_NONE = 0; //activation delay
	public const STAGE_ENGAGED = 1;
	public const STAGE_TIRED = 2;
	public const STAGE_DISENGAGED = 3;
	public const STAGE_ENDED = 4;

	public function getAttackPoints() : int{
		return $this->tier->getBaseAttackPoints() - 3;
	}

	public function getCooldownTag() : ?string{
		return ItemCooldownTags::SPEAR;
	}

	/**
	 * Returns the use cooldown started by a jab, in ticks (Bedrock).
	 */
	public function getJabCooldownTicks() : int{
		return match($this->tier){
			ToolTier::WOOD => 13,
			ToolTier::STONE => 15,
			ToolTier::COPPER => 17,
			ToolTier::IRON, ToolTier::GOLD => 19,
			ToolTier::DIAMOND => 21,
			ToolTier::NETHERITE => 23,
		};
	}

	/**
	 * Returns the charge damage per block/second of relative speed.
	 */
	public function getChargeDamageMultiplier() : float{
		return match($this->tier){
			ToolTier::WOOD, ToolTier::GOLD => 0.7,
			ToolTier::STONE, ToolTier::COPPER => 0.82,
			ToolTier::IRON => 0.95,
			ToolTier::DIAMOND => 1.075,
			ToolTier::NETHERITE => 1.2,
		};
	}

	/**
	 * Returns the ticks the use button must be held before the charge starts hitting, followed by the durations of
	 * the engaged, tired and disengaged stages.
	 *
	 * @return int[]
	 * @phpstan-return array{int, int, int, int}
	 */
	public function getChargeTimings() : array{
		return match($this->tier){
			ToolTier::WOOD => [15, 100, 100, 100],
			ToolTier::STONE => [14, 90, 90, 95],
			ToolTier::COPPER => [13, 80, 85, 85],
			ToolTier::IRON => [12, 50, 85, 90],
			ToolTier::GOLD => [14, 70, 100, 105],
			ToolTier::DIAMOND => [10, 60, 70, 70],
			ToolTier::NETHERITE => [8, 50, 60, 65],
		};
	}

	/**
	 * Returns the charge stage (STAGE_*) after holding the spear for the given number of ticks.
	 */
	public function getChargeStage(int $useDuration) : int{
		[$delay, $engaged, $tired, $disengaged] = $this->getChargeTimings();
		return match(true){
			$useDuration < $delay => self::STAGE_NONE,
			$useDuration < $delay + $engaged => self::STAGE_ENGAGED,
			$useDuration < $delay + $engaged + $tired => self::STAGE_TIRED,
			$useDuration < $delay + $engaged + $tired + $disengaged => self::STAGE_DISENGAGED,
			default => self::STAGE_ENDED
		};
	}

	/**
	 * Returns the damage of a charge hit at the given relative speed (blocks/second), or 0 if too slow.
	 */
	public function calculateChargeDamage(float $relativeSpeed) : float{
		if($relativeSpeed < self::CHARGE_MIN_DAMAGE_SPEED){
			return 0.0;
		}
		return $this->getChargeDamageMultiplier() * $relativeSpeed;
	}

	public static function canChargeKnockBack(int $stage, float $relativeSpeed) : bool{
		return ($stage === self::STAGE_ENGAGED || $stage === self::STAGE_TIRED) && $relativeSpeed >= self::CHARGE_MIN_KNOCKBACK_SPEED;
	}

	/**
	 * Returns the horizontal speed (blocks/tick) a jab with Lunge gives, or 0 without Lunge.
	 */
	public function getLungeImpulse() : float{
		return self::LUNGE_IMPULSE_PER_LEVEL * $this->getEnchantmentLevel(VanillaEnchantments::LUNGE());
	}

	public function canStartUsingItem(Player $player) : bool{
		return !$player->hasItemCooldown($this);
	}

	/**
	 * Returns whether the given item can repair this spear in an anvil.
	 */
	public function isValidRepairMaterial(Item $item) : bool{
		return match($this->tier){
			ToolTier::WOOD => $item instanceof ItemBlock && $item->getBlock() instanceof Planks,
			ToolTier::STONE => $item instanceof ItemBlock && match($item->getBlock()->getTypeId()){
				BlockTypeIds::COBBLESTONE, BlockTypeIds::BLACKSTONE, BlockTypeIds::COBBLED_DEEPSLATE => true,
				default => false
			},
			ToolTier::COPPER => $item->getTypeId() === ItemTypeIds::COPPER_INGOT,
			ToolTier::IRON => $item->getTypeId() === ItemTypeIds::IRON_INGOT,
			ToolTier::GOLD => $item->getTypeId() === ItemTypeIds::GOLD_INGOT,
			ToolTier::DIAMOND => $item->getTypeId() === ItemTypeIds::DIAMOND,
			ToolTier::NETHERITE => $item->getTypeId() === ItemTypeIds::NETHERITE_INGOT,
		};
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
}
