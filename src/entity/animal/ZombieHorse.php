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

namespace pocketmine\entity\animal;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\monster\Undead;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function mt_rand;

/**
 * Bedrock values (entities/zombie_horse.json, 1.21.130): 25 health, movement 0.205-0.275, 1.4x1.6, tempted by red
 * mushrooms (which also heal it, 3 health, and raise its temper by 10). Passive. Burns in daylight unless it wears horse
 * armor. Tamed by riding it like a horse
 * (vanilla tamemount); a wild one may carry an adult zombie rider. Natural spawning with a zombie rider is not
 * implemented. Can't breed. Wears horse armor like a horse (saddle and armor slots, like
 * GeyserMC's zombie horse screen).
 * Loot (loot_tables/entities/zombie_horse.json): 2-3 rotten flesh (+0-1 per Looting level); XP 1-3.
 */
class ZombieHorse extends AbstractHorse implements Undead{

	public function hasArmorSlot() : bool{
		return true;
	}

	public static function getNetworkTypeId() : string{ return EntityIds::ZOMBIE_HORSE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.6, 1.4, 1.52);
	}

	public function getName() : string{
		return "Zombie Horse";
	}

	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return new EntitySizeInfo(2.24, 1.96, 2.128); //baby collision box 1.96 x 2.24 at scale 0.5
	}

	public function getSpawnBabyChance() : int{
		return 20; //entity_spawned: 36 adult / 9 baby
	}

	protected function canBeAgeLocked() : bool{
		return false; //no golden dandelion in its ageable component
	}

	protected function growsUp() : bool{
		return false; //minecraft:horse_baby has no minecraft:ageable
	}

	protected function randomStats() : array{
		return [25, self::randomRange(0.205, 0.275), self::randomRange(0.5, 0.7)];
	}

	public function canBreathe() : bool{
		return true;
	}

	protected function panicsWhenHurt() : bool{
		return false; //no behavior.panic (it floats like a horse)
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		//vanilla family types: also an adult zombie ("zombie_rider") on a wild one
		return parent::canBeRiddenBy($entity) || (!$this->isBaby() && !$this->isTamed() && $entity instanceof Zombie && !$entity->isBaby());
	}

	protected function isTemptItem(Item $item) : bool{
		return $item->getTypeId() === VanillaBlocks::RED_MUSHROOM()->asItem()->getTypeId();
	}

	public function isBreedingItem(Item $item) : bool{
		return false;
	}

	protected function getFood(Item $item) : ?array{
		return $this->isTemptItem($item) ? [3, 0.0, 10] : null;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new ZombieHorse($location);
	}

	/**
	 * Vanilla burns_in_daylight with protection_slot slot.armor.body: it catches fire in the sun (checked like monsters,
	 * see {@link Monster::isEntityInDaylight()}) unless it wears horse armor.
	 */
	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(($this->server->getTick() + $this->id) % 5 === 0 && !$this->closed && $this->isAlive() && !$this->isOnFire() &&
			mt_rand(0, 4) === 0 && $this->getHorseArmor()->isNull() && Monster::isEntityInDaylight($this)
		){
			$this->setOnFire(8);
		}
		return $hasUpdate;
	}

	protected function getAdultDrops() : array{
		return [VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(2, 3) + $this->lootingBonus())];
	}
}
