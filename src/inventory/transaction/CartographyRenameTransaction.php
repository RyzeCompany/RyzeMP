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

use pocketmine\item\EmptyMap;
use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\player\Player;
use function count;
use function mb_substr;
use function trim;

/**
 * Renaming a map in the name field of the cartography table (Bedrock) without a map operation: the whole map stack in
 * the input slot is used up and given back renamed, for free. A name given together with an operation is part of the
 * CartographyTransaction instead.
 */
final class CartographyRenameTransaction extends InventoryTransaction{

	public function __construct(
		Player $source,
		private readonly Item $input,
		private readonly Item $result,
		array $actions = []
	){
		parent::__construct($source, $actions);
	}

	public function getInput() : Item{ return clone $this->input; }

	public function getResult() : Item{ return clone $this->result; }

	/**
	 * Returns $item with the name typed in the cartography table, or null if the name would not change anything. The
	 * same rules as the anvil: at most 50 characters, and a blank name removes the custom name.
	 * @internal
	 */
	public static function applyName(Item $item, string $newName) : ?Item{
		$newName = mb_substr($newName, 0, AnvilTransaction::MAX_NAME_LENGTH);
		$result = clone $item;
		if(trim($newName) === ""){
			if(!$item->hasCustomName()){
				return null;
			}
			$result->clearCustomName();
		}elseif($newName !== $item->getName()){
			$result->setCustomName($newName);
		}else{
			return null;
		}
		return $result;
	}

	/**
	 * Returns the renamed map stack, or null if the item in the input slot can't be renamed there (only maps) or the
	 * name doesn't change.
	 * @internal
	 */
	public static function calculateResult(Item $input, string $newName) : ?Item{
		if(!$input instanceof FilledMap && !$input instanceof EmptyMap){
			return null;
		}
		return self::applyName($input, $newName);
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}

		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);

		if(
			count($inputs) !== 1 || !$inputs[0]->equalsExact($this->input) ||
			count($outputs) !== 1 || !$outputs[0]->equalsExact($this->result)
		){
			throw new TransactionValidationException("Items used or created don't match the cartography table rename");
		}
	}
}
