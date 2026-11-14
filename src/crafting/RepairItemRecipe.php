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

namespace pocketmine\crafting;

use pocketmine\item\Durable;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use function intdiv;
use function max;

/**
 * Repairing in the crafting grid (client-hardcoded multi recipe): two items with durability of the same type make one
 * item with the remaining durability of both plus 5% of the maximum (capped at the maximum). The result is a plain
 * item: names and enchantments are lost, except curses, which carry over at the higher level (Minecraft Wiki, Java
 * RepairItemRecipe). No experience is needed.
 */
final class RepairItemRecipe extends MultiCraftingRecipe{
	/** UUID the client uses for this multi recipe (BedrockProtocol MultiRecipe::TYPE_REPAIR_ITEM) */
	public const MULTI_RECIPE_UUID = "00000000-0000-0000-0000-000000000001";

	public function getMultiRecipeUuid() : string{
		return self::MULTI_RECIPE_UUID;
	}

	public function getIngredientList() : array{
		//representative only: the real ingredients depend on the grid (see getIngredientListFor())
		return [new ExactRecipeIngredient(VanillaItems::WOODEN_PICKAXE()), new ExactRecipeIngredient(VanillaItems::WOODEN_PICKAXE())];
	}

	protected function computeResults(CraftingGrid $grid) : ?array{
		$result = self::computeResult($grid->getContents());
		return $result !== null ? [$result] : null;
	}

	/**
	 * Returns the repaired item for the given grid contents, or null if they aren't two repairable items of a kind.
	 *
	 * @internal exposed for tests
	 * @param Item[] $items
	 */
	public static function computeResult(array $items) : ?Durable{
		$first = null;
		$second = null;
		foreach($items as $item){
			if($item->isNull()){
				continue;
			}
			if(!$item instanceof Durable || $item->getCount() !== 1 || $second !== null){
				return null;
			}
			if($first === null){
				$first = $item;
			}else{
				$second = $item;
			}
		}
		if($first === null || $second === null || $first->getTypeId() !== $second->getTypeId() || $first->getStateId() !== $second->getStateId()){
			return null;
		}

		$maxDurability = $first->getMaxDurability();
		$remaining = ($maxDurability - $first->getDamage()) + ($maxDurability - $second->getDamage()) + intdiv($maxDurability * 5, 100);

		$result = clone $first;
		$result->setCount(1);
		$result->clearNamedTag();
		$result->setDamage(max($maxDurability - $remaining, 0));
		foreach([VanillaEnchantments::BINDING(), VanillaEnchantments::VANISHING()] as $curse){
			$level = max($first->getEnchantmentLevel($curse), $second->getEnchantmentLevel($curse));
			if($level > 0){
				$result->addEnchantment(new EnchantmentInstance($curse, $level));
			}
		}
		return $result;
	}
}
