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

use pocketmine\entity\Ageable;
use pocketmine\entity\AgeLockTrait;
use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\PanicGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\goal\TemptGoal;
use pocketmine\entity\ai\SwimmingMobTrait;
use pocketmine\entity\animal\Frog;
use pocketmine\entity\BucketableMob;
use pocketmine\entity\BucketableMobTrait;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Mob;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use function intdiv;

/**
 * Bedrock values (entities/tadpole.json): 6 health, 0.8x0.6, underwater speed 0.1, panics at 2x, tempted by slime
 * balls (1.25x), 8 seconds of air out of water. Grows into a frog after 20 minutes (feeding a slime ball takes 2
 * minutes off); the frog variant follows the biome. Caught with a water bucket (the bucket keeps its age). A golden dandelion
 * stops it from growing up ({@link AgeLockTrait}).
 */
class Tadpole extends Mob implements Ageable, BucketableMob{
	use SwimmingMobTrait;
	use AgeLockTrait;
	use BucketableMobTrait {
		writeBucketData as private writeBaseBucketData;
		applyBucketData as private applyBaseBucketData;
	}

	public const TAG_AGE = "Age"; //TAG_Int
	public const GROW_UP_TICKS = 24000;

	private int $age = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::TADPOLE; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.6, 0.8, 0.3);
	}

	public function getName() : string{
		return "Tadpole";
	}

	protected function getSwimSpeed() : float{
		return 0.1;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->setMaxHealth(6);
		$this->setMovementSpeed(0.1);
		$this->setMaxAirSupplyTicks(160);
		parent::initEntity($nbt);
		$this->age = $nbt->getInt(self::TAG_AGE, 0);
		$this->loadAgeLock($nbt);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_AGE, $this->age);
		$this->saveAgeLock($nbt);
		return $nbt;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(1, new PanicGoal($this, 2.0));
		$this->goalSelector->addGoal(2, new RandomStrollGoal($this, 1.0, 40, 10, 7));
		$this->goalSelector->addGoal(3, new LookAtPlayerGoal($this, 6.0));
		$this->goalSelector->addGoal(5, new TemptGoal($this, 1.25, fn(Item $item) => $item->getTypeId() === ItemTypeIds::SLIMEBALL));
	}

	public function isBaby() : bool{
		return true;
	}

	/**
	 * Ticks lived so far (it becomes a frog at {@link Tadpole::GROW_UP_TICKS}).
	 */
	public function getAge() : int{
		return $this->age;
	}

	public function setAge(int $age) : void{
		$this->age = $age;
	}

	protected function restartGrowth() : void{
		$this->age = 0;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if(!$this->isAlive() || $this->closed){
			return $hasUpdate;
		}
		if(!$this->ageLocked){
			$this->age += $tickDiff;
			if($this->age >= self::GROW_UP_TICKS){
				$this->growUp();
				return false;
			}
		}
		if($this->ageLockTicks > 0){
			$this->tickAgeLock($tickDiff);
		}
		if($this->onGround && !$this->isInWater()){
			$this->motion = new Vector3((Utils::getRandomFloat() * 2 - 1) * 0.05, 0.4, (Utils::getRandomFloat() * 2 - 1) * 0.05);
			$this->onGround = false;
			$this->playMobSound(LevelSoundEvent::FLOP);
		}
		return true;
	}

	/**
	 * Replaces the tadpole by a frog of the local variant.
	 */
	public function growUp() : Frog{
		$frog = new Frog($this->location->asLocation());
		$frog->setVariant(Frog::variantForBiome($this->getWorld(), $this->location));
		if($this->nameTag !== ""){
			$frog->setNameTag($this->nameTag);
		}
		$frog->setPersistent(true);
		$frog->spawnToAll();
		$this->flagForDespawn();
		return $frog;
	}

	protected function onInteractWithItem(Player $player, Item $item) : bool{
		if($this->tryUseGoldenDandelion($player, $item)){
			return true;
		}
		if($item->getTypeId() === ItemTypeIds::SLIMEBALL && !$this->isAgeLocked()){
			$this->consumeHeldItem($player);
			//feed_items growth (default 0.1) is a fraction of the whole growing time
			$this->age += intdiv(self::GROW_UP_TICKS, 10);
			NetworkBroadcastUtils::broadcastPackets($this->getViewers(), [ActorEventPacket::create($this->id, ActorEvent::BABY_ANIMAL_FEED, 0, null)]);
			return true;
		}
		return $this->tryPickUpWithBucket($player, $item);
	}

	protected function getBucketItem() : Item{
		return VanillaItems::TADPOLE_BUCKET();
	}

	protected function writeBucketData(CompoundTag $tag) : void{
		$this->writeBaseBucketData($tag);
		$tag->setInt(BucketableMob::BUCKET_TAG_AGE, $this->age);
		$this->saveAgeLock($tag);
	}

	public function applyBucketData(CompoundTag $tag) : void{
		$this->applyBaseBucketData($tag);
		$this->age = $tag->getInt(BucketableMob::BUCKET_TAG_AGE, $this->age);
		$this->loadAgeLock($tag);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::BABY, true);
	}
}
