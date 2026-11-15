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
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\utils\CommandItemParser;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\item\LegacyStringToItemParserException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use function array_slice;
use function count;
use function implode;
use function is_numeric;

/**
 * /give <player: target> <itemName> [amount] [data] [components]: gives the item to every target (1 by default, like
 * Bedrock); what does not fit in the inventory is dropped at the player's feet.
 *
 * The item may be any PocketMine item name or vanilla item ID. [data] selects a variant (e.g. the potion type).
 * [components] is Bedrock's JSON ({"minecraft:can_destroy":{"blocks":["stone"]}}, minecraft:can_place_on,
 * minecraft:keep_on_death, minecraft:item_lock), or PocketMine's JSON NBT tags, which may also directly follow the
 * amount.
 */
class GiveCommand extends VanillaCommand implements ExhaustiveOverloads{
	public function __construct(){
		parent::__construct(
			"give",
			KnownTranslationFactory::pocketmine_command_give_description(),
			KnownTranslationFactory::pocketmine_command_give_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_GIVE_SELF,
			DefaultPermissionNames::COMMAND_GIVE_OTHER
		]);
	}

	public function getOverloads() : array{
		return [[
			CommandParameter::target("player"),
			CommandParameter::item("itemName"),
			CommandParameter::int("amount", true),
			CommandParameter::int("data", true),
			CommandParameter::json("components", true)
		]];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}

		$players = $this->fetchPermittedPlayerTargets($sender, $args[0], DefaultPermissionNames::COMMAND_GIVE_SELF, DefaultPermissionNames::COMMAND_GIVE_OTHER);
		if(count($players) === 0){
			return true;
		}

		try{
			$item = CommandItemParser::parse($args[1]);
		}catch(LegacyStringToItemParserException $e){
			$sender->sendMessage(KnownTranslationFactory::commands_give_item_notFound($args[1])->prefix(TextFormat::RED));
			return true;
		}

		if(!isset($args[2])){
			$item->setCount(1);
		}else{
			$count = $this->getBoundedInt($sender, $args[2], 1, 32767);
			if($count === null){
				return true;
			}
			$item->setCount($count);
		}

		$tagsStart = 3;
		if(isset($args[3]) && is_numeric($args[3])){
			$data = $this->getBoundedInt($sender, $args[3], 0, 32767);
			if($data === null){
				return true;
			}
			$variant = CommandItemParser::withData($item, $data);
			if($variant === null){
				$sender->sendMessage(KnownTranslationFactory::commands_give_item_notFound($args[1] . ":" . $args[3])->prefix(TextFormat::RED));
				return true;
			}
			$item = $variant;
			$tagsStart = 4;
		}

		if(isset($args[$tagsStart])){
			$error = CommandItemParser::applyTags($item, implode(" ", array_slice($args, $tagsStart)));
			if($error !== null){
				$sender->sendMessage(KnownTranslationFactory::commands_give_tagError($error));
				return true;
			}
		}

		foreach($players as $player){
			foreach($player->getInventory()->addItem(clone $item) as $leftover){
				$player->getWorld()->dropItem($player->getPosition(), $leftover);
			}
		}

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_give_success(
			$item->getName() . " (" . $args[1] . ")",
			(string) $item->getCount(),
			TargetSelector::joinNames($players)
		));
		return true;
	}
}
