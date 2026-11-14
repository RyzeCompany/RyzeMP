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

namespace pocketmine\command\parameter;

use pocketmine\command\utils\CommandBlockParser;
use pocketmine\command\utils\CommandItemParser;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\player\GameMode;
use function array_map;
use function is_numeric;
use function sort;
use const SORT_STRING;

/**
 * Names and values of the enums shared by the built-in commands. The names are Bedrock's, so plugins describing the
 * same kind of argument can reuse them (enums are shared by name in the command list sent to clients: always use these
 * helpers or {@link CommandParameter} factories for these names, so the values match).
 */
final class CommandEnums{
	public const BOOLEAN = "Boolean";
	public const ITEM = "Item";
	/** Block names (Bedrock's "Block" enum). */
	public const BLOCK = "Block";
	public const EFFECT = "Effect";
	public const ENCHANT = "Enchant";
	public const GAME_MODE = "GameMode";
	public const DIFFICULTY = "Difficulty";
	public const COMMAND_NAME = "CommandName";

	private function __construct(){
		//NOOP
	}

	/**
	 * Vanilla item IDs (without the minecraft: prefix) the server can create.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function items() : array{
		return CommandItemParser::getVanillaIds();
	}

	/**
	 * Names of the blocks the server knows (without "minecraft:"), as {@link \pocketmine\command\utils\CommandBlockParser}
	 * accepts them.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function blocks() : array{
		return CommandBlockParser::getBlockNames();
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function effects() : array{
		return self::sortedAliases(StringToEffectParser::getInstance()->getKnownAliases());
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function enchantments() : array{
		return self::sortedAliases(StringToEnchantmentParser::getInstance()->getKnownAliases());
	}

	/**
	 * Game mode names and letters accepted by /gamemode, including Bedrock's "default" (the server's default game mode)
	 * if $withDefault is true.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function gameModes(bool $withDefault = true) : array{
		$values = [];
		foreach(GameMode::cases() as $gameMode){
			foreach($gameMode->getAliases() as $alias){
				//vanilla values only: PocketMine's extra spectator aliases (v, view) are not part of Bedrock's enum
				if(!is_numeric($alias) && $alias !== "v" && $alias !== "view"){
					$values[] = $alias;
				}
			}
		}
		if($withDefault){
			$values[] = "default";
			$values[] = "d";
		}
		return $values;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function difficulties() : array{
		return ["peaceful", "easy", "normal", "hard", "p", "e", "n", "h"];
	}

	/**
	 * @param string[]|int[] $aliases
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function sortedAliases(array $aliases) : array{
		$values = array_map(fn(string|int $alias) => (string) $alias, $aliases);
		sort($values, SORT_STRING);
		return $values;
	}
}
