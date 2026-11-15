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

use pocketmine\entity\ai\goal\AvoidEntityGoal;
use pocketmine\entity\ai\goal\FlockingGoal;
use pocketmine\entity\ai\goal\RandomSwimGoal;
use pocketmine\entity\ai\SwimmingMobTrait;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\BucketableMob;
use pocketmine\entity\BucketableMobTrait;
use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use function mt_rand;

/**
 * Cod, salmon, pufferfish and tropical fish (Bedrock "fish" family): 3 health, swim around in water, flee from
 * players and axolotls, flop on land and suffocate there, and swim in schools ({@link FlockingGoal}). Caught with a
 * water bucket. XP 1-3 on player kills; 25% chance (+1% per Looting level) to drop a bone, plus 1-2 per Looting level.
 */
abstract class AbstractFish extends Mob implements BucketableMob{
	use SwimmingMobTrait;
	use BucketableMobTrait;

	private ?FlockingGoal $flocking = null;

	protected function initEntity(CompoundTag $nbt) : void{
		//fish keep the water around them as before the other swimming mobs got this cache (see SwimmingMobTrait): only
		//block changes that notify the entities around (setBlock() with updates, chunk loads) make them look it up again,
		//not the block modification counts, which cost about 2% of a fish tick (FishBenchmarkTest); a block set without
		//updates in the blocks a fish swims in is seen once it enters other blocks
		$this->waterAroundWatched = false;
		$this->setMaxHealth(3);
		$this->setMovementSpeed($this->getSwimSpeed());
		parent::initEntity($nbt);
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? mt_rand(1, 3) : 0;
	}

	/**
	 * Distance at which the fish flees from players and axolotls (Bedrock avoid_mob_type max_dist).
	 */
	protected function getAvoidDistance() : float{
		return 6.0;
	}

	/**
	 * Schooling (Bedrock minecraft:flocking of fish.json and pufferfish.json: influence radius 3, schools of 4 to 8,
	 * 10% loner chance, cohesion threshold 1.95, in water only).
	 */
	protected function createFlockingGoal() : FlockingGoal{
		return new FlockingGoal($this, 3.0, 4, 8, 0.1, 1.95);
	}

	/**
	 * @internal Returns the goal that keeps this fish in its school.
	 */
	public function getFlockingGoal() : FlockingGoal{
		return $this->flocking ??= $this->createFlockingGoal();
	}

	protected function registerGoals() : void{
		//without an axolotl in the chunks around, only players are threats: they are looked for in the player list
		//instead of the (many) entities around the fish (one block more for the axolotl's chunk counted once per tick)
		$avoidDistance = $this->getAvoidDistance();
		$this->goalSelector->addGoal(1, new AvoidEntityGoal($this, fn(Entity $e) => $e instanceof Player || $e instanceof Axolotl, $avoidDistance, 1.5, 2.0,
			onlyPlayersNow: fn() => !Axolotl::isAnyNear($this->getWorld(),$this->location->x, $this->location->z, $avoidDistance + 1)
		));
		//followers swim after their leader instead of wandering (Java canRandomSwim)
		$this->goalSelector->addGoal(2, $this->getFlockingGoal());
		//Bedrock behavior.random_swim: always swimming (interval 0), 16 blocks around, 4 up or down
		$this->goalSelector->addGoal(3, new RandomSwimGoal($this, 1.0, 0, 16, 4));
	}

	protected function onDispose() : void{
		$this->flocking?->dispose();
		$this->forgetWaterAround();
		parent::onDispose();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && $this->onGround && !$this->isInWater()){
			//flopping around on land
			$this->motion = new Vector3((Utils::getRandomFloat() * 2 - 1) * 0.05, 0.4, (Utils::getRandomFloat() * 2 - 1) * 0.05);
			$this->onGround = false;
			$this->playMobSound(LevelSoundEvent::FLOP);
			$hasUpdate = true;
		}
		return $hasUpdate;
	}

	protected function playAmbientSound() : void{
		//fish make no ambient sound
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		return $this->tryPickUpWithBucket($player, $item);
	}

	public function getDrops() : array{
		$drops = $this->getFishDrops();
		$looting = $this->getLootingLevel();
		if(Utils::getRandomFloat() < 0.25 + 0.01 * $looting){
			$drops[] = VanillaItems::BONE()->setCount(1 + self::boneLootingBonus($looting));
		}
		return $drops;
	}

	/**
	 * Extra bones of the fish loot tables (looting_enchant count 1-2 per Looting level; Java rounds level x 1..2).
	 */
	protected static function boneLootingBonus(int $looting) : int{
		return $looting > 0 ? mt_rand($looting, 2 * $looting) : 0;
	}

	/**
	 * @return Item[]
	 */
	abstract protected function getFishDrops() : array;
}
