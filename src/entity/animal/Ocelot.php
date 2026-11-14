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

use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\BreedGoal;
use pocketmine\entity\ai\goal\FloatGoal;
use pocketmine\entity\ai\goal\FollowParentGoal;
use pocketmine\entity\ai\goal\LeapAtTargetGoal;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\SpawnReason;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use function in_array;
use function mt_rand;

/**
 * Bedrock values (entities/ocelot.json): 10 health, 0.6x0.7, movement 0.3. Wild ocelots flee from players (10
 * blocks), are tempted by raw cod and salmon (0.5x) and hunt chickens and baby turtles. Feeding them fish makes them
 * trust the player (1 in 3, TRUSTING flag): trusting ocelots don't flee and can be bred with fish. They can't be
 * tamed (since 1.14). No fall damage. No loot; XP 1-3.
 * An adult wild ocelot that spawns (naturally or from a spawn egg) comes with two trusting kittens 1 time in 7
 * (wild_child_ocelot_spawn, {@link Ocelot::TWINS_CHANCE}).
 */
class Ocelot extends Animal{
	public const TAG_TRUSTING = "IsTrusting"; //TAG_Byte

	/** wild_child_ocelot_spawn: random_chance 7, num_to_spawn 2 */
	public const TWINS_CHANCE = 7;
	public const TWINS_COUNT = 2;

	private bool $trusting = false;
	/** Created without saved data and not ticked yet (see onFirstUpdate()). */
	private bool $freshSpawn = false;

	public static function getNetworkTypeId() : string{ return EntityIds::OCELOT; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.7, 0.6, 0.5);
	}

	public function getName() : string{
		return "Ocelot";
	}

	public function getSpawnBabyChance() : int{
		return 25; //entity_spawned: 3 adult / 1 baby
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(10);
		$this->setMovementSpeed(0.3);
		parent::initEntity($nbt);
		$this->freshSpawn = $nbt->getTag(self::TAG_TRUSTING) === null;
		$this->trusting = $nbt->getByte(self::TAG_TRUSTING, 0) !== 0;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setByte(self::TAG_TRUSTING, $this->trusting ? 1 : 0);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(0, new FloatGoal($this));
		$this->goalSelector->addGoal(1, new PanicGoal($this, 1.25));
		$this->goalSelector->addGoal(3, new LeapAtTargetGoal($this, 0.3));
		$this->goalSelector->addGoal(3, new BreedGoal($this, 1.0));
		$this->goalSelector->addGoal(4, new MeleeAttackGoal($this, 0.8, 3.0, reachMultiplier: 2.0)); //ocelotattack
		//within_radius 16; wild (not trusting) ocelots can_get_scared
		$this->goalSelector->addGoal(4, new TemptGoal($this, 0.5, fn(Item $item) => $this->isBreedingItem($item), 16.0, canGetScared: fn() => !$this->trusting));
		$this->goalSelector->addGoal(5, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Player, 10.0, 0.8, 1.33, fn() => !$this->trusting, true));
		$this->goalSelector->addGoal(6, new FollowParentGoal($this, 1.1));
		$this->goalSelector->addGoal(8, new RandomStrollGoal($this, 0.8));
		$this->goalSelector->addGoal(9, new LookAtPlayerGoal($this, 6.0));
		//the prey are chickens and baby turtles: no search in worlds without either (the filter would turn every entity down)
		$this->targetSelector->addGoal(1, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Chicken || ($e instanceof Turtle && $e->isBaby() && !$e->isInWater()), 8.0, condition: $this->worldHasPrey(...), reselect: true));
	}

	private function worldHasPrey() : bool{
		$world = $this->getWorld();
		return $world->hasEntitiesOfClass(Chicken::class) || $world->hasEntitiesOfClass(Turtle::class);
	}

	public function isTrusting() : bool{
		return $this->trusting;
	}

	public function setTrusting(bool $trusting) : void{
		$this->trusting = $trusting;
		$this->networkPropertiesDirty = true;
	}

	public function isBreedingItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::RAW_FISH || $item->getTypeId() === ItemTypeIds::RAW_SALMON;
	}

	protected function canFallInLove(Player $player) : bool{
		return $this->trusting;
	}

	protected function createBaby(Animal $partner, Location $location) : Animal{
		$baby = new Ocelot($location);
		$baby->setTrusting(true);
		return $baby;
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		return 0; //damage_sensor: fall deals no damage
	}

	protected function onFirstUpdate(int $currentTick) : void{
		parent::onFirstUpdate($currentTick);
		if($this->freshSpawn){
			$this->freshSpawn = false;
			//entity_spawned of an adult (wild) ocelot: wild_child_ocelot_spawn
			if(!$this->isBaby() && !$this->trusting && !$this->isClosed() &&
				in_array($this->getSpawnReason(), [SpawnReason::NATURAL, SpawnReason::SPAWN_EGG], true) &&
				mt_rand(1, self::TWINS_CHANCE) === 1
			){
				$this->spawnTwins();
			}
		}
	}

	/**
	 * Spawns the two kittens of wild_child_ocelot_spawn (spawn_event entity_born: trusting babies) where the ocelot is.
	 */
	private function spawnTwins() : void{
		for($i = 0; $i < self::TWINS_COUNT; ++$i){
			$baby = new Ocelot($this->location->asLocation());
			$baby->setTrusting(true);
			$baby->setAge(self::BABY_AGE);
			$baby->setPersistent($this->isPersistent());
			$baby->setSpawnReason(SpawnReason::BREEDING);
			$baby->spawnToAll();
		}
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->trusting && $this->isBreedingItem($item) && !$this->isBaby()){
			$this->consumeHeldItem($player);
			if(Utils::getRandomFloat() < 1 / 3){
				$this->setTrusting(true);
				$this->broadcastActorEvent(ActorEvent::TAME_SUCCESS);
			}else{
				$this->broadcastActorEvent(ActorEvent::TAME_FAIL);
			}
			return true;
		}
		return parent::onInteractWithItem($player, $item);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::TRUSTING, $this->trusting);
	}
}
