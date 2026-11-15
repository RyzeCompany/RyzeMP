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

namespace pocketmine\network\mcpe\handler;

use pocketmine\block\CommandBlock;
use pocketmine\block\tile\CommandBlock as TileCommandBlock;
use pocketmine\block\utils\CommandBlockType;
use pocketmine\block\utils\GameMasterBlocks;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\CommandBlockUpdatePacket;
use pocketmine\player\Player;
use function mb_strlen;
use function preg_match;

/**
 * Edits sent by the command block screen of a command block (CommandBlockUpdatePacket with isBlock). Only operators
 * in creative mode may edit command blocks; other edits are refused and the block is sent again.
 *
 * @internal
 */
final class CommandBlockRequests{
	/** Squared distance from the player within which command blocks can be edited (same as structure blocks) */
	private const MAX_DISTANCE_SQUARED = 10000;
	public const MAX_NAME_LENGTH = 256;

	private function __construct(){
		//NOOP
	}

	/**
	 * Applies the edit. Returns false if it was refused.
	 */
	public static function handleBlockUpdate(NetworkSession $session, Player $player, CommandBlockUpdatePacket $packet) : bool{
		$pos = new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
		$world = $player->getWorld();
		if($pos->distanceSquared($player->getLocation()) > self::MAX_DISTANCE_SQUARED || !$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
			return false;
		}
		$block = $world->getBlock($pos);
		$tile = $world->getTile($pos);
		if(!$block instanceof CommandBlock || !$tile instanceof TileCommandBlock){
			return false;
		}
		$type = CommandBlockType::tryFrom($packet->commandBlockMode);
		if(
			!GameMasterBlocks::canUse($player) ||
			$type === null ||
			mb_strlen($packet->command) > TileCommandBlock::MAX_COMMAND_LENGTH ||
			mb_strlen($packet->name) > self::MAX_NAME_LENGTH ||
			preg_match('/[\x00-\x1f\x7f]/u', $packet->name) !== 0
		){
			foreach($world->createBlockUpdatePackets([$pos], $session->getTypeConverter()) as $updatePacket){
				$session->sendDataPacket($updatePacket);
			}
			return false;
		}

		$wasAuto = $tile->isAuto();
		$tile->setCommand($packet->command);
		$tile->setCustomName($packet->name);
		$tile->setTrackOutput($packet->shouldTrackOutput);
		$tile->setTickDelay($packet->tickDelay);
		$tile->setExecuteOnFirstTick($packet->executeOnFirstTick);
		$tile->setAuto(!$packet->isRedstoneMode);

		$newBlock = self::blockOfType($type)->setFacing($block->getFacing())->setConditional($packet->isConditional);
		//sends the block and its block entity to the viewers; a new type keeps the block entity
		$world->setBlock($pos, $newBlock);

		//turning "Always Active" on activates the block; a powered block turned into a repeating one starts running
		$placed = $world->getBlock($pos);
		$becameRepeating = $type === CommandBlockType::REPEATING && $block->getCommandBlockType() !== CommandBlockType::REPEATING;
		if($placed instanceof CommandBlock && !$tile->isClosed() && $tile->isActive() && ((!$wasAuto && $tile->isAuto()) || $becameRepeating)){
			$placed->activate($tile);
		}
		return true;
	}

	private static function blockOfType(CommandBlockType $type) : CommandBlock{
		return match($type){
			CommandBlockType::IMPULSE => VanillaBlocks::COMMAND_BLOCK(),
			CommandBlockType::REPEATING => VanillaBlocks::REPEATING_COMMAND_BLOCK(),
			CommandBlockType::CHAIN => VanillaBlocks::CHAIN_COMMAND_BLOCK(),
		};
	}
}
