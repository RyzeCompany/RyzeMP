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

namespace pocketmine\entity\projectile;

use pocketmine\entity\monster\Breeze;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\ProjectileHitEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\GameRules;
use pocketmine\world\particle\BreezeWindExplosionParticle;
use pocketmine\world\sound\BreezeWindChargeBurstSound;
use pocketmine\world\WindBurst;

/**
 * Wind charge shot by a breeze (minecraft:breeze_wind_charge_projectile): 1 damage on a direct hit, and a burst with
 * explode power 3, knockback_scaling 0.6 and normal fall damage (negates_fall_damage false), particle
 * breeze_wind_burst and sound breeze_wind_charge.burst. It toggles blocks like a player's charge, but only when mob
 * griefing is allowed (Java ServerExplosion.canTriggerBlocks()). The breeze that shot it is not pushed by its burst.
 * Hitting it sends it back where the attacker looks (reflect_on_hurt, no reflect_immunity).
 */
class BreezeWindCharge extends WindCharge implements NeverSavedWithChunkEntity{
	public const BURST_RADIUS = 3.0;
	public const BURST_KNOCKBACK_MULTIPLIER = 0.6;

	public static function getNetworkTypeId() : string{ return EntityIds::BREEZE_WIND_CHARGE_PROJECTILE; }

	protected function getReflectImmunityTicks() : int{
		return 0;
	}

	protected function onHit(ProjectileHitEvent $event) : void{
		if($this->burst){
			return;
		}
		$this->burst = true;
		$world = $this->getWorld();
		$owner = $this->getOwningEntity();
		WindBurst::apply(
			$world,
			$this->getBurstCenter($event),
			self::BURST_RADIUS,
			self::BURST_KNOCKBACK_MULTIPLIER,
			false,
			$owner instanceof Breeze ? $owner : null,
			new BreezeWindChargeBurstSound(),
			new BreezeWindExplosionParticle(),
			GameRules::allowsMobGriefing($world)
		);
	}
}
