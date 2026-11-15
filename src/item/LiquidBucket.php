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
use pocketmine\block\Campfire;
use pocketmine\block\Lava;
use pocketmine\block\Liquid;
use pocketmine\block\utils\UltraWarmHelper;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\block\Water;
use pocketmine\event\player\PlayerBucketEmptyEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\vibration\GameEvent;

class LiquidBucket extends Item{
	private Liquid $liquid;

	public function __construct(ItemIdentifier $identifier, string $name, Liquid $liquid){
		parent::__construct($identifier, $name);
		$this->liquid = $liquid;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getFuelTime() : int{
		if($this->liquid instanceof Lava){
			return 20000;
		}

		return 0;
	}

	public function getFuelResidue() : Item{
		return VanillaItems::BUCKET();
	}

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		$waterlogTarget = $this->liquid instanceof Water ? WaterloggedHelper::getWaterBucketTarget($blockClicked, $blockReplace) : null;
		if($waterlogTarget === null && !$blockReplace->canBeReplaced()){
			return ItemUseResult::NONE;
		}
		if($this->liquid instanceof Water && UltraWarmHelper::isUltraWarm($blockReplace->getPosition())){
			return $this->evaporate($player, $waterlogTarget ?? $blockReplace, $face, $returnedItems);
		}
		if($waterlogTarget !== null){
			return $this->waterlog($player, $waterlogTarget, $face, $returnedItems);
		}

		//TODO: move this to generic placement logic
		$resultBlock = clone $this->liquid;

		$ev = new PlayerBucketEmptyEvent($player, $blockReplace, $face, $this, VanillaItems::BUCKET());
		$ev->call();
		if(!$ev->isCancelled()){
			$player->getWorld()->setBlock($blockReplace->getPosition(), $resultBlock->getFlowingForm());
			$player->getWorld()->addSound($blockReplace->getPosition()->add(0.5, 0.5, 0.5), $resultBlock->getBucketEmptySound());
			$player->getWorld()->addGameEvent($blockReplace->getPosition(), GameEvent::FLUID_PLACE, $player);

			$this->pop();
			$returnedItems[] = $ev->getItem();
			return ItemUseResult::SUCCESS;
		}

		return ItemUseResult::FAIL;
	}

	/**
	 * Empties the bucket into a waterloggable block (see {@link WaterloggedHelper::canWaterlog()}), like Java's
	 * LiquidBlockContainer.placeLiquid(): a lit campfire is put out by it (see {@link Campfire}).
	 *
	 * @param Item[] &$returnedItems
	 */
	private function waterlog(Player $player, Block $target, int $face, array &$returnedItems) : ItemUseResult{
		$ev = new PlayerBucketEmptyEvent($player, $target, $face, $this, VanillaItems::BUCKET());
		$ev->call();
		if($ev->isCancelled()){
			return ItemUseResult::FAIL;
		}
		$world = $player->getWorld();
		$position = $target->getPosition();
		WaterloggedHelper::waterlog($target);
		$world->addSound($position->add(0.5, 0.5, 0.5), $this->liquid->getBucketEmptySound());
		$world->addGameEvent($position, GameEvent::FLUID_PLACE, $player);

		$this->pop();
		$returnedItems[] = $ev->getItem();
		return ItemUseResult::SUCCESS;
	}

	/**
	 * Empties a water bucket in an ultra-warm dimension (the Nether), like Java BucketItem.emptyContents(): the water
	 * evaporates with a fizz instead of being placed or waterlogging the block, and the bucket is emptied anyway.
	 *
	 * @param Item[] &$returnedItems
	 */
	private function evaporate(Player $player, Block $target, int $face, array &$returnedItems) : ItemUseResult{
		$ev = new PlayerBucketEmptyEvent($player, $target, $face, $this, VanillaItems::BUCKET());
		$ev->call();
		if($ev->isCancelled()){
			return ItemUseResult::FAIL;
		}
		UltraWarmHelper::playEvaporation($player->getWorld(), $target->getPosition());

		$this->pop();
		$returnedItems[] = $ev->getItem();
		return ItemUseResult::SUCCESS;
	}

	public function getLiquid() : Liquid{
		return $this->liquid;
	}
}
