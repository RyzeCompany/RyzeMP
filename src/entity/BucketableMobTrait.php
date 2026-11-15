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

namespace pocketmine\entity;

use pocketmine\block\Water;
use pocketmine\item\Item;
use pocketmine\item\LiquidBucket;
use pocketmine\item\MobBucket;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function max;
use function min;

/**
 * Picking up a {@link BucketableMob} with a water bucket (vanilla: fish, axolotls and tadpoles are caught with a
 * water bucket, not an empty one). For {@link Mob}s.
 */
trait BucketableMobTrait{

	/**
	 * Item of the filled bucket, without data.
	 */
	abstract protected function getBucketItem() : Item;

	/**
	 * Writes the data kept in the bucket (variant, age...).
	 */
	protected function writeBucketData(CompoundTag $tag) : void{
		$tag->setFloat(BucketableMob::BUCKET_TAG_HEALTH, $this->getHealth());
	}

	public function createBucket() : Item{
		$item = $this->getBucketItem();
		$tag = $item->getNamedTag();
		$this->writeBucketData($tag);
		$item->setNamedTag($tag);
		if($this->getNameTag() !== ""){
			$item->setCustomName($this->getNameTag());
		}
		return $item;
	}

	public function applyBucketData(CompoundTag $tag) : void{
		$health = $tag->getTag(BucketableMob::BUCKET_TAG_HEALTH);
		if($health !== null){
			$this->setHealth(max(1.0, min((float) $this->getMaxHealth(), $tag->getFloat(BucketableMob::BUCKET_TAG_HEALTH))));
		}
		$this->setPersistent(true);
	}

	/**
	 * Puts the mob in the player's water bucket. Returns false if the player isn't holding a plain water bucket.
	 */
	protected function tryPickUpWithBucket(Player $player, Item $item) : bool{
		if(!$item instanceof LiquidBucket || $item instanceof MobBucket || !$item->getLiquid() instanceof Water || !$this->isAlive()){
			return false;
		}
		$this->exchangeHeldItem($player, $this->createBucket());
		$this->playMobSound(LevelSoundEvent::BUCKET_FILL_FISH);
		$this->unleash(true); //removed without dying: the lead drops (Java Bucketable.bucketMobPickup())
		$this->flagForDespawn();
		return true;
	}
}
