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

namespace pocketmine\inventory\transaction;

use pocketmine\crafting\CartographyMapRecipe;
use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\world\map\MapManager;
use function array_values;
use function count;
use function min;

/**
 * A map operation of the cartography table (see CartographyMapRecipe). One map and one item of the additional slot
 * are used up; zooming out and locking create the new map data once the transaction goes through.
 */
class CartographyTransaction extends InventoryTransaction{

	public function __construct(
		Player $source,
		private readonly CartographyMapRecipe $recipe,
		private readonly Item $input,
		private readonly Item $additional,
		private readonly Item $result,
		array $actions = []
	){
		parent::__construct($source, $actions);
	}

	public function getRecipe() : CartographyMapRecipe{ return $this->recipe; }

	public function getResult() : Item{ return clone $this->result; }

	/**
	 * Cancels out stackable items between the lists, the same way matchItems() does with the actions.
	 *
	 * @param Item[] $needItems
	 * @param Item[] $haveItems
	 * @phpstan-param list<Item> $needItems
	 * @phpstan-param list<Item> $haveItems
	 *
	 * @return Item[][]
	 * @phpstan-return array{list<Item>, list<Item>}
	 */
	private static function cancelOut(array $needItems, array $haveItems) : array{
		foreach($needItems as $i => $needItem){
			foreach($haveItems as $j => $haveItem){
				if($needItem->canStackWith($haveItem)){
					$amount = min($needItem->getCount(), $haveItem->getCount());
					$needItem->setCount($needItem->getCount() - $amount);
					$haveItem->setCount($haveItem->getCount() - $amount);
					if($haveItem->getCount() === 0){
						unset($haveItems[$j]);
					}
					if($needItem->getCount() === 0){
						unset($needItems[$i]);
						break;
					}
				}
			}
		}
		return [array_values($needItems), array_values($haveItems)];
	}

	/**
	 * @param Item[] $actual
	 * @param Item[] $expected
	 * @phpstan-param list<Item> $actual
	 * @phpstan-param list<Item> $expected
	 */
	private static function sameItems(array $actual, array $expected) : bool{
		if(count($actual) !== count($expected)){
			return false;
		}
		foreach($actual as $item){
			$found = false;
			foreach($expected as $k => $expectedItem){
				if($item->equalsExact($expectedItem)){
					unset($expected[$k]);
					$found = true;
					break;
				}
			}
			if(!$found){
				return false;
			}
		}
		return true;
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}

		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);

		[$expectedOutputs, $expectedInputs] = self::cancelOut(
			[clone $this->result],
			[(clone $this->input)->setCount(1), (clone $this->additional)->setCount(1)]
		);
		if(!self::sameItems($outputs, $expectedOutputs) || !self::sameItems($inputs, $expectedInputs)){
			throw new TransactionValidationException("Items used or created don't match the cartography table operation");
		}
	}

	public function execute() : void{
		parent::execute();

		if($this->recipe->createsNewMap() && $this->input instanceof FilledMap && $this->result instanceof FilledMap){
			$manager = MapManager::getInstance();
			$source = $manager->getMap($this->input->getMapId());
			if($source !== null){
				if($this->recipe->getUuid() === CartographyMapRecipe::EXTENDING){
					$manager->createScaledMap($source, $this->result->getMapId());
				}else{
					$manager->createLockedCopy($source, $this->result->getMapId());
				}
			}
			//the client can't know the ID of the new map, so make sure it gets the real item
			$manager->scheduleInventorySync($this->source);
		}
	}
}
