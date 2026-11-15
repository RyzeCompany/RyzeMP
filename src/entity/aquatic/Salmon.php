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

use pocketmine\entity\ai\goal\FlockingGoal;
use pocketmine\entity\BucketableMob;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use function mt_rand;

/**
 * Bedrock values (entities/salmon.json): 3 health, 0.5x0.5, underwater speed 0.12, flees players within 3 blocks.
 * Three sizes (1.21.50+): small (scale 0.5, weight 30), normal (1, 50), large (1.5, 15).
 * Loot (salmon_normal / salmon_large): 1 raw salmon (cooked when burning), 25% bone (1-2 when large).
 */
class Salmon extends AbstractFish{
	public const SIZE_SMALL = 0;
	public const SIZE_NORMAL = 1;
	public const SIZE_LARGE = 2;

	private const SCALES = [self::SIZE_SMALL => 0.5, self::SIZE_NORMAL => 1.0, self::SIZE_LARGE => 1.5];

	private int $sizeVariant = self::SIZE_NORMAL;

	public static function getNetworkTypeId() : string{ return EntityIds::SALMON; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.5, 0.5, 0.3);
	}

	public function getName() : string{
		return "Salmon";
	}

	protected function getSwimSpeed() : float{
		return 0.12;
	}

	protected function getAvoidDistance() : float{
		return 3.0;
	}

	protected function createFlockingGoal() : FlockingGoal{
		//salmon.json flocking: tighter schools (cohesion threshold 1.5)
		return new FlockingGoal($this, 3.0, 4, 8, 0.1, 1.5);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$variant = $nbt->getTag(BucketableMob::BUCKET_TAG_VARIANT) !== null ? $nbt->getInt(BucketableMob::BUCKET_TAG_VARIANT) : self::randomSize();
		$this->setSizeVariant($variant);
	}

	public static function randomSize() : int{
		$r = mt_rand(0, 94);
		return $r < 30 ? self::SIZE_SMALL : ($r < 80 ? self::SIZE_NORMAL : self::SIZE_LARGE);
	}

	public function getSizeVariant() : int{
		return $this->sizeVariant;
	}

	public function setSizeVariant(int $size) : void{
		$this->sizeVariant = isset(self::SCALES[$size]) ? $size : self::SIZE_NORMAL;
		$this->setScale(self::SCALES[$this->sizeVariant]);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(BucketableMob::BUCKET_TAG_VARIANT, $this->sizeVariant);
		return $nbt;
	}

	protected function writeBucketData(CompoundTag $tag) : void{
		parent::writeBucketData($tag);
		$tag->setInt(BucketableMob::BUCKET_TAG_VARIANT, $this->sizeVariant);
	}

	public function applyBucketData(CompoundTag $tag) : void{
		parent::applyBucketData($tag);
		if($tag->getTag(BucketableMob::BUCKET_TAG_VARIANT) !== null){
			$this->setSizeVariant($tag->getInt(BucketableMob::BUCKET_TAG_VARIANT));
		}
	}

	protected function getBucketItem() : Item{
		return VanillaItems::SALMON_BUCKET();
	}

	protected function getFishDrops() : array{
		return [$this->isOnFire() ? VanillaItems::COOKED_SALMON() : VanillaItems::RAW_SALMON()];
	}

	public function getDrops() : array{
		$drops = parent::getDrops();
		if($this->sizeVariant === self::SIZE_LARGE){
			foreach($drops as $drop){
				if($drop->getTypeId() === VanillaItems::BONE()->getTypeId()){
					$drop->setCount($drop->getCount() + mt_rand(0, 1));
				}
			}
		}
		return $drops;
	}
}
