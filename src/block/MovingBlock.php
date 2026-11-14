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

namespace pocketmine\block;

use pocketmine\block\tile\MovingBlock as TileMovingBlock;
use pocketmine\block\tile\PistonArm as TilePistonArm;
use pocketmine\block\utils\PistonPushReaction;
use pocketmine\block\utils\SupportType;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\format\Chunk;

/**
 * Placeholder for a block being moved by a piston. It stands at the block's destination; the piston replaces it with
 * the moved block once its arm has finished moving.
 */
class MovingBlock extends Transparent{

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function canBePlaced() : bool{
		return false;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		return false;
	}

	public function getPistonPushReaction() : PistonPushReaction{
		return PistonPushReaction::BLOCK;
	}

	public function getDrops(Item $item) : array{
		return [];
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		return VanillaItems::AIR();
	}

	public function onScheduledUpdate() : void{
		//runs one tick after the block entity was created or loaded, then every tick while the piston moves: if no
		//piston is moving this block any more (it was unloaded or removed mid-move), put the moved block in place now
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileMovingBlock){
			$world->setBlock($this->position, VanillaBlocks::AIR());
			return;
		}
		$pistonPos = $tile->getPistonPos();
		if(
			$pistonPos !== null &&
			$world->isInWorld($pistonPos->getFloorX(), $pistonPos->getFloorY(), $pistonPos->getFloorZ()) &&
			$world->isChunkLoaded($pistonPos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pistonPos->getFloorZ() >> Chunk::COORD_BIT_SIZE)
		){
			$arm = $world->getTile($pistonPos);
			if($arm instanceof TilePistonArm && $arm->isMoving()){
				//check again until the piston is done, in case it is removed without finishing (explosions...)
				$world->scheduleDelayedBlockUpdate($this->position, 1);
				return;
			}
		}
		Piston::placeMovedBlock($world, $this->position, $tile);
	}
}
