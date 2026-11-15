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
use pocketmine\entity\BucketableMob;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\event\player\PlayerBucketEmptyEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\World;

/**
 * Bucket of Sulfur Cube (a sulfur cube picked up with an empty bucket). Unlike the buckets of aquatic mobs it holds no
 * water: using it on a block releases the cube (with the age, health and block saved on the bucket, see
 * {@link BucketableMob}) and leaves an empty bucket.
 */
class SulfurCubeBucket extends UnstackableItem{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if(!$blockReplace->canBeReplaced()){
			return ItemUseResult::NONE;
		}
		$ev = new PlayerBucketEmptyEvent($player, $blockReplace, $face, $this, VanillaItems::BUCKET());
		$ev->call();
		if($ev->isCancelled()){
			return ItemUseResult::FAIL;
		}
		if($this->releaseMob($player->getWorld(), $blockReplace->getPosition()->add(0.5, 0, 0.5)) === null){
			return ItemUseResult::FAIL;
		}
		$this->pop();
		$returnedItems[] = $ev->getItem();
		return ItemUseResult::SUCCESS;
	}

	/**
	 * Spawns the cube of this bucket at the given position. Returns it, or null if no sulfur cube is registered.
	 */
	public function releaseMob(World $world, Vector3 $pos) : ?Entity{
		$entity = EntitySpawnRegistry::getInstance()->create(EntityIds::SULFUR_CUBE, Location::fromObject($pos, $world, Utils::getRandomFloat() * 360, 0));
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
		if($entity instanceof Mob){
			$entity->playMobSound(LevelSoundEvent::BUCKET_EMPTY_LAND_ANIMAL);
		}
		return $entity;
	}
}
