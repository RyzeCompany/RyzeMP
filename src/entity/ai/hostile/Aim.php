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

namespace pocketmine\entity\ai\hostile;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use function atan2;
use function intdiv;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;
use const M_PI;

/**
 * Aiming helpers for mobs shooting projectiles (arrows, tridents, potions, fireballs, wind charges).
 */
final class Aim{

	private function __construct(){
		//NOOP
	}

	/**
	 * Approximately normally distributed random number (mean 0, deviation 1).
	 */
	public static function gaussian() : float{
		return (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() - 1.5) * 2;
	}

	/**
	 * Vanilla inaccuracy of mob projectiles: (14 - difficulty * 4) * 0.0075 per axis.
	 */
	public static function mobInaccuracy(World $world) : float{
		return (14 - $world->getDifficulty() * 4) * 0.0075;
	}

	/**
	 * Returns the motion of a projectile shot from $from at $speed blocks per tick towards $to, randomly deviated by
	 * $inaccuracy. $lift raises the aim by that fraction of the horizontal distance to make up for gravity (arrows:
	 * 0.2).
	 */
	public static function motionTowards(Vector3 $from, Vector3 $to, float $speed, float $inaccuracy = 0.0, float $lift = 0.0) : ?Vector3{
		$dx = $to->x - $from->x;
		$dy = $to->y - $from->y;
		$dz = $to->z - $from->z;
		$dy += sqrt($dx * $dx + $dz * $dz) * $lift;
		$len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($len < 0.0001){
			return null;
		}
		return new Vector3(
			($dx / $len + self::gaussian() * $inaccuracy) * $speed,
			($dy / $len + self::gaussian() * $inaccuracy) * $speed,
			($dz / $len + self::gaussian() * $inaccuracy) * $speed
		);
	}

	/**
	 * Returns the point a projectile should be aimed at on the target: a third of its height (vanilla), or its eyes.
	 */
	public static function targetPoint(Entity $target, float $heightFraction = 1 / 3) : Vector3{
		$loc = $target->getLocation();
		return new Vector3($loc->x, $loc->y + $target->getSize()->getHeight() * $heightFraction, $loc->z);
	}

	/**
	 * Returns a location at $pos facing along $motion (projectile yaw/pitch conventions).
	 */
	public static function facing(Vector3 $pos, World $world, Vector3 $motion) : Location{
		$yaw = atan2($motion->z, $motion->x) / M_PI * 180 - 90;
		$pitch = -atan2($motion->y, sqrt($motion->x ** 2 + $motion->z ** 2)) / M_PI * 180;
		return Location::fromObject($pos, $world, $yaw, $pitch);
	}

	/**
	 * Shoots an arrow from the shooter's eyes at the target like a skeleton (speed 1.6, aimed above the target for
	 * gravity), optionally tipped with a potion. Returns the arrow (already spawned), or null if the target is at the
	 * shooter's eyes.
	 *
	 * The enchantments of $bow (the bow the mob holds) apply like a player's: Power adds damage, Punch knockback, Flame
	 * sets the arrow on fire.
	 */
	public static function shootArrow(Living $shooter, Living $target, ?PotionType $potion = null, float $speed = 1.6, ?Item $bow = null) : ?Arrow{
		$world = $shooter->getWorld();
		$eye = $shooter->getEyePos()->subtract(0, 0.1, 0);
		$motion = self::motionTowards($eye, self::targetPoint($target), $speed, self::mobInaccuracy($world), 0.2);
		if($motion === null){
			return null;
		}
		$arrow = new Arrow(self::facing($eye, $world, $motion), $shooter, false);
		$arrow->setPickupMode(Arrow::PICKUP_NONE);
		$arrow->setBaseDamage(2.0 + $world->getDifficulty() * 0.11 + self::gaussian() * 0.25);
		if($potion !== null){
			$arrow->setPotionType($potion);
		}
		if($bow !== null && $bow->hasEnchantments()){
			self::applyBowEnchantments($arrow, $bow);
		}
		$arrow->setMotion($motion);
		$arrow->spawnToAll();
		return $arrow;
	}

	/**
	 * Applies the enchantments of a bow to an arrow it shoots, like {@link \pocketmine\item\Bow::onReleaseUsing()}.
	 */
	public static function applyBowEnchantments(Arrow $arrow, Item $bow) : void{
		if(($punchLevel = $bow->getEnchantmentLevel(VanillaEnchantments::PUNCH())) > 0){
			$arrow->setPunchKnockback($punchLevel);
		}
		if(($powerLevel = $bow->getEnchantmentLevel(VanillaEnchantments::POWER())) > 0){
			$arrow->setBaseDamage($arrow->getBaseDamage() + (($powerLevel + 1) / 2));
		}
		if($bow->hasEnchantment(VanillaEnchantments::FLAME())){
			$arrow->setOnFire(intdiv($arrow->getFireTicks(), 20) + 100);
		}
	}
}
