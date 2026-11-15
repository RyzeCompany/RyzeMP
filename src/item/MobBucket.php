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

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\Liquid;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\block\Water;
use pocketmine\entity\BucketableMob;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\World;

/**
 * Bucket of an aquatic mob (cod, salmon, tropical fish, pufferfish, axolotl, tadpole). It places water like a water
 * bucket, leaves an empty bucket and releases the mob registered for {@link MobBucket::getEntityTypeId()} in
 * {@link EntitySpawnRegistry} (if any) with the data saved on the bucket (variant, age...; see
 * {@link BucketableMob}). Mobs are picked up by using a water bucket on them.
 */
class MobBucket extends LiquidBucket{

	public function __construct(
		ItemIdentifier $identifier,
		string $name,
		Liquid $liquid,
		private ?string $entityTypeId = null
	){
		parent::__construct($identifier, $name, $liquid);
	}

	/**
	 * Returns the Bedrock identifier of the mob in the bucket (e.g. "minecraft:cod"), or null if none is known.
	 */
	public function getEntityTypeId() : ?string{
		return $this->entityTypeId;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		$bucket = clone $this;
		//the mob goes where the water goes: into the block it waterlogs, if any
		$target = $this->getLiquid() instanceof Water ? WaterloggedHelper::getWaterBucketTarget($blockClicked, $blockReplace) ?? $blockReplace : $blockReplace;
		$result = parent::onInteractBlock($player, $blockReplace, $blockClicked, $face, $clickVector, $returnedItems);
		if($result === ItemUseResult::SUCCESS){
			$bucket->releaseMob($player->getWorld(), $target->getPosition()->add(0.5, 0, 0.5));
		}
		return $result;
	}

	/**
	 * Spawns the mob of this bucket at the given position. Returns the mob, or null if there is no entity for it.
	 */
	public function releaseMob(World $world, Vector3 $pos) : ?Entity{
		if($this->entityTypeId === null){
			return null;
		}
		$entity = EntitySpawnRegistry::getInstance()->create($this->entityTypeId, Location::fromObject($pos, $world, Utils::getRandomFloat() * 360, 0));
		if($entity === null){
			return null;
		}
		if($entity instanceof BucketableMob){
			$entity->applyBucketData($this->getNamedTag());
		}
		if($this->hasCustomName()){
			$entity->setNameTag($this->getCustomName());
		}
		$entity->spawnToAll();
		return $entity;
	}
}
