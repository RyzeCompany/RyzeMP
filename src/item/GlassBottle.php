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
use pocketmine\block\BlockTypeIds;
use pocketmine\entity\object\DragonBreathCloud;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\BottleFillDragonBreathSound;
use pocketmine\world\sound\BottleFillSound;
use pocketmine\world\vibration\GameEvent;

class GlassBottle extends Item{

	/**
	 * Vanilla BottleItem.use(): a dragon's breath cloud touching the player's box grown by 2 blocks is bottled first.
	 */
	private const DRAGON_BREATH_REACH = 2.0;

	/**
	 * Bottles a cloud of the dragon's breath near the player, if any (the only source of Dragon's Breath): the cloud
	 * shrinks by 0.5 blocks.
	 *
	 * @param Item[] &$returnedItems
	 */
	private function fillFromDragonBreath(Player $player, array &$returnedItems) : bool{
		$box = $player->getBoundingBox()->expandedCopy(self::DRAGON_BREATH_REACH, self::DRAGON_BREATH_REACH, self::DRAGON_BREATH_REACH);
		foreach($player->getWorld()->getNearbyEntities($box, $player) as $entity){
			if($entity instanceof DragonBreathCloud && $entity->isAlive() && !$entity->isFlaggedForDespawn()){
				$entity->onBottled();
				$player->getWorld()->addSound($player->getPosition(), new BottleFillDragonBreathSound());
				$this->pop();
				$returnedItems[] = VanillaItems::DRAGON_BREATH();
				return true;
			}
		}
		return false;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		return $this->fillFromDragonBreath($player, $returnedItems) ? ItemUseResult::SUCCESS : ItemUseResult::NONE;
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if($this->fillFromDragonBreath($player, $returnedItems)){
			return ItemUseResult::SUCCESS;
		}
		if($blockClicked->getTypeId() === BlockTypeIds::WATER){
			$this->pop();
			$returnedItems[] = VanillaItems::POTION()->setType(PotionType::WATER);
			$world = $player->getWorld();
			$world->addSound($player->getPosition(), new BottleFillSound());
			$world->addGameEvent($blockClicked->getPosition(), GameEvent::FLUID_PICKUP, $player);

			return ItemUseResult::SUCCESS;
		}

		return ItemUseResult::NONE;
	}
}
