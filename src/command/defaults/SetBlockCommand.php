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

use pocketmine\block\BlockTypeIds;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\utils\CommandBlockParser;
use pocketmine\command\utils\CommandBlockWriter;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use function count;
use function in_array;
use function strtolower;

/**
 * /setblock <position> <block> [states] [replace|destroy|keep], like Bedrock: only the main block layer changes (a
 * waterloggable block set in water keeps the water), the block keeps the states it is given, and the lower half of a
 * door or a two-block plant gets its upper half too.
 */
class SetBlockCommand extends VanillaCommand{

	public const MODES = ["replace", "destroy", "keep"];

	public function __construct(){
		parent::__construct(
			"setblock",
			KnownTranslationFactory::pocketmine_command_setblock_description(),
			KnownTranslationFactory::pocketmine_command_setblock_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_SETBLOCK);
	}

	public function getOverloads() : array{
		return [[
			CommandParameter::position("position"),
			CommandParameter::block("tileName"),
			CommandParameter::blockStates("blockStates"),
			CommandParameter::enum("oldBlockHandling", "SetBlockMode", self::MODES, true),
		], [
			CommandParameter::position("position"),
			CommandParameter::block("tileName"),
			CommandParameter::enum("oldBlockHandling", "SetBlockMode", self::MODES, true),
		]];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 4){
			throw new InvalidCommandSyntaxException();
		}
		$origin = self::getCommandOrigin($sender) ?? throw new InvalidCommandSyntaxException();
		$pos = CommandCoordinates::parsePosition($origin, $args[0], $args[1], $args[2])->floor();
		$offset = 3;
		$parsed = CommandBlockParser::parse($args, $offset);
		$mode = "replace";
		if(isset($args[$offset])){
			$mode = strtolower($args[$offset++]);
			if(!in_array($mode, self::MODES, true) || isset($args[$offset])){
				throw new InvalidCommandSyntaxException();
			}
		}

		$world = $origin->getWorld();
		$x = (int) $pos->x;
		$y = (int) $pos->y;
		$z = (int) $pos->z;
		if(!$world->isInWorld($x, $y, $z)){
			//BDS 1.26.60 answers "couldn't be placed" for heights outside the world
			$sender->sendMessage(KnownTranslationFactory::commands_setblock_noChange()->prefix(TextFormat::RED));
			return true;
		}
		if(!CommandBlockWriter::prepareChunk($world, $x, $z)){
			$sender->sendMessage(KnownTranslationFactory::commands_setblock_outOfWorld()->prefix(TextFormat::RED));
			return true;
		}
		if($mode === "keep" && $world->getBlockAt($x, $y, $z, addToCache: false)->getTypeId() !== BlockTypeIds::AIR){
			$sender->sendMessage(KnownTranslationFactory::commands_setblock_noChange()->prefix(TextFormat::RED));
			return true;
		}
		if(!CommandBlockWriter::setWithUpperHalf($world, $x, $y, $z, $parsed->getBlock(), $mode === "destroy")){
			$sender->sendMessage(KnownTranslationFactory::commands_setblock_noChange()->prefix(TextFormat::RED));
			return true;
		}
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_setblock_success());
		return true;
	}
}
