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

use pocketmine\block\inventory\JigsawInventory;
use pocketmine\block\tile\Jigsaw as TileJigsaw;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\GameMasterBlocks;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function array_search;

/**
 * Jigsaw block (https://minecraft.wiki/w/Jigsaw_Block). Holds the connection data of a structure piece in its block
 * entity ({@link TileJigsaw}); the vanilla generator reads that data from the templates, so in the world it is only a
 * data holder that operators in creative mode can edit. The front faces away from the clicked block; the rotation
 * (0-3) turns the block around its front: for a front facing up or down it is the direction of the top
 * ({@link Jigsaw::TOP_DIRECTIONS}). Never drops.
 */
class Jigsaw extends Opaque implements AnyFacing{
	use AnyFacingTrait;

	public const MAX_ROTATION = 3;
	/** Direction of the top of a jigsaw facing up or down, by rotation */
	public const TOP_DIRECTIONS = [Facing::NORTH, Facing::EAST, Facing::SOUTH, Facing::WEST];

	protected int $rotation = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->boundedIntAuto(0, self::MAX_ROTATION, $this->rotation);
	}

	public function getRotation() : int{ return $this->rotation; }

	/** @return $this */
	public function setRotation(int $rotation) : self{
		if($rotation < 0 || $rotation > self::MAX_ROTATION){
			throw new \InvalidArgumentException("Rotation must be in range 0 ... " . self::MAX_ROTATION);
		}
		$this->rotation = $rotation;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null && !GameMasterBlocks::canUse($player)){
			return false;
		}
		$this->facing = $face;
		$this->rotation = 0;
		if($player !== null && Facing::axis($face) === Axis::Y){
			//vanilla JigsawBlock.getStateForPlacement(): the top points back at the player
			$top = Facing::opposite($player->getHorizontalFacing());
			$this->rotation = (int) array_search($top, self::TOP_DIRECTIONS, true);
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player === null || !GameMasterBlocks::canUse($player)){
			return false;
		}
		if($this->position->getWorld()->getTile($this->position) instanceof TileJigsaw){
			$player->setCurrentWindow(new JigsawInventory($this->position));
		}
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}
}
