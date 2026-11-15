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

use pocketmine\block\utils\FenceConnectionRules;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\WallConnectionType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use function count;

/**
 * @phpstan-type WallConnectionSet array<Facing::NORTH|Facing::EAST|Facing::SOUTH|Facing::WEST, WallConnectionType>
 */
class Wall extends Transparent{
	private const EPSILON = 0.0001;
	/** The block above covers a side when it reaches past these bounds (the outer quarter of that side's arm)... */
	private const OUTER_MIN = 4 / 16;
	private const OUTER_MAX = 12 / 16;
	/** ...while overlapping the middle line of the arm. */
	private const ARM_MIN = 7 / 16;
	private const ARM_MAX = 9 / 16;

	/**
	 * @var WallConnectionType[]
	 * @phpstan-var WallConnectionSet
	 */
	protected array $connections = [];
	protected bool $post = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->wallConnections($this->connections);
		$w->bool($this->post);
	}

	/**
	 * @return WallConnectionType[]
	 * @phpstan-return WallConnectionSet
	 */
	public function getConnections() : array{ return $this->connections; }

	public function getConnection(int $face) : ?WallConnectionType{
		return $this->connections[$face] ?? null;
	}

	/**
	 * @param WallConnectionType[] $connections
	 * @phpstan-param WallConnectionSet $connections
	 * @return $this
	 */
	public function setConnections(array $connections) : self{
		$this->connections = $connections;
		return $this;
	}

	/** @return $this */
	public function setConnection(int $face, ?WallConnectionType $type) : self{
		if($face !== Facing::NORTH && $face !== Facing::SOUTH && $face !== Facing::WEST && $face !== Facing::EAST){
			throw new \InvalidArgumentException("Facing can only be north, east, south or west");
		}
		if($type !== null){
			$this->connections[$face] = $type;
		}else{
			unset($this->connections[$face]);
		}
		return $this;
	}

	public function isPost() : bool{ return $this->post; }

	/** @return $this */
	public function setPost(bool $post) : self{
		$this->post = $post;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->recalculateConnections();
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if($this->recalculateConnections()){
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	/**
	 * Walls connect to other walls, glass panes and bars, fence gates in line with them and sturdy faces (Java
	 * WallBlock.connectsTo).
	 */
	private function connectsTo(Block $block, int $facing) : bool{
		if($block instanceof Wall || $block instanceof Thin){
			return true;
		}
		if($block instanceof FenceGate){
			return FenceConnectionRules::connectsToGate($block, $facing);
		}
		return FenceConnectionRules::connectsToFace($block, $facing);
	}

	/**
	 * Recomputes the sides and the post from the neighbours (Java WallBlock.updateShape, with the "covered" tests
	 * fitted to BDS 1.26.52): a connected side is tall when the block above covers it, and the post is raised for
	 * isolated walls, ends, corners and T-junctions, under a wall with a post, and under anything standing on the
	 * wall unless both sides of a straight run are tall.
	 */
	protected function recalculateConnections() : bool{
		$changed = false;

		$above = $this->getSide(Facing::UP);
		$tallSides = 0;
		$standsOnTop = self::coverFromAbove($above, $tallSides);

		foreach(Facing::HORIZONTAL as $facing){
			$type = null;
			if($this->connectsTo($this->getSide($facing), $facing)){
				$type = ($tallSides & (1 << $facing)) !== 0 ? WallConnectionType::TALL : WallConnectionType::SHORT;
			}
			if(($this->connections[$facing] ?? null) !== $type){
				$changed = true;
				if($type !== null){
					$this->connections[$facing] = $type;
				}else{
					unset($this->connections[$facing]);
				}
			}
		}

		$post = $this->shouldRaisePost($above, $standsOnTop);
		if($post !== $this->post){
			$this->post = $post;
			$changed = true;
		}

		if($changed){
			$this->collisionBoxes = null;
		}
		return $changed;
	}

	/**
	 * Java WallBlock.shouldRaisePost.
	 */
	private function shouldRaisePost(Block $above, bool $standsOnTop) : bool{
		if($above instanceof Wall && $above->post){
			return true;
		}
		$north = $this->connections[Facing::NORTH] ?? null;
		$south = $this->connections[Facing::SOUTH] ?? null;
		$west = $this->connections[Facing::WEST] ?? null;
		$east = $this->connections[Facing::EAST] ?? null;
		if(($north === null) !== ($south === null) || ($west === null) !== ($east === null) || ($north === null && $west === null)){
			//isolated wall, end, corner or T-junction
			return true;
		}
		if(($north === WallConnectionType::TALL && $south === WallConnectionType::TALL) || ($west === WallConnectionType::TALL && $east === WallConnectionType::TALL)){
			return false;
		}
		return $standsOnTop;
	}

	/**
	 * Works out how the block above covers the top of this wall: sets a bit (1 << facing) in $tallSides for each side it
	 * covers, and returns whether it stands on the wall at all (which raises the post of a straight run).
	 *
	 * Fitted to BDS 1.26.52 rather than Java's face shape tests: a side is covered when anything standing on the wall
	 * reaches the outer quarter of that side's arm (slabs, carpets, pressure plates, cakes, dragon eggs, a wall or a
	 * fence or pane running along the wall, a fence gate in line with it...), while the small centred blocks (torches,
	 * signs, banners, lanterns, candles, heads, flower pots, an unconnected fence or pane...) only raise the post.
	 * Blocks hanging from above (top slabs, upside-down trapdoors, hanging lanterns...) don't touch the wall. Blocks
	 * without a collision box (liquids, cobwebs, pressure plates...) cover the whole top.
	 */
	private static function coverFromAbove(Block $above, int &$tallSides) : bool{
		$tallSides = 0;
		if($above->getTypeId() === BlockTypeIds::AIR){
			return false;
		}
		if(
			$above instanceof Torch ||
			$above instanceof FloorSign ||
			$above instanceof FloorBanner ||
			$above instanceof FlowerPot ||
			$above instanceof Conduit
		){
			return true;
		}
		if(
			$above instanceof HangingRoots ||
			$above instanceof SporeBlossom ||
			($above instanceof PointedDripstone && $above->isHanging())
		){
			return false;
		}

		$boxes = $above->getCollisionBoxes();
		if(count($boxes) === 0){
			$tallSides = (1 << Facing::NORTH) | (1 << Facing::SOUTH) | (1 << Facing::WEST) | (1 << Facing::EAST);
			return true;
		}

		$pos = $above->getPosition();
		$baseX = $pos->getFloorX();
		$baseY = $pos->getFloorY();
		$baseZ = $pos->getFloorZ();
		$standsOnTop = false;
		foreach($boxes as $bb){
			if($bb->minY - $baseY > self::EPSILON){
				continue; //doesn't reach down to the wall
			}
			$standsOnTop = true;
			$minX = $bb->minX - $baseX;
			$maxX = $bb->maxX - $baseX;
			$minZ = $bb->minZ - $baseZ;
			$maxZ = $bb->maxZ - $baseZ;
			if($minZ < self::ARM_MAX && $maxZ > self::ARM_MIN){
				if($minX < self::OUTER_MIN - self::EPSILON){
					$tallSides |= 1 << Facing::WEST;
				}
				if($maxX > self::OUTER_MAX + self::EPSILON){
					$tallSides |= 1 << Facing::EAST;
				}
			}
			if($minX < self::ARM_MAX && $maxX > self::ARM_MIN){
				if($minZ < self::OUTER_MIN - self::EPSILON){
					$tallSides |= 1 << Facing::NORTH;
				}
				if($maxZ > self::OUTER_MAX + self::EPSILON){
					$tallSides |= 1 << Facing::SOUTH;
				}
			}
		}
		return $standsOnTop;
	}

	protected function recalculateCollisionBoxes() : array{
		//walls don't have any special collision boxes like fences do

		$north = isset($this->connections[Facing::NORTH]);
		$south = isset($this->connections[Facing::SOUTH]);
		$west = isset($this->connections[Facing::WEST]);
		$east = isset($this->connections[Facing::EAST]);

		$inset = 0.25;
		if(
			!$this->post && //if there is a block on top, it stays as a post
			(
				($north && $south && !$west && !$east) ||
				(!$north && !$south && $west && $east)
			)
		){
			//If connected to two sides on the same axis but not any others, AND there is not a block on top, there is no post and the wall is thinner
			$inset = 0.3125;
		}

		return [
			AxisAlignedBB::one()
				->extend(Facing::UP, 0.5)
				->trim(Facing::NORTH, $north ? 0 : $inset)
				->trim(Facing::SOUTH, $south ? 0 : $inset)
				->trim(Facing::WEST, $west ? 0 : $inset)
				->trim(Facing::EAST, $east ? 0 : $inset)
		];
	}

	public function getSupportType(int $facing) : SupportType{
		return Facing::axis($facing) === Axis::Y ? SupportType::CENTER : SupportType::NONE;
	}
}
