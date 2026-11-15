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

namespace pocketmine\entity\npc\trade;

use pocketmine\block\utils\DyeColor;
use pocketmine\color\Color;
use pocketmine\item\Armor;
use pocketmine\item\DyeableItem;
use pocketmine\item\enchantment\EnchantingHelper;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\Potion;
use pocketmine\item\PotionType;
use pocketmine\item\SplashPotion;
use pocketmine\item\TippedArrow;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Utils;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function min;
use function str_replace;
use function strtolower;

/**
 * Item functions of the Bedrock trade tables ("functions" of a wanted or given item), by name. A function returns the
 * changed item, or null when it can't be applied: the trade is then skipped, like trades with an unknown function.
 *
 * Supported: enchant_with_levels, enchant_book_for_trading, random_aux_value, set_potion, random_dye and
 * exploration_map (cartographer explorer maps, see {@link ExplorerMaps}; only when the trader's context can make them).
 *
 * @phpstan-type FunctionData array<array-key, mixed>
 * @phpstan-type TradeFunction \Closure(Item $item, string $itemName, FunctionData $data, TradeContext $context) : ?Item
 *
 * @internal
 */
final class TradeFunctions{
	/**
	 * Enchantments a librarian never sells (vanilla "tradeable" tag: everything but these three).
	 */
	private const UNTRADEABLE_ENCHANTMENTS = ["SOUL_SPEED", "SWIFT_SNEAK", "WIND_BURST"];
	/**
	 * Treasure enchantments a librarian sells, for twice the price (vanilla "double_trade_price" tag).
	 */
	private const TREASURE_ENCHANTMENTS = ["BINDING", "VANISHING", "FROST_WALKER", "MENDING"];
	/** Most expensive enchanted book. */
	private const MAX_BOOK_PRICE = 64;

	/**
	 * @var \Closure[]
	 * @phpstan-var array<string, TradeFunction>
	 */
	private static array $functions = [];
	private static bool $initialized = false;

	private function __construct(){
		//NOOP
	}

	/**
	 * Registers (or replaces) the function of the given name.
	 *
	 * @phpstan-param TradeFunction $function
	 */
	public static function register(string $name, \Closure $function) : void{
		self::init();
		Utils::validateCallableSignature(static fn(Item $item, string $itemName, array $data, TradeContext $context) : ?Item => null, $function);
		self::$functions[$name] = $function;
	}

	public static function isSupported(string $name) : bool{
		self::init();
		return isset(self::$functions[$name]);
	}

	/**
	 * Applies the function to the item, or returns null if the function is unknown or can't be applied.
	 *
	 * @phpstan-param FunctionData $data
	 */
	public static function apply(string $name, Item $item, string $itemName, array $data, TradeContext $context) : ?Item{
		self::init();
		$function = self::$functions[$name] ?? null;
		return $function !== null ? $function($item, $itemName, $data, $context) : null;
	}

	private static function init() : void{
		if(self::$initialized){
			return;
		}
		self::$initialized = true;
		self::$functions["enchant_with_levels"] = self::enchantWithLevels(...);
		self::$functions["enchant_book_for_trading"] = self::enchantBookForTrading(...);
		self::$functions["random_aux_value"] = self::randomAuxValue(...);
		self::$functions["set_potion"] = self::setPotion(...);
		self::$functions["random_dye"] = self::randomDye(...);
		self::$functions["exploration_map"] = self::explorationMap(...);
	}

	/**
	 * Explorer map pointing at the nearest structure of the "destination" (made by the trader's context, see
	 * {@link TradeContext::createExplorationMap()}); null (trade skipped) if it can't be made.
	 *
	 * @phpstan-param FunctionData $data
	 */
	private static function explorationMap(Item $item, string $itemName, array $data, TradeContext $context) : ?Item{
		$destination = $data["destination"] ?? null;
		if(!is_string($destination) || ExplorerMaps::getDestination($destination) === null){
			return null;
		}
		return $context->createExplorationMap($item, $destination);
	}

	/**
	 * min/max object or plain integer, like Bedrock's "levels" and "values".
	 */
	private static function range(mixed $value, TradeContext $context) : ?int{
		if(is_int($value)){
			return $value;
		}
		if(is_array($value) && is_int($value["min"] ?? null) && is_int($value["max"] ?? null)){
			return $context->nextRange($value["min"], $value["max"]);
		}
		return null;
	}

	/**
	 * Enchants the item like an enchanting table of the given level (armour, tools, weapons, bows, rods). Treasure
	 * enchantments are never asked for by the vanilla tables.
	 *
	 * @phpstan-param FunctionData $data
	 */
	private static function enchantWithLevels(Item $item, string $itemName, array $data, TradeContext $context) : ?Item{
		$levels = self::range($data["levels"] ?? null, $context);
		if($levels === null){
			return null;
		}
		return EnchantingHelper::enchantWithLevels($item, $levels, $context->getRandom());
	}

	/**
	 * Enchantments a librarian can sell, each with whether it's a treasure one (double price), in a stable order.
	 *
	 * @return mixed[][]
	 * @phpstan-return list<array{Enchantment, bool}>
	 */
	public static function getTradeableEnchantments() : array{
		$list = [];
		foreach(Utils::stringifyKeys(VanillaEnchantments::getAll()) as $name => $enchantment){
			if(in_array($name, self::UNTRADEABLE_ENCHANTMENTS, true)){
				continue;
			}
			$list[] = [$enchantment, in_array($name, self::TREASURE_ENCHANTMENTS, true)];
		}
		return $list;
	}

	/**
	 * Librarian enchanted books: a random tradeable enchantment at a random level, and a first price of
	 * base_cost + rand(0..base_random_cost) + level x (per_level_cost + rand(0..per_level_random_cost)) emeralds,
	 * twice that for treasure enchantments, at most 64.
	 *
	 * @phpstan-param FunctionData $data
	 */
	private static function enchantBookForTrading(Item $item, string $itemName, array $data, TradeContext $context) : ?Item{
		$enchantments = self::getTradeableEnchantments();
		if(count($enchantments) === 0){
			return null;
		}
		[$enchantment, $treasure] = $enchantments[$context->getRandom()->nextBoundedInt(count($enchantments))];
		$level = $context->nextRange(1, $enchantment->getMaxLevel());

		$price = self::intParam($data, "base_cost", 2) + $context->nextRange(0, self::intParam($data, "base_random_cost", 5)) +
			$level * (self::intParam($data, "per_level_cost", 3) + $context->nextRange(0, self::intParam($data, "per_level_random_cost", 10)));
		if($treasure){
			$price *= 2;
		}
		$emeralds = $context->getWant(0);
		if($emeralds !== null){
			$emeralds->setCount(min(self::MAX_BOOK_PRICE, $price, $emeralds->getMaxStackSize()));
		}
		return VanillaItems::ENCHANTED_BOOK()->setCount($item->getCount())->addEnchantment(new EnchantmentInstance($enchantment, $level));
	}

	/**
	 * @phpstan-param FunctionData $data
	 */
	private static function intParam(array $data, string $key, int $default) : int{
		$value = $data[$key] ?? $default;
		return is_int($value) ? $value : $default;
	}

	/**
	 * Random aux value of the item (bed and banner colours, suspicious stew effects).
	 *
	 * @phpstan-param FunctionData $data
	 */
	private static function randomAuxValue(Item $item, string $itemName, array $data, TradeContext $context) : ?Item{
		$aux = self::range($data["values"] ?? null, $context);
		if($aux === null){
			return null;
		}
		$variant = TradeTables::resolveItem(TradeTables::itemId($itemName) . ":" . $aux);
		return $variant?->setCount($item->getCount());
	}

	/**
	 * Potion of a potion, splash potion or tipped arrow, by Bedrock potion id ("nightvision", "long_swiftness"...).
	 *
	 * @phpstan-param FunctionData $data
	 */
	private static function setPotion(Item $item, string $itemName, array $data, TradeContext $context) : ?Item{
		$id = $data["id"] ?? null;
		if(!is_string($id)){
			return null;
		}
		$key = str_replace("_", "", strtolower($id));
		$type = null;
		foreach(PotionType::cases() as $case){
			if(str_replace("_", "", strtolower($case->name)) === $key){
				$type = $case;
				break;
			}
		}
		if($type === null){
			return null;
		}
		if($item instanceof TippedArrow || $item instanceof Potion || $item instanceof SplashPotion){
			return $item->setType($type);
		}
		if($item->getTypeId() === VanillaItems::ARROW()->getTypeId()){
			return VanillaItems::TIPPED_ARROW()->setType($type)->setCount($item->getCount());
		}
		return null;
	}

	/**
	 * Dyed leather armour: one random dye colour, then a 30% chance of mixing in a second one and 20% of a third
	 * (Java's DyedArmorForEmeralds; Bedrock doesn't document its random_dye).
	 *
	 * @phpstan-param FunctionData $data
	 */
	private static function randomDye(Item $item, string $itemName, array $data, TradeContext $context) : ?Item{
		$random = $context->getRandom();
		$dyes = DyeColor::cases();
		$colors = [$dyes[$random->nextBoundedInt(count($dyes))]->getRgbValue()];
		if($random->nextFloat() < 0.3){
			$colors[] = $dyes[$random->nextBoundedInt(count($dyes))]->getRgbValue();
		}
		if($random->nextFloat() < 0.2){
			$colors[] = $dyes[$random->nextBoundedInt(count($dyes))]->getRgbValue();
		}
		$color = Color::mix(...$colors);
		if($item instanceof Armor || $item instanceof DyeableItem){
			return $item->setCustomColor($color);
		}
		return null;
	}
}
