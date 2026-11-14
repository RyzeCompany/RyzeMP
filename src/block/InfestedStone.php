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

namespace pocketmine\block;

use pocketmine\entity\monster\Silverfish;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\player\Player;

class InfestedStone extends Opaque{

	private int $imitated;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, Block $imitated){
		$this->imitated = $imitated->getStateId();
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function getImitatedBlock() : Block{
		return RuntimeBlockStateRegistry::getInstance()->fromStateId($this->imitated);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function getSilkTouchDrops(Item $item) : array{
		return [$this->getImitatedBlock()->asItem()];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	/**
	 * Breaking an infested block (without Silk Touch) releases the silverfish hiding in it.
	 */
	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$result = parent::onBreak($item, $player, $returnedItems);
		if(!$item->hasEnchantment(VanillaEnchantments::SILK_TOUCH()) && ($player === null || !$player->isCreative())){
			Silverfish::spawnFromBlock($this->position->getWorld(), $this->position);
		}
		return $result;
	}
}
