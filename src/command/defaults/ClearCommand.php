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

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\command\utils\CommandItemParser;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParserException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function count;
use function min;

class ClearCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"clear",
			KnownTranslationFactory::pocketmine_command_clear_description(),
			KnownTranslationFactory::pocketmine_command_clear_usage()
		);
		$this->setPermissions([DefaultPermissionNames::COMMAND_CLEAR_SELF, DefaultPermissionNames::COMMAND_CLEAR_OTHER]);
	}

	public function getOverloads() : array{
		return [[
			CommandParameter::target("player", true),
			CommandParameter::item("itemName", true),
			CommandParameter::int("data", true),
			CommandParameter::int("maxCount", true)
		]];
	}

	/**
	 * /clear [player: target] [item] [maxCount], or Bedrock's /clear [player] [item] [data] [maxCount] (data -1 or 0
	 * matches the item's default variant; other values select a variant, e.g. a potion type).
	 */
	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) > 4){
			throw new InvalidCommandSyntaxException();
		}

		$targets = $this->fetchPermittedPlayerTargets($sender, $args[0] ?? null, DefaultPermissionNames::COMMAND_CLEAR_SELF, DefaultPermissionNames::COMMAND_CLEAR_OTHER);
		if(count($targets) === 0){
			return true;
		}

		$targetItem = null;
		$maxCount = -1;
		if(isset($args[1])){
			try{
				$targetItem = CommandItemParser::parse($args[1]);
				if(isset($args[3])){
					$data = $this->getInteger($sender, $args[2], -1, 32767);
					$targetItem = $data <= 0 ? $targetItem : (CommandItemParser::withData($targetItem, $data) ?? throw new LegacyStringToItemParserException());
				}

				$maxCountArg = $args[3] ?? $args[2] ?? null;
				if($maxCountArg !== null){
					$targetItem->setCount($maxCount = $this->getInteger($sender, $maxCountArg, -1));
				}
			}catch(LegacyStringToItemParserException $e){
				//vanilla checks this at argument parsing layer, can't come up with a better alternative
				$sender->sendMessage(KnownTranslationFactory::commands_give_item_notFound($args[1])->prefix(TextFormat::RED));
				return true;
			}
		}

		foreach($targets as $target){
			$this->clearTarget($sender, $target, $targetItem !== null ? clone $targetItem : null, $maxCount);
		}
		return true;
	}

	private function clearTarget(CommandSender $sender, Player $target, ?Item $targetItem, int $maxCount) : void{
		/**
		 * @var Inventory[] $inventories - This is the order that vanilla would clear items in.
		 */
		$inventories = [
			$target->getInventory(),
			$target->getCursorInventory(),
			$target->getArmorInventory(),
			$target->getOffHandInventory()
		];

		// Checking player's inventory for all the items matching the criteria
		if($targetItem !== null && $maxCount === 0){
			$count = $this->countItems($inventories, $targetItem);
			if($count > 0){
				$sender->sendMessage(KnownTranslationFactory::commands_clear_testing($target->getName(), (string) $count));
			}else{
				$sender->sendMessage(KnownTranslationFactory::commands_clear_failure_no_items($target->getName())->prefix(TextFormat::RED));
			}

			return;
		}

		$clearedCount = 0;
		if($targetItem === null){
			// Clear all items from the inventories
			$clearedCount += $this->countItems($inventories, null);
			foreach($inventories as $inventory){
				$inventory->clearAll();
			}
		}else{
			// Clear the item from target's inventory irrelevant of the count
			if($maxCount === -1){
				$clearedCount += $this->countItems($inventories, $targetItem);
				foreach($inventories as $inventory){
					$inventory->remove($targetItem);
				}
			}else{
				// Clear the item from target's inventory up to maxCount
				foreach($inventories as $inventory){
					foreach($inventory->all($targetItem) as $index => $item){
						// The count to reduce from the item and max count
						$reductionCount = min($item->getCount(), $maxCount);
						$item->pop($reductionCount);
						$clearedCount += $reductionCount;
						$inventory->setItem($index, $item);

						$maxCount -= $reductionCount;
						if($maxCount <= 0){
							break 2;
						}
					}
				}
			}
		}

		if($clearedCount > 0){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_clear_success($target->getName(), (string) $clearedCount));
		}else{
			$sender->sendMessage(KnownTranslationFactory::commands_clear_failure_no_items($target->getName())->prefix(TextFormat::RED));
		}
	}

	/**
	 * @param Inventory[] $inventories
	 */
	protected function countItems(array $inventories, ?Item $target) : int{
		$count = 0;
		foreach($inventories as $inventory){
			$contents = $target !== null ? $inventory->all($target) : $inventory->getContents();
			foreach($contents as $item){
				$count += $item->getCount();
			}
		}
		return $count;
	}
}
