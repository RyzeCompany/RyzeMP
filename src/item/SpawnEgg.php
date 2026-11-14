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
use pocketmine\entity\BabySpawning;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\riding\Jockeys;
use pocketmine\entity\SpawnReason;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;

abstract class SpawnEgg extends Item{

	/**
	 * Creates the entity of this egg, or returns null if there is none. By default, the entity is created by the
	 * factory registered in {@link EntitySpawnRegistry} for {@link SpawnEgg::getEntityTypeId()}.
	 */
	protected function createEntity(World $world, Vector3 $pos, float $yaw, float $pitch) : ?Entity{
		$typeId = $this->getEntityTypeId();
		return $typeId !== null ? EntitySpawnRegistry::getInstance()->create($typeId, Location::fromObject($pos, $world, $yaw, $pitch)) : null;
	}

	/**
	 * Returns the Bedrock identifier of the mob in this egg (e.g. "minecraft:zombie"), used to set the mob of a monster
	 * spawner, or null if unknown.
	 */
	public function getEntityTypeId() : ?string{
		return null;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		$entity = $this->createEntity($player->getWorld(), $blockReplace->getPosition()->add(0.5, 0, 0.5), Utils::getRandomFloat() * 360, 0);
		if($entity === null){
			return ItemUseResult::NONE;
		}

		if($this->hasCustomName()){
			$entity->setNameTag($this->getCustomName());
		}
		$this->pop();
		if($entity instanceof Living){
			$entity->setSpawnReason(SpawnReason::SPAWN_EGG);
		}
		BabySpawning::rollSpawnBaby($entity);
		$entity->spawnToAll();
		Jockeys::onMobSpawned($entity);
		$player->getWorld()->addGameEvent($entity->getPosition(), GameEvent::ENTITY_PLACE, $player);
		//TODO: what if the entity was marked for deletion?
		return ItemUseResult::SUCCESS;
	}

	/**
	 * Creates and spawns the entity of this egg at the given position (e.g. for dispensers). Does not consume the egg.
	 * Returns null if there is no entity implementation for this egg's mob.
	 */
	public function spawnEntity(World $world, Vector3 $pos, float $yaw, float $pitch) : ?Entity{
		$entity = $this->createEntity($world, $pos, $yaw, $pitch);
		if($entity === null){
			return null;
		}
		if($this->hasCustomName()){
			$entity->setNameTag($this->getCustomName());
		}
		if($entity instanceof Living){
			$entity->setSpawnReason(SpawnReason::SPAWN_EGG);
		}
		BabySpawning::rollSpawnBaby($entity);
		$entity->spawnToAll();
		Jockeys::onMobSpawned($entity);
		return $entity;
	}
}
