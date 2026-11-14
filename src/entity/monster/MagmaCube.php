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

use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function mt_rand;

/**
 * Bedrock values (magma_cube.json): a fire immune slime that takes no fall damage and floats in lava. Size 4: 16
 * health, movement 0.75, 6 damage; size 2: 4 health, 0.66, 4 damage; size 1: 1 health, 0.6, 3 damage (even small
 * ones hurt; other sizes: size + 2). Targets and hurts players and iron golems (not snow golems). Hops every 2-6
 * seconds (0.67-2 with a target), higher than slimes (Java: +0.1 jump per size; in lava 0.22 + 0.05 per size).
 * Loot (sizes 2 and 4): 0-1 magma cream (+0-1 per Looting level).
 */
class MagmaCube extends Slime{
	public static function getNetworkTypeId() : string{ return EntityIds::MAGMA_CUBE; }

	protected function getFreezeDamage() : float{
		return 5.0; //vanilla freeze_hurts_extra_types
	}

	public function getName() : string{
		return "Magma Cube";
	}

	protected function getSpeedForSize() : float{
		return match($this->getSlimeSize()){
			1 => 0.6,
			2 => 0.66,
			default => 0.75
		};
	}

	public function getContactDamage() : float{
		return $this->getSlimeSize() + 2.0;
	}

	protected function getCalmJumpDelay() : array{
		return [40, 120];
	}

	protected function getAggressiveJumpDelay() : array{
		return [13, 40];
	}

	protected function getJumpVelocityForHop() : float{
		return 0.42 + $this->getSlimeSize() * 0.1;
	}

	protected function isGolemTarget(Living $entity) : bool{
		return $entity instanceof IronGolem;
	}

	protected function getLiquidJumpVelocity() : ?float{
		return $this->isInLava() ? 0.22 + $this->getSlimeSize() * 0.05 : null;
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0; //damage_sensor: fall deals no damage
	}

	public function isFireProof() : bool{
		return true;
	}

	protected function createChild(Location $location, int $size) : Slime{
		return new MagmaCube($location, null, $size);
	}

	public function getDrops() : array{
		if($this->getSlimeSize() < 2){
			return [];
		}
		return [VanillaItems::MAGMA_CREAM()->setCount(mt_rand(0, 1) + $this->lootingBonus())];
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::MAGMA_CUBE_SPAWN_EGG();
	}
}
