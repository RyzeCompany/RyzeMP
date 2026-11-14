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

use pocketmine\item\Durable;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\world\Position;
use function count;
use function intdiv;
use function max;
use function mt_rand;

/**
 * Taking the result out of a grindstone. Validates the result against the consumed inputs and gives the vanilla
 * amount of experience (as orbs dropped at the grindstone) once executed.
 */
class GrindstoneTransaction extends InventoryTransaction{
	/** Vanilla anvil/grindstone prior work penalty tag (not otherwise used by PocketMine). */
	public const TAG_REPAIR_COST = "RepairCost";

	/** @var Item[] */
	private array $inputs = [];

	public function __construct(
		Player $source,
		private readonly Position $grindstonePosition,
		array $actions = []
	){
		parent::__construct($source, $actions);
	}

	/** @return Item[] */
	public function getInputs() : array{ return $this->inputs; }

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}

		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);

		$inputCount = count($inputs);
		if($inputCount < 1 || $inputCount > 2){
			throw new TransactionValidationException("Expected 1 or 2 input items, got $inputCount");
		}
		if(($outputCount = count($outputs)) !== 1){
			throw new TransactionValidationException("Expected 1 output item, but received $outputCount");
		}

		$candidates = $inputCount === 1 ?
			[self::calculateResult($inputs[0], null)] :
			[self::calculateResult($inputs[0], $inputs[1]), self::calculateResult($inputs[1], $inputs[0])];
		foreach($candidates as $candidate){
			if($candidate !== null && $candidate->equalsExact($outputs[0])){
				$this->inputs = $inputs;
				return;
			}
		}
		throw new TransactionValidationException("Invalid output item");
	}

	public function execute() : void{
		parent::execute();

		$xp = 0;
		foreach($this->inputs as $input){
			$xp += self::getEnchantmentExperience($input);
		}
		if($xp > 0){
			//vanilla: half of the enchantment cost (rounded up), plus a random amount up to the same value - 1
			$half = intdiv($xp + 1, 2);
			$this->grindstonePosition->getWorld()->dropExperience($this->grindstonePosition->add(0.5, 0.5, 0.5), $half + mt_rand(0, $half - 1));
		}
	}

	public static function isCurse(Enchantment $enchantment) : bool{
		return $enchantment === VanillaEnchantments::VANISHING() || $enchantment === VanillaEnchantments::BINDING();
	}

	/**
	 * Returns the sum of the minimum enchanting power of all the non-curse enchantments on the item.
	 */
	public static function getEnchantmentExperience(Item $item) : int{
		$total = 0;
		foreach($item->getEnchantments() as $instance){
			if(!self::isCurse($instance->getType())){
				$total += $instance->getType()->getMinEnchantingPower($instance->getLevel());
			}
		}
		return $total;
	}

	/**
	 * Returns what the grindstone produces from the given items, or null if they can't be used together.
	 * Mirrors the vanilla rules: a single item must be enchanted (or an enchanted book); two items must be the same
	 * item, and are repaired (sum of both durabilities plus 5% of the maximum) if they are damageable, or must be
	 * identical otherwise. Every non-curse enchantment is removed, curses from both items are kept.
	 */
	public static function calculateResult(?Item $input, ?Item $additional) : ?Item{
		if($input !== null && $input->isNull()){
			$input = null;
		}
		if($additional !== null && $additional->isNull()){
			$additional = null;
		}
		if($input === null && $additional === null){
			return null;
		}
		if(($input !== null && $input->getCount() > 1) || ($additional !== null && $additional->getCount() > 1)){
			return null;
		}

		if($input !== null && $additional !== null){
			if($input->getTypeId() !== $additional->getTypeId()){
				return null;
			}
			$result = clone $input;
			$count = 1;
			if($result instanceof Durable && $additional instanceof Durable){
				$max = $result->getMaxDurability();
				$remaining = ($max - $result->getDamage()) + ($max - $additional->getDamage()) + intdiv($max * 5, 100);
				$damage = max($max - $remaining, 0);
			}else{
				if(!$input->equals($additional)){
					return null;
				}
				$damage = null;
				$count = 2;
			}
			foreach($additional->getEnchantments() as $instance){
				if(self::isCurse($instance->getType()) && !$result->hasEnchantment($instance->getType())){
					$result->addEnchantment($instance);
				}
			}
		}else{
			$single = $input ?? $additional;
			if($single->getTypeId() !== ItemTypeIds::ENCHANTED_BOOK && !$single->hasEnchantments()){
				return null;
			}
			$result = clone $single;
			$count = 1;
			$damage = $result instanceof Durable ? $result->getDamage() : null;
		}

		foreach($result->getEnchantments() as $instance){
			if(!self::isCurse($instance->getType())){
				$result->removeEnchantment($instance->getType());
			}
		}
		if($damage !== null && $result instanceof Durable){
			$result->setDamage($damage);
		}
		$result->setCount($count);

		$nbt = $result->getNamedTag();
		$curses = count($result->getEnchantments());
		if($curses > 0){
			//vanilla recalculates the prior work penalty as if each remaining enchantment had been added in an anvil
			$repairCost = 0;
			for($i = 0; $i < $curses; ++$i){
				$repairCost = $repairCost * 2 + 1;
			}
			$nbt->setInt(self::TAG_REPAIR_COST, $repairCost);
		}else{
			$nbt->removeTag(self::TAG_REPAIR_COST);
		}
		$result->setNamedTag($nbt);

		if($result->getTypeId() === ItemTypeIds::ENCHANTED_BOOK && !$result->hasEnchantments()){
			$book = VanillaItems::BOOK()->setCount($result->getCount());
			$book->setNamedTag($result->getNamedTag());
			return $book;
		}
		return $result;
	}
}
