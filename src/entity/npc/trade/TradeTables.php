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

use pocketmine\block\utils\ArchaeologyLoot;
use pocketmine\entity\npc\TradeOffer;
use pocketmine\item\Item;
use pocketmine\loot\LootItemConverter;
use pocketmine\utils\AssumptionFailedError;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function ksort;
use function preg_match;
use function strlen;
use function substr;

/**
 * Makes trade offers from the Bedrock trade tables ({@link VanillaTradeTables}, trading/economy_trades/*.json).
 *
 * For a level, each group gives num_to_select of its trades, picked at random (-1 = all of them, in table order).
 * Trades that can't be made are skipped and another trade of the group is picked instead: unknown items, unsupported
 * item functions (see {@link TradeFunctions}) and "choice" lists whose filters exclude every entry. Quantities given as
 * a range are rolled when the offer is made. Absent fields default to: trader_exp 1, reward_exp true, price
 * multiplier 0 (the trade's price doesn't change with reputation).
 *
 * The tables are constant PHP data; offers are only made when a trader needs them (first interaction, level up).
 *
 * @phpstan-type TradeItemEntry array{item: string, min: int, max: int, mult?: float, functions?: list<array<array-key, mixed>>, filters?: array<array-key, mixed>}
 * @phpstan-type TradeChoiceEntry array{choice: list<TradeItemEntry>, filters?: array<array-key, mixed>}
 * @phpstan-type TradeData array{wants: list<TradeItemEntry|TradeChoiceEntry>, gives: list<TradeItemEntry|TradeChoiceEntry>, max_uses: int, trader_exp?: int, reward_exp?: bool}
 * @phpstan-type TierData array{exp: int, groups: list<array{select: int, trades: list<TradeData>}>}
 *
 * @internal
 */
final class TradeTables{
	public const WANDERING_TRADER = "wandering_trader";

	/** Trader experience of trades without trader_exp (butcher rabbit stew, cleric redstone, mason bricks). */
	public const DEFAULT_TRADER_EXP = 1;

	/**
	 * @var Item[]|null[] item name => prototype, null if unknown
	 * @phpstan-var array<string, Item|null>
	 */
	private static array $items = [];
	/**
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private static array $reported = [];
	/**
	 * @var mixed[][][] table => checked levels
	 * @phpstan-var array<string, list<TierData>>
	 */
	private static array $tiers = [];

	private function __construct(){
		//NOOP
	}

	public static function hasTable(string $table) : bool{
		return isset(VanillaTradeTables::TABLES[$table]);
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getTableNames() : array{
		return array_keys(VanillaTradeTables::TABLES);
	}

	/**
	 * Levels of a table (none if it doesn't exist). Each table is checked once, on first use: PHPStan gives up on the
	 * type of the big generated constant, and a bad entry should fail loudly rather than make odd trades.
	 *
	 * @return mixed[][]
	 * @phpstan-return list<TierData>
	 */
	public static function getTiers(string $table) : array{
		if(!isset(self::$tiers[$table])){
			$data = VanillaTradeTables::TABLES[$table] ?? null;
			if($data === null){
				return [];
			}
			self::$tiers[$table] = self::checkTiers($data, $table);
		}
		return self::$tiers[$table];
	}

	/**
	 * @phpstan-return list<TierData>
	 */
	private static function checkTiers(mixed $data, string $where) : array{
		$tiers = [];
		foreach(self::checkList($data, $where) as $t => $tier){
			if(!is_array($tier) || !is_int($tier["exp"] ?? null)){
				throw new AssumptionFailedError("$where level $t: expected exp");
			}
			$groups = [];
			foreach(self::checkList($tier["groups"] ?? null, "$where level $t") as $g => $group){
				if(!is_array($group) || !is_int($group["select"] ?? null)){
					throw new AssumptionFailedError("$where level $t group $g: expected select");
				}
				$trades = [];
				foreach(self::checkList($group["trades"] ?? null, "$where level $t group $g") as $i => $trade){
					$tradeWhere = "$where level $t group $g trade $i";
					if(!is_array($trade) || !is_int($trade["max_uses"] ?? null)){
						throw new AssumptionFailedError("$tradeWhere: expected max_uses");
					}
					$checked = [
						"wants" => self::checkEntries($trade["wants"] ?? null, "$tradeWhere wants"),
						"gives" => self::checkEntries($trade["gives"] ?? null, "$tradeWhere gives"),
						"max_uses" => $trade["max_uses"]
					];
					if(isset($trade["trader_exp"])){
						$checked["trader_exp"] = is_int($trade["trader_exp"]) ? $trade["trader_exp"] : throw new AssumptionFailedError("$tradeWhere: bad trader_exp");
					}
					if(isset($trade["reward_exp"])){
						$checked["reward_exp"] = is_bool($trade["reward_exp"]) ? $trade["reward_exp"] : throw new AssumptionFailedError("$tradeWhere: bad reward_exp");
					}
					$trades[] = $checked;
				}
				$groups[] = ["select" => $group["select"], "trades" => $trades];
			}
			$tiers[] = ["exp" => $tier["exp"], "groups" => $groups];
		}
		return $tiers;
	}

	/**
	 * @return mixed[]
	 * @phpstan-return list<mixed>
	 */
	private static function checkList(mixed $data, string $where) : array{
		if(!is_array($data) || !array_is_list($data)){
			throw new AssumptionFailedError("$where: expected a list");
		}
		return $data;
	}

	/**
	 * @phpstan-return list<TradeItemEntry|TradeChoiceEntry>
	 */
	private static function checkEntries(mixed $data, string $where) : array{
		$entries = [];
		foreach(self::checkList($data, $where) as $i => $entry){
			if(is_array($entry) && isset($entry["choice"])){
				$choices = [];
				foreach(self::checkList($entry["choice"], "$where $i choice") as $choice){
					$choices[] = self::checkItemEntry($choice, "$where $i choice");
				}
				$checked = ["choice" => $choices];
				if(isset($entry["filters"])){
					$checked["filters"] = is_array($entry["filters"]) ? $entry["filters"] : throw new AssumptionFailedError("$where $i: bad filters");
				}
				$entries[] = $checked;
			}else{
				$entries[] = self::checkItemEntry($entry, "$where $i");
			}
		}
		return $entries;
	}

	/**
	 * @phpstan-return TradeItemEntry
	 */
	private static function checkItemEntry(mixed $entry, string $where) : array{
		if(!is_array($entry) || !is_string($entry["item"] ?? null) || !is_int($entry["min"] ?? null) || !is_int($entry["max"] ?? null)){
			throw new AssumptionFailedError("$where: expected item, min and max");
		}
		$checked = ["item" => $entry["item"], "min" => $entry["min"], "max" => $entry["max"]];
		if(isset($entry["mult"])){
			$checked["mult"] = is_float($entry["mult"]) ? $entry["mult"] : throw new AssumptionFailedError("$where: bad mult");
		}
		if(isset($entry["functions"])){
			$functions = [];
			foreach(self::checkList($entry["functions"], "$where functions") as $function){
				$functions[] = is_array($function) ? $function : throw new AssumptionFailedError("$where: bad function");
			}
			$checked["functions"] = $functions;
		}
		if(isset($entry["filters"])){
			$checked["filters"] = is_array($entry["filters"]) ? $entry["filters"] : throw new AssumptionFailedError("$where: bad filters");
		}
		return $checked;
	}

	/**
	 * Total trader experience needed for each level of the table.
	 *
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public static function getTierExpRequirements(string $table) : array{
		$result = [];
		foreach(self::getTiers($table) as $tier){
			$result[] = $tier["exp"];
		}
		return $result;
	}

	/**
	 * Makes the offers of a level of the table (none if the table or the level doesn't exist).
	 *
	 * @return TradeOffer[]
	 * @phpstan-return list<TradeOffer>
	 */
	public static function createOffers(string $table, int $tier, TradeContext $context) : array{
		$tierData = self::getTiers($table)[$tier] ?? null;
		if($tierData === null){
			return [];
		}
		$random = $context->getRandom();
		$offers = [];
		foreach($tierData["groups"] as $group){
			$trades = $group["trades"];
			$count = count($trades);
			$select = $group["select"] < 0 ? $count : $group["select"];
			$order = [];
			for($i = 0; $i < $count; ++$i){
				$order[] = $i;
			}
			if($select < $count){
				//Fisher-Yates with the context's random, so tests can seed it
				for($i = $count - 1; $i > 0; --$i){
					$j = $random->nextBoundedInt($i + 1);
					[$order[$i], $order[$j]] = [$order[$j], $order[$i]];
				}
			}
			$picked = [];
			foreach($order as $index){
				if(count($picked) >= $select){
					break;
				}
				$offer = self::createOffer($table, $trades[$index], $tier, $context);
				if($offer !== null){
					$picked[$index] = $offer;
				}
			}
			//table order, like vanilla's trade screen
			ksort($picked);
			foreach($picked as $offer){
				$offers[] = $offer;
			}
		}
		return $offers;
	}

	/**
	 * @phpstan-param TradeData $trade
	 */
	private static function createOffer(string $table, array $trade, int $tier, TradeContext $context) : ?TradeOffer{
		$wants = [];
		$multipliers = [];
		foreach($trade["wants"] as $entry){
			$picked = self::pickEntry($entry, $context);
			if($picked === null){
				return null;
			}
			$item = self::createItem($table, $picked, $context);
			if($item === null){
				return null;
			}
			$wants[] = $item;
			$multipliers[] = $picked["mult"] ?? 0.0;
		}
		if(count($wants) === 0 || count($wants) > 2 || count($trade["gives"]) !== 1){
			return null;
		}
		$context->setWants($wants);
		$picked = self::pickEntry($trade["gives"][0], $context);
		$sell = $picked !== null ? self::createItem($table, $picked, $context) : null;
		$context->setWants([]);
		if($sell === null){
			return null;
		}
		return new TradeOffer(
			$wants[0],
			$wants[1] ?? null,
			$sell,
			$trade["max_uses"],
			$tier,
			$trade["trader_exp"] ?? self::DEFAULT_TRADER_EXP,
			$trade["reward_exp"] ?? true,
			0,
			$multipliers[0],
			$multipliers[1] ?? 0.0
		);
	}

	/**
	 * The entry itself, or a random entry of its choice list among those whose filters match; null if nothing
	 * matches.
	 *
	 * @phpstan-param TradeItemEntry|TradeChoiceEntry $entry
	 * @phpstan-return TradeItemEntry|null
	 */
	private static function pickEntry(array $entry, TradeContext $context) : ?array{
		if(isset($entry["filters"]) && !self::matchesFilter($entry["filters"], $context)){
			return null;
		}
		if(!isset($entry["choice"])){
			return $entry;
		}
		$candidates = [];
		foreach($entry["choice"] as $choice){
			if(!isset($choice["filters"]) || self::matchesFilter($choice["filters"], $context)){
				$candidates[] = $choice;
			}
		}
		$count = count($candidates);
		if($count === 0){
			return null;
		}
		return $count === 1 ? $candidates[0] : $candidates[$context->getRandom()->nextBoundedInt($count)];
	}

	/**
	 * @phpstan-param TradeItemEntry $entry
	 */
	private static function createItem(string $table, array $entry, TradeContext $context) : ?Item{
		$item = self::resolveItem($entry["item"]);
		if($item === null){
			self::report($table, "unknown item " . $entry["item"]);
			return null;
		}
		$item->setCount($context->nextRange($entry["min"], $entry["max"]));
		foreach($entry["functions"] ?? [] as $function){
			$name = $function["function"] ?? null;
			if(!is_string($name)){
				return null;
			}
			$item = TradeFunctions::apply($name, $item, $entry["item"], $function, $context);
			if($item === null){
				self::report($table, "item function $name (unsupported, or not possible for this trader)");
				return null;
			}
		}
		return $item;
	}

	private static function report(string $table, string $message) : void{
		$key = $table . " " . $message;
		if(!isset(self::$reported[$key])){
			self::$reported[$key] = true;
			\GlobalLogger::get()->debug("Trade table $table: skipped trades with $message");
		}
	}

	/**
	 * Evaluates a Bedrock filter (all_of, any_of, none_of, or a test) against the trader. Supported tests:
	 * is_mark_variant and in_overworld; any other test fails.
	 *
	 * @param mixed[] $filter
	 */
	public static function matchesFilter(array $filter, TradeContext $context) : bool{
		if(array_is_list($filter)){
			//a bare list is all_of
			$filter = ["all_of" => $filter];
		}
		foreach(["all_of", "any_of", "none_of"] as $group){
			$list = $filter[$group] ?? null;
			if($list === null){
				continue;
			}
			if(!is_array($list)){
				return false;
			}
			if(!array_is_list($list)){
				$list = [$list];
			}
			$any = false;
			foreach($list as $child){
				$matches = is_array($child) && self::matchesFilter($child, $context);
				if($group === "all_of" && !$matches){
					return false;
				}
				if($matches){
					$any = true;
				}
			}
			return match($group){
				"all_of" => true,
				"any_of" => $any,
				"none_of" => !$any,
			};
		}
		$value = $filter["value"] ?? true;
		$actual = match($filter["test"] ?? null){
			"is_mark_variant" => $context->getMarkVariant(),
			"in_overworld" => $context->isInOverworld(),
			default => null
		};
		if($actual === null || (!is_int($value) && !is_bool($value))){
			return false;
		}
		if(is_bool($actual)){
			$actual = $actual ? 1 : 0;
		}
		if(is_bool($value)){
			$value = $value ? 1 : 0;
		}
		return match($filter["operator"] ?? "=="){
			"=", "==", "equals" => $actual === $value,
			"!=", "<>", "not" => $actual !== $value,
			"<" => $actual < $value,
			"<=" => $actual <= $value,
			">" => $actual > $value,
			">=" => $actual >= $value,
			default => false
		};
	}

	/**
	 * Bedrock item name without its aux value ("minecraft:dye:4" -> "minecraft:dye").
	 */
	public static function itemId(string $name) : string{
		return preg_match('/^(.+:[^:]+):\d+$/', $name, $matches) === 1 ? $matches[1] : $name;
	}

	/**
	 * A new item for a Bedrock item name with an optional aux value ("minecraft:emerald", "minecraft:dye:4",
	 * "minecraft:potion:8"), or null if this server doesn't know it. Legacy aux values are upgraded like saved items;
	 * names with a flattened id and a leftover aux ("minecraft:birch_log:2") fall back to the plain name.
	 */
	public static function resolveItem(string $name) : ?Item{
		if(!array_key_exists($name, self::$items)){
			$id = self::itemId($name);
			$aux = $id !== $name ? (int) substr($name, strlen($id) + 1) : 0;
			$item = ArchaeologyLoot::createItem($id, $aux) ?? LootItemConverter::bedrockToItem($id, $aux);
			if($item === null && $aux !== 0){
				$item = ArchaeologyLoot::createItem($id, 0) ?? LootItemConverter::bedrockToItem($id, 0);
			}
			self::$items[$name] = $item;
		}
		$item = self::$items[$name];
		return $item !== null ? clone $item : null;
	}
}
