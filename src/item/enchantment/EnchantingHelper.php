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

namespace pocketmine\item\enchantment;

use pocketmine\block\BlockTypeIds;
use pocketmine\item\enchantment\AvailableEnchantmentRegistry as EnchantmentRegistry;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems as Items;
use pocketmine\utils\Limits;
use pocketmine\utils\Random;
use pocketmine\world\Position;
use function abs;
use function array_filter;
use function chr;
use function count;
use function floor;
use function max;
use function min;
use function mt_rand;
use function ord;
use function round;

/**
 * Helper methods used for enchanting using the enchanting table.
 */
final class EnchantingHelper{
	private const MAX_BOOKSHELF_COUNT = 15;

	private function __construct(){
		//NOOP
	}

	/**
	 * Generates a new random seed for enchant option randomization.
	 */
	public static function generateSeed() : int{
		return mt_rand(Limits::INT32_MIN, Limits::INT32_MAX);
	}

	/**
	 * @param EnchantmentInstance[] $enchantments
	 */
	public static function enchantItem(Item $item, array $enchantments) : Item{
		$resultItem = $item->getTypeId() === ItemTypeIds::BOOK ? Items::ENCHANTED_BOOK() : clone $item;

		foreach($enchantments as $enchantment){
			$resultItem->addEnchantment($enchantment);
		}

		return $resultItem;
	}

	/**
	 * Enchants the item like an enchanting table option of the given level (vanilla enchant_with_levels loot
	 * function). With $treasure (Bedrock "treasure": true), the treasure enchantments that loot can give (Mending,
	 * Frost Walker and the curses) are possible too. Books become enchanted books. Returns the item unchanged when
	 * nothing can be applied.
	 */
	public static function enchantWithLevels(Item $item, int $levels, Random $random, bool $treasure = false) : Item{
		$enchantments = self::createOption($random, $item, $levels, $treasure)->getEnchantments();
		return count($enchantments) > 0 ? self::enchantItem($item, $enchantments) : $item;
	}

	/**
	 * @return EnchantingOption[]
	 */
	public static function generateOptions(Position $tablePos, Item $input, int $seed) : array{
		if($input->isNull() || $input->hasEnchantments()){
			return [];
		}

		$random = new Random($seed);

		$bookshelfCount = self::countBookshelves($tablePos);
		$baseRequiredLevel = $random->nextRange(1, 8) + ($bookshelfCount >> 1) + $random->nextRange(0, $bookshelfCount);
		$topRequiredLevel = (int) floor(max($baseRequiredLevel / 3, 1));
		$middleRequiredLevel = (int) floor($baseRequiredLevel * 2 / 3 + 1);
		$bottomRequiredLevel = max($baseRequiredLevel, $bookshelfCount * 2);

		$options = [
			self::createOption($random, $input, $topRequiredLevel),
			self::createOption($random, $input, $middleRequiredLevel),
			self::createOption($random, $input, $bottomRequiredLevel),
		];
		foreach($options as $option){
			if(count($option->getEnchantments()) > 0){
				return $options;
			}
		}
		//nothing can be put on the item (a stick): no offers, like vanilla (it showed costs without enchantments)
		return [];
	}

	private static function countBookshelves(Position $tablePos) : int{
		$bookshelfCount = 0;
		$world = $tablePos->getWorld();

		for($x = -2; $x <= 2; $x++){
			for($z = -2; $z <= 2; $z++){
				// We only check blocks at a distance of 2 blocks from the enchanting table
				if(abs($x) !== 2 && abs($z) !== 2){
					continue;
				}

				// Ensure the space between the bookshelf stack at this X/Z and the enchanting table is empty
				for($y = 0; $y <= 1; $y++){
					// Calculate the coordinates of the space between the bookshelf and the enchanting table
					$spaceX = max(min($x, 1), -1);
					$spaceZ = max(min($z, 1), -1);
					$spaceBlock = $world->getBlock($tablePos->add($spaceX, $y, $spaceZ));
					if($spaceBlock->getTypeId() !== BlockTypeIds::AIR){
						continue 2;
					}
				}

				// Finally, check the number of bookshelves at the current position
				for($y = 0; $y <= 1; $y++){
					$block = $world->getBlock($tablePos->add($x, $y, $z));
					if($block->getTypeId() === BlockTypeIds::BOOKSHELF){
						$bookshelfCount++;
						if($bookshelfCount === self::MAX_BOOKSHELF_COUNT){
							return $bookshelfCount;
						}
					}
				}
			}
		}

		return $bookshelfCount;
	}

	private static function createOption(Random $random, Item $inputItem, int $requiredXpLevel, bool $treasure = false) : EnchantingOption{
		$enchantingPower = $requiredXpLevel;

		$enchantability = $inputItem->getEnchantability();
		$enchantingPower = $enchantingPower + $random->nextRange(0, $enchantability >> 2) + $random->nextRange(0, $enchantability >> 2) + 1;
		// Random bonus for enchanting power between 0.85 and 1.15
		$bonus = 1 + ($random->nextFloat() + $random->nextFloat() - 1) * 0.15;
		$enchantingPower = (int) round($enchantingPower * $bonus);

		$resultEnchantments = [];
		$availableEnchantments = self::getAvailableEnchantments($enchantingPower, $inputItem, $treasure);

		$lastEnchantment = self::getRandomWeightedEnchantment($random, $availableEnchantments);
		if($lastEnchantment !== null){
			$resultEnchantments[] = $lastEnchantment;

			// With probability (power + 1) / 50, continue adding enchantments
			while($random->nextFloat() <= ($enchantingPower + 1) / 50){
				// Remove from the list of available enchantments anything that conflicts
				// with previously-chosen enchantments
				$availableEnchantments = array_filter(
					$availableEnchantments,
					function(EnchantmentInstance $e) use ($lastEnchantment){
						return $e->getType() !== $lastEnchantment->getType() &&
							$e->getType()->isCompatibleWith($lastEnchantment->getType());
					}
				);

				$lastEnchantment = self::getRandomWeightedEnchantment($random, $availableEnchantments);
				if($lastEnchantment === null){
					break;
				}

				$resultEnchantments[] = $lastEnchantment;
				$enchantingPower >>= 1;
			}
		}

		return new EnchantingOption($requiredXpLevel, self::getRandomOptionName($random), $resultEnchantments);
	}

	/**
	 * @return EnchantmentInstance[]
	 */
	private static function getAvailableEnchantments(int $enchantingPower, Item $item, bool $treasure) : array{
		$list = [];

		$candidates = EnchantmentRegistry::getInstance()->getPrimaryEnchantmentsForItem($item);
		if($treasure){
			foreach(self::getTreasureEnchantmentsForItem($item) as $enchantment){
				$candidates[] = $enchantment;
			}
		}
		foreach($candidates as $enchantment){
			for($lvl = $enchantment->getMaxLevel(); $lvl > 0; $lvl--){
				if($enchantingPower >= $enchantment->getMinEnchantingPower($lvl) &&
					$enchantingPower <= $enchantment->getMaxEnchantingPower($lvl)
				){
					$list[] = new EnchantmentInstance($enchantment, $lvl);
					break;
				}
			}
		}

		return $list;
	}

	/**
	 * Treasure enchantments that random loot can give (Bedrock enchant_with_levels "treasure") and that the item
	 * accepts by its primary or secondary tags. Soul Speed, Swift Sneak and Wind Burst only come from specific tables.
	 *
	 * @return Enchantment[]
	 */
	private static function getTreasureEnchantmentsForItem(Item $item) : array{
		$itemTags = $item->getEnchantmentTags();
		if(count($itemTags) === 0 || $item->hasEnchantments()){
			return [];
		}
		$registry = EnchantmentRegistry::getInstance();
		$tags = ItemEnchantmentTagRegistry::getInstance();
		$result = [];
		foreach([VanillaEnchantments::MENDING(), VanillaEnchantments::FROST_WALKER(), VanillaEnchantments::BINDING(), VanillaEnchantments::VANISHING()] as $enchantment){
			if($registry->isRegistered($enchantment) && (
				$tags->isTagArrayIntersection($registry->getPrimaryItemTags($enchantment), $itemTags) ||
				$tags->isTagArrayIntersection($registry->getSecondaryItemTags($enchantment), $itemTags)
			)){
				$result[] = $enchantment;
			}
		}
		return $result;
	}

	/**
	 * @param EnchantmentInstance[] $enchantments
	 */
	private static function getRandomWeightedEnchantment(Random $random, array $enchantments) : ?EnchantmentInstance{
		if(count($enchantments) === 0){
			return null;
		}

		$totalWeight = 0;
		foreach($enchantments as $enchantment){
			$totalWeight += $enchantment->getType()->getRarity();
		}

		$result = null;
		$randomWeight = $random->nextRange(1, $totalWeight);

		foreach($enchantments as $enchantment){
			$randomWeight -= $enchantment->getType()->getRarity();

			if($randomWeight <= 0){
				$result = $enchantment;
				break;
			}
		}

		return $result;
	}

	private static function getRandomOptionName(Random $random) : string{
		$name = "";
		for($i = $random->nextRange(5, 15); $i > 0; $i--){
			$name .= chr($random->nextRange(ord("a"), ord("z")) & 0xff);
		}

		return $name;
	}
}
