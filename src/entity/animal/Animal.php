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

use pocketmine\entity\Ageable;
use pocketmine\entity\AgeLockTrait;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\SpawnReason;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;
use function max;
use function mt_rand;

/**
 * A passive animal that can be bred with food and has babies which grow up.
 *
 * Age follows vanilla: negative = baby (grows up at 0, after 20 minutes), positive = breeding cooldown (5 minutes).
 * Feeding the breeding food to an adult puts it in love for 30 seconds; feeding a baby makes it grow by 10% of the
 * whole growing time (Bedrock minecraft:ageable feed_items growth 0.1 of the duration). A golden dandelion stops a baby
 * from growing up ({@link AgeLockTrait}).
 */
abstract class Animal extends Mob implements Ageable{
	use AgeLockTrait;

	public const TAG_AGE = "Age"; //TAG_Int
	public const TAG_IS_BABY = "IsBaby"; //TAG_Byte
	public const TAG_IN_LOVE = "InLove"; //TAG_Int

	public const BABY_AGE = -24000;
	public const BREED_COOLDOWN = 6000;
	public const LOVE_TICKS = 600;

	protected int $age = 0;
	protected int $inLove = 0;

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$age = $nbt->getInt(self::TAG_AGE, 0);
		if($nbt->getByte(self::TAG_IS_BABY, 0) !== 0 && $age >= 0){
			$age = self::BABY_AGE;
		}
		$this->setAge($age);
		$this->inLove = max(0, $nbt->getInt(self::TAG_IN_LOVE, 0));
		$this->loadAgeLock($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_AGE, $this->age);
		$nbt->setByte(self::TAG_IS_BABY, $this->isBaby() ? 1 : 0);
		$nbt->setInt(self::TAG_IN_LOVE, $this->inLove);
		$this->saveAgeLock($nbt);
		return $nbt;
	}

	/**
	 * Returns whether the item is the food that makes this animal breed (and lures it, and makes babies grow).
	 */
	abstract public function isBreedingItem(Item $item) : bool;

	/**
	 * Animals can be led with a lead (vanilla: all of them except villagers).
	 */
	public function canBeLeashed() : bool{
		return true;
	}

	/**
	 * Creates (without spawning) the baby of this animal and the partner.
	 */
	abstract protected function createBaby(Animal $partner, Location $location) : Animal;

	public function isBaby() : bool{
		return $this->age < 0;
	}

	public function getAge() : int{
		return $this->age;
	}

	/**
	 * Scale of the adult (Bedrock "minecraft:scale"; the collision box is scaled with it).
	 */
	protected function getAdultScale() : float{
		return 1.0;
	}

	/**
	 * Scale of the baby (Bedrock "minecraft:scale" of the baby component group).
	 */
	protected function getBabyScale() : float{
		return 0.5;
	}

	/**
	 * Age of a newborn baby: minus the ticks it takes to grow up (Bedrock minecraft:ageable duration).
	 */
	public function getBabyStartAge() : int{
		return self::BABY_AGE;
	}

	/**
	 * Returns whether this animal has a baby form (frogs come from tadpoles, parrots have none).
	 */
	protected function hasBabyForm() : bool{
		return true;
	}

	/**
	 * Returns whether its babies grow up (Bedrock: the baby component group has minecraft:ageable; zombie and skeleton
	 * horse babies stay babies).
	 */
	protected function growsUp() : bool{
		return true;
	}

	public function makeSpawnBaby() : bool{
		if(!$this->hasBabyForm()){
			return false;
		}
		$this->setAge($this->getBabyStartAge());
		return true;
	}

	protected function restartGrowth() : void{
		$this->setAge($this->getBabyStartAge());
	}

	/**
	 * Collision box of the baby before its scale (Bedrock collision_box of the baby component group), or null if the
	 * baby has the adult box scaled down.
	 */
	protected function getBabySizeInfo() : ?EntitySizeInfo{
		return null;
	}

	public function setScale(float $value) : void{
		parent::setScale($value);
		if($this->isBaby() && ($baby = $this->getBabySizeInfo()) !== null){
			$this->setSize($baby->scale($value));
		}
	}

	public function setAge(int $age) : void{
		$wasBaby = $this->isBaby();
		$this->age = $age;
		if($wasBaby !== $this->isBaby() || $this->getScale() !== ($this->isBaby() ? $this->getBabyScale() : $this->getAdultScale())){
			$this->setScale($this->isBaby() ? $this->getBabyScale() : $this->getAdultScale());
			$this->networkPropertiesDirty = true;
		}
		if($wasBaby && !$this->isBaby() && $this->isAlive()){
			$this->onGrowUp();
		}
	}

	/**
	 * Makes a baby grow by the given number of ticks (not while a golden dandelion locks its age).
	 */
	public function ageUp(int $ticks) : void{
		if($this->age < 0 && !$this->isAgeLocked() && $this->growsUp()){
			$this->setAge($this->age + $ticks >= 0 ? 0 : $this->age + $ticks);
		}
	}

	public function isInLove() : bool{
		return $this->inLove > 0;
	}

	public function getLoveTicks() : int{
		return $this->inLove;
	}

	public function setInLove(int $ticks) : void{
		$wasInLove = $this->isInLove();
		$this->inLove = max(0, $ticks);
		if($wasInLove !== $this->isInLove()){
			$this->networkPropertiesDirty = true;
		}
	}

	/**
	 * Returns whether this animal can mate with the other one right now.
	 */
	public function canMate(Animal $other) : bool{
		return $other !== $this && $this->breedsWith($other) && $this->isInLove() && $other->isInLove() &&
			!$this->isBaby() && !$other->isBaby() && $other->isAlive() && !$other->isClosed();
	}

	/**
	 * Returns whether this kind of animal breeds with the other one's kind (Bedrock "breeds_with"): by default the
	 * same kind only; horses also breed with donkeys, llamas with trader llamas.
	 */
	protected function breedsWith(Animal $other) : bool{
		return $other::class === static::class;
	}

	/**
	 * Spawns a baby of the two animals, resets their love and starts their breeding cooldown. Returns the baby, or
	 * null if the animal became pregnant instead (frogs, turtles, sniffers lay eggs later).
	 */
	public function breed(Animal $partner) : ?Animal{
		$baby = $this->spawnBaby($partner);

		foreach([$this, $partner] as $parent){
			$parent->setAge(self::BREED_COOLDOWN);
			$parent->setInLove(0);
		}
		$this->getWorld()->dropExperience($this->location, mt_rand(1, 7));
		return $baby;
	}

	/**
	 * Creates and spawns the baby when two animals breed. Animals that lay eggs override this to become pregnant and
	 * return null.
	 */
	protected function spawnBaby(Animal $partner) : ?Animal{
		$baby = $this->createBaby($partner, $this->location->asLocation());
		$baby->setAge(self::BABY_AGE);
		$baby->setPersistent(true);
		$baby->setSpawnReason(SpawnReason::BREEDING);
		$baby->spawnToAll();
		return $baby;
	}

	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		if($this->isAlive() && $this->tryUseGoldenDandelion($player, $player->getInventory()->getItemInHand())){
			$this->getWorld()->addGameEvent($this->location, GameEvent::ENTITY_INTERACT, $player);
			return true;
		}
		return parent::onInteract($player, $clickPos);
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if(!$this->isBreedingItem($item)){
			return false;
		}
		if($this->isBaby()){
			if($this->isAgeLocked()){
				return false;
			}
			$this->consumeFood($player, $item);
			$this->ageUp($this->getFeedGrowth($item));
			$this->broadcastActorEvent(ActorEvent::BABY_ANIMAL_FEED);
			return true;
		}
		if($this->age === 0 && !$this->isInLove() && $this->canFallInLove($player)){
			$this->consumeFood($player, $item);
			$this->setInLove(self::LOVE_TICKS);
			$this->broadcastActorEvent(ActorEvent::LOVE_PARTICLES);
			return true;
		}
		return false;
	}

	/**
	 * Takes the food fed to the animal from the player's hand. Bucket foods (axolotls, nautiluses) leave a water
	 * bucket.
	 */
	protected function consumeFood(Player $player, Item $item) : void{
		$this->consumeHeldItem($player);
	}

	/**
	 * Returns how many ticks of growing up feeding this item to a baby saves (Bedrock feed_items growth: by default 10%
	 * of the whole growing time, i.e. 2 minutes of 20).
	 */
	protected function getFeedGrowth(Item $item) : int{
		return (int) (-$this->getBabyStartAge() / 10);
	}

	/**
	 * Returns whether feeding the breeding item may put this adult in love now (e.g. only tamed wolves at full health).
	 */
	protected function canFallInLove(Player $player) : bool{
		return true;
	}

	/**
	 * Called when the baby becomes an adult.
	 */
	protected function onGrowUp() : void{

	}

	public function broadcastActorEvent(int $event, int $data = 0) : void{
		NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [ActorEventPacket::create($this->id, $event, $data, null)]);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive()){
			if($this->age < 0){
				if(!$this->ageLocked && $this->growsUp()){
					$this->setAge(($this->age + $tickDiff) >= 0 ? 0 : $this->age + $tickDiff);
				}
			}elseif($this->age > 0){
				$this->age = max(0, $this->age - $tickDiff);
			}
			if($this->inLove > 0){
				$this->setInLove($this->inLove - $tickDiff);
			}
			if($this->ageLockTicks > 0){
				$this->tickAgeLock($tickDiff);
			}
		}
		return $hasUpdate;
	}

	public function getXpDropAmount() : int{
		return !$this->isBaby() && $this->wasRecentlyHitByPlayer() ? mt_rand(1, 3) : 0;
	}

	public function getDrops() : array{
		return $this->isBaby() ? [] : $this->getAdultDrops();
	}

	/**
	 * @return Item[]
	 */
	protected function getAdultDrops() : array{
		return [];
	}

	/**
	 * Returns the raw or cooked version of a meat drop depending on whether the animal died burning.
	 */
	protected function cookedIfBurning(Item $raw, Item $cooked) : Item{
		return $this->isOnFire() ? $cooked : $raw;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, $this->isBaby());
		$properties->setGenericFlag(EntityMetadataFlags::INLOVE, $this->isInLove());
	}
}
