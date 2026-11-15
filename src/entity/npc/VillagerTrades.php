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

namespace pocketmine\entity\npc;

use pocketmine\entity\npc\trade\ExplorerMaps;
use pocketmine\entity\npc\trade\TradeContext;
use pocketmine\entity\npc\trade\TradeTables;
use pocketmine\item\Item;
use pocketmine\utils\Random;
use function array_keys;
use function array_unique;
use function array_values;
use function mt_rand;

/**
 * Trades of each villager profession and of the wandering trader, from Mojang's trade tables
 * (trading/economy_trades/*.json, see {@link TradeTables}): per level, every group of the table gives num_to_select
 * random trades (e.g. a novice farmer buys one crop and sells bread, a novice armorer buys coal and sells one iron
 * armour piece), with the table's price multipliers (0.2 on gear and enchanted books), enchanted gear
 * (enchant_with_levels) and librarian enchanted books. Trades are picked when the villager reaches the level.
 * Cartographer explorer maps point at the nearest structure of the villager's world ({@link ExplorerMaps}).
 */
final class VillagerTrades{
	/** Trader experience needed for each level (vanilla: total_exp_required of every villager table). */
	public const TIER_EXP_REQUIREMENTS = [0, 10, 70, 150, 250];

	/**
	 * profession => trade table name (file name of trading/economy_trades without "_trades")
	 */
	private const TABLES = [
		VillagerV2::PROFESSION_FARMER => "farmer",
		VillagerV2::PROFESSION_FISHERMAN => "fisherman",
		VillagerV2::PROFESSION_SHEPHERD => "shepherd",
		VillagerV2::PROFESSION_FLETCHER => "fletcher",
		VillagerV2::PROFESSION_LIBRARIAN => "librarian",
		VillagerV2::PROFESSION_CARTOGRAPHER => "cartographer",
		VillagerV2::PROFESSION_CLERIC => "cleric",
		VillagerV2::PROFESSION_ARMORER => "armorer",
		VillagerV2::PROFESSION_WEAPONSMITH => "weapon_smith",
		VillagerV2::PROFESSION_TOOLSMITH => "tool_smith",
		VillagerV2::PROFESSION_BUTCHER => "butcher",
		VillagerV2::PROFESSION_LEATHERWORKER => "leather_worker",
		VillagerV2::PROFESSION_MASON => "stone_mason",
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * Name of the trade table of the profession, or null if it doesn't trade.
	 */
	public static function getTableName(int $profession) : ?string{
		return self::TABLES[$profession] ?? null;
	}

	/**
	 * Creates the trades of the profession for the level, or none if the profession doesn't trade.
	 *
	 * @param int  $markVariant villager type ({@link VillagerV2::TYPE_PLAINS}...), for trades that depend on it
	 * @param bool $inOverworld whether the villager is in the overworld (explorer maps are only sold there)
	 * @phpstan-param (\Closure(Item $map, string $destination) : ?Item)|null $explorationMaps makes cartographer
	 *                explorer maps (see {@link ExplorerMaps}); their trades are skipped without it
	 *
	 * @return TradeOffer[]
	 * @phpstan-return list<TradeOffer>
	 */
	public static function createOffers(int $profession, int $tier, ?Random $random = null, int $markVariant = VillagerV2::TYPE_PLAINS, bool $inOverworld = true, ?\Closure $explorationMaps = null) : array{
		$table = self::TABLES[$profession] ?? null;
		if($table === null){
			return [];
		}
		return TradeTables::createOffers($table, $tier, new TradeContext($random ?? new Random(mt_rand()), $markVariant, $inOverworld, $explorationMaps));
	}

	/**
	 * Two "buy" trades, two special trades and five common ones, like the vanilla wandering trader.
	 *
	 * @return TradeOffer[]
	 * @phpstan-return list<TradeOffer>
	 */
	public static function createWanderingTraderOffers(?Random $random = null) : array{
		return TradeTables::createOffers(TradeTables::WANDERING_TRADER, 0, new TradeContext($random ?? new Random(mt_rand())));
	}

	/**
	 * Returns the item names of the tables that this server doesn't know (their trades are skipped).
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getUnknownItemNames() : array{
		$names = [];
		foreach(TradeTables::getTableNames() as $table){
			foreach(TradeTables::getTiers($table) as $tier){
				foreach($tier["groups"] as $group){
					foreach($group["trades"] as $trade){
						foreach([...$trade["wants"], ...$trade["gives"]] as $entry){
							foreach(isset($entry["choice"]) ? $entry["choice"] : [$entry] as $choice){
								if(TradeTables::resolveItem($choice["item"]) === null){
									$names[] = $choice["item"];
								}
							}
						}
					}
				}
			}
		}
		return array_values(array_unique($names));
	}

	/**
	 * @return int[] professions with trades
	 * @phpstan-return list<int>
	 */
	public static function getTradingProfessions() : array{
		return array_keys(self::TABLES);
	}
}
