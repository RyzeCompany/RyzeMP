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
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\utils\CommandBlockParser;
use pocketmine\command\utils\CommandBlockWriter;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\ParsedBlock;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use function count;
use function in_array;
use function max;
use function min;
use function strtolower;

/**
 * /fill <from> <to> <block> [states] [replace|destroy|keep|hollow|outline] and
 * /fill <from> <to> <block> [states] replace <filter> [filterStates], like Bedrock: at most 32768 blocks, blocks are
 * set like /setblock (see {@link CommandBlockWriter}) and the count is the number of blocks that changed.
 */
class FillCommand extends VanillaCommand{

	public const MAX_BLOCKS = 32768;
	public const MODES = ["destroy", "hollow", "keep", "outline", "replace"];

	public function __construct(){
		parent::__construct(
			"fill",
			KnownTranslationFactory::pocketmine_command_fill_description(),
			KnownTranslationFactory::pocketmine_command_fill_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_FILL);
	}

	public function getOverloads() : array{
		$mode = CommandParameter::enum("oldBlockHandling", "FillMode", self::MODES, true);
		$replace = CommandParameter::enum("oldBlockHandling", "FillReplaceMode", ["replace"]);
		return [
			[CommandParameter::position("from"), CommandParameter::position("to"), CommandParameter::block("tileName"), CommandParameter::blockStates("blockStates"), $mode],
			[CommandParameter::position("from"), CommandParameter::position("to"), CommandParameter::block("tileName"), $mode],
			[CommandParameter::position("from"), CommandParameter::position("to"), CommandParameter::block("tileName"), CommandParameter::blockStates("blockStates"), $replace, CommandParameter::block("replaceTileName", true), CommandParameter::blockStates("replaceBlockStates", true)],
			[CommandParameter::position("from"), CommandParameter::position("to"), CommandParameter::block("tileName"), $replace, CommandParameter::block("replaceTileName", true), CommandParameter::blockStates("replaceBlockStates", true)],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 7){
			throw new InvalidCommandSyntaxException();
		}
		$origin = self::getCommandOrigin($sender) ?? throw new InvalidCommandSyntaxException();
		$from = CommandCoordinates::parsePosition($origin, $args[0], $args[1], $args[2])->floor();
		$to = CommandCoordinates::parsePosition($origin, $args[3], $args[4], $args[5])->floor();
		$offset = 6;
		$block = CommandBlockParser::parse($args, $offset);
		$mode = "replace";
		$filter = null;
		if(isset($args[$offset])){
			$mode = strtolower($args[$offset++]);
			if(!in_array($mode, self::MODES, true)){
				throw new InvalidCommandSyntaxException();
			}
			if($mode === "replace" && isset($args[$offset])){
				$filter = CommandBlockParser::parse($args, $offset);
			}
			if(isset($args[$offset])){
				throw new InvalidCommandSyntaxException();
			}
		}

		$minX = (int) min($from->x, $to->x);
		$minY = (int) min($from->y, $to->y);
		$minZ = (int) min($from->z, $to->z);
		$maxX = (int) max($from->x, $to->x);
		$maxY = (int) max($from->y, $to->y);
		$maxZ = (int) max($from->z, $to->z);
		$volume = ($maxX - $minX + 1) * ($maxY - $minY + 1) * ($maxZ - $minZ + 1);
		if($volume > self::MAX_BLOCKS){
			$sender->sendMessage(KnownTranslationFactory::commands_fill_tooManyBlocks((string) $volume, (string) self::MAX_BLOCKS)->prefix(TextFormat::RED));
			return true;
		}

		$world = $origin->getWorld();
		if(!$world->isInWorld($minX, $minY, $minZ) || !$world->isInWorld($maxX, $maxY, $maxZ)){
			$sender->sendMessage(KnownTranslationFactory::commands_fill_outOfWorld()->prefix(TextFormat::RED));
			return true;
		}
		for($cx = $minX >> 4; $cx <= $maxX >> 4; ++$cx){
			for($cz = $minZ >> 4; $cz <= $maxZ >> 4; ++$cz){
				if(!CommandBlockWriter::prepareChunk($world, $cx << 4, $cz << 4)){
					$sender->sendMessage(KnownTranslationFactory::commands_fill_outOfWorld()->prefix(TextFormat::RED));
					return true;
				}
			}
		}

		$count = self::fill($world, $minX, $minY, $minZ, $maxX, $maxY, $maxZ, $block, $mode, $filter);
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_fill_success((string) $count));
		return true;
	}

	/**
	 * Fills the box and returns how many blocks changed.
	 */
	private static function fill(World $world, int $minX, int $minY, int $minZ, int $maxX, int $maxY, int $maxZ, ParsedBlock $parsed, string $mode, ?ParsedBlock $filter) : int{
		$block = $parsed->getBlock();
		$air = VanillaBlocks::AIR();
		$count = 0;
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				for($y = $minY; $y <= $maxY; ++$y){
					$edge = $x === $minX || $x === $maxX || $y === $minY || $y === $maxY || $z === $minZ || $z === $maxZ;
					$target = $block;
					if(!$edge && $mode === "hollow"){
						$target = $air;
					}elseif(!$edge && $mode === "outline"){
						continue;
					}
					if($mode === "keep" || $filter !== null){
						$current = $world->getBlockAt($x, $y, $z, addToCache: false);
						if($mode === "keep" && $current->getTypeId() !== BlockTypeIds::AIR){
							continue;
						}
						if($filter !== null && !$filter->matches($current)){
							continue;
						}
					}
					if(CommandBlockWriter::set($world, $x, $y, $z, $target, $mode === "destroy")){
						$count++;
					}
				}
			}
		}
		return $count;
	}
}
