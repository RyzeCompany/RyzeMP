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

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\monster\Undead;
use pocketmine\entity\monster\WitherSkeleton;
use pocketmine\entity\object\LightningBolt;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\Zombie;
use pocketmine\event\world\LightningStrikeEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use function mt_rand;

/**
 * Bedrock values (entities/skeleton_horse.json): 15 health, movement 0.2, 1.4x1.6, breathes under water. Passive.
 * Needs no taming nor a saddle (is_tamed, input_ground_controlled and can_power_jump are not in component groups): it
 * is ridden and steered right away (by players, skeletons and zombies), takes no saddle and has no inventory screen.
 * Can't breed; its babies never grow up (no minecraft:ageable).
 * Skeleton trap: a natural lightning strike may spawn a trap horse instead (see
 * {@link \pocketmine\world\weather\Weather}). When a player comes within 10 blocks, a visual lightning bolt strikes
 * and the trap turns into four skeleton horsemen (this horse and three more, each ridden by a skeleton with an iron
 * helmet). An untriggered trap disappears after 15 minutes.
 * Loot (loot_tables/entities/skeleton_horse.json): 0-2 bones (+0-1 per Looting level); XP 1-3.
 */
class SkeletonHorse extends AbstractHorse implements Undead{
	public const TAG_TRAP = "SkeletonTrap"; //TAG_Byte
	public const TAG_TRAP_TIME = "SkeletonTrapTime"; //TAG_Int

	public const TRAP_RADIUS = 10.0;
	public const TRAP_LIFETIME = 18000;
	public const TRAP_HORSEMEN = 4;

	private bool $trap = false;
	private int $trapTime = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::SKELETON_HORSE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.6, 1.4, 1.52);
	}

	public function getName() : string{
		return "Skeleton Horse";
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
		return false; //minecraft:skeleton_horse_baby has no minecraft:ageable
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->trap = $nbt->getByte(self::TAG_TRAP, 0) !== 0;
		$this->trapTime = $nbt->getInt(self::TAG_TRAP_TIME, 0);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_TRAP, $this->trap ? 1 : 0);
		$nbt->setInt(self::TAG_TRAP_TIME, $this->trapTime);
		return $nbt;
	}

	protected function randomStats() : array{
		return [15, 0.2, self::randomRange(0.4, 1.0)];
	}

	public function canBreathe() : bool{
		return true;
	}

	protected function floatsInWater() : bool{
		return false; //it walks on the bottom (is_amphibious, no behavior.float)
	}

	protected function panicsWhenHurt() : bool{
		return false;
	}

	public function isTameable() : bool{
		return false;
	}

	protected function isSteeredWithoutSaddle() : bool{
		return true;
	}

	public function canWearSaddle() : bool{
		return false; //no equippable component
	}

	public function canOpenInventory(Player $player) : bool{
		return false; //no inventory component
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setByte(EntityMetadataProperties::CONTAINER_TYPE, 0);
	}

	/**
	 * Whether this is a skeleton trap waiting for a player.
	 */
	public function isTrap() : bool{
		return $this->trap;
	}

	public function setTrap(bool $trap) : void{
		$this->trap = $trap;
		$this->trapTime = 0;
		if($trap){
			$this->setPersistent(true);
		}
	}

	public function canBeRiddenBy(Entity $entity) : bool{
		if($this->isBaby()){
			return false;
		}
		return $entity instanceof Player || $entity instanceof Skeleton || $entity instanceof WitherSkeleton || ($entity instanceof Zombie && !$entity->isBaby());
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->trap || $this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		$this->trapTime += $tickDiff;
		if($this->trapTime >= self::TRAP_LIFETIME){
			$this->flagForDespawn();
			return false;
		}
		if(($this->server->getTick() + $this->id) % 10 === 0 && $this->sensing->getNearestPlayer(self::TRAP_RADIUS) !== null){
			$this->springTrap();
		}
		return true;
	}

	/**
	 * Sets off the skeleton trap (vanilla SkeletonTrapGoal): a visual lightning bolt, then this horse and three more
	 * appear ridden by skeletons with iron helmets, the new ones pushed away a little.
	 */
	public function springTrap() : void{
		if(!$this->trap){
			return;
		}
		$this->trap = false;
		$this->setAge(0);
		LightningBolt::strike($this->location->asLocation(), true, LightningStrikeEvent::CAUSE_TRAP);
		$this->addHorseman($this);
		for($i = 1; $i < self::TRAP_HORSEMEN; ++$i){
			$horse = new SkeletonHorse(Location::fromObject($this->location, $this->getWorld(), $this->location->yaw, 0));
			$horse->setPersistent(true);
			$horse->spawnToAll();
			$horse->setMotion($horse->getMotion()->add(
				(Utils::getRandomFloat() - Utils::getRandomFloat()) * 1.1485,
				0,
				(Utils::getRandomFloat() - Utils::getRandomFloat()) * 1.1485
			));
			$this->addHorseman($horse);
		}
	}

	private function addHorseman(SkeletonHorse $horse) : void{
		$skeleton = new Skeleton($horse->getLocation());
		$skeleton->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		Jockeys::addRider($horse, $skeleton);
	}

	protected function isTemptItem(Item $item) : bool{
		return false;
	}

	public function isBreedingItem(Item $item) : bool{
		return false;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		return new SkeletonHorse($location);
	}

	protected function getAdultDrops() : array{
		$count = mt_rand(0, 2) + $this->lootingBonus();
		return $count > 0 ? [VanillaItems::BONE()->setCount($count)] : [];
	}
}
