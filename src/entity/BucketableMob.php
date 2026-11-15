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

use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;

/**
 * A mob that can be picked up in a bucket (fish, axolotls, tadpoles): using a water bucket on it replaces the bucket
 * with {@link BucketableMob::createBucket()}, and emptying that bucket releases the mob again with
 * {@link BucketableMob::applyBucketData()}.
 */
interface BucketableMob{
	/** Keys of the bucket item NBT (same names as PowerNukkitX / vanilla actor data). */
	public const BUCKET_TAG_VARIANT = "Variant";
	public const BUCKET_TAG_MARK_VARIANT = "MarkVariant";
	public const BUCKET_TAG_COLOR = "Color";
	public const BUCKET_TAG_COLOR2 = "Color2";
	public const BUCKET_TAG_AGE = "Age";
	public const BUCKET_TAG_HEALTH = "Health";

	/**
	 * Returns the bucket holding this mob, with its data (variant, age...) in the item NBT.
	 */
	public function createBucket() : Item;

	/**
	 * Restores the mob's data from the NBT of the bucket it was released from.
	 */
	public function applyBucketData(CompoundTag $tag) : void;
}
