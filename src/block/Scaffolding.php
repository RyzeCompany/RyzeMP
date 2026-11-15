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

use pocketmine\block\utils\Fallable;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\FallingBlock;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\Sound;
use pocketmine\world\World;
use function floor;
use function min;

/**
 * Scaffolding, with the Bedrock rules.
 *
 * Its stability is the horizontal distance to a scaffolding column standing on a block with a full top face: 0 on
 * such a block, the same as the scaffolding below it, otherwise the lowest horizontal neighbour + 1. 7 means that it
 * is unsupported:
 * - scaffolding that was never supported (placed more than 6 blocks out, or in mid-air) falls like sand, and lands as
 *   scaffolding where it can stand (otherwise it drops as an item);
 * - scaffolding that loses its support breaks and drops itself, so removing the bottom of a column, or the column
 *   holding an overhang, takes down everything that depended on it, one block per tick.
 *
 * Placement (Bedrock): scaffolding goes against the clicked face like any other block (the client picks the face from
 * where the player looks, also from inside scaffolding), except that placing it into scaffolding, or against the
 * bottom face of scaffolding, puts it on top of that scaffolding column. Entities don't stop it from being placed.
 * Climbing, walking through it and sneaking down are handled by the client; only the top planks collide.
 */
class Scaffolding extends Transparent implements Fallable{
	public const MAX_STABILITY = 7;

	private int $stability = 0;
	private bool $stabilityCheck = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_STABILITY, $this->stability);
		$w->bool($this->stabilityCheck);
	}

	public function getStability() : int{ return $this->stability; }

	/** @return $this */
	public function setStability(int $stability) : self{
		if($stability < 0 || $stability > self::MAX_STABILITY){
			throw new \InvalidArgumentException("Stability must be in range 0-" . self::MAX_STABILITY);
		}
		$this->stability = $stability;
		return $this;
	}

	/**
	 * Vanilla "stability_check" state: set on scaffolding placed by a player until its stability has been checked,
	 * one tick later. Whether unsupported scaffolding falls or breaks depends on its stored stability, not on this.
	 */
	public function isStabilityCheck() : bool{ return $this->stabilityCheck; }

	/** @return $this */
	public function setStabilityCheck(bool $stabilityCheck) : self{
		$this->stabilityCheck = $stabilityCheck;
		return $this;
	}

	public function canClimb() : bool{
		return true;
	}

	public function getFlameEncouragement() : int{
		return 60;
	}

	public function getFlammability() : int{
		return 20;
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === Facing::UP ? SupportType::CENTER : SupportType::NONE;
	}

	protected function recalculateCollisionBoxes() : array{
		//the planks on top; entities inside it can move through it freely (players climb it client-side)
		return [AxisAlignedBB::one()->trim(Facing::DOWN, 14 / 16)];
	}

	public function isPlacementObstructedByEntities() : bool{
		//entities stand inside scaffolding (vanilla only collides with entities above it)
		return false;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		//climbing or sneaking down through scaffolding resets the fall distance, like ladders; landing on top doesn't
		if($entity instanceof Living && $entity->getPosition()->floor()->equals($this->position)){
			$entity->resetFallDistance();
		}
		return true;
	}

	/**
	 * Returns the stability scaffolding would have at the given position.
	 */
	public static function calculateStability(World $world, Vector3 $pos) : int{
		$below = $world->getBlock($pos->getSide(Facing::DOWN));
		$stability = self::MAX_STABILITY;
		if($below instanceof Scaffolding){
			$stability = $below->getStability();
		}elseif($below->getSupportType(Facing::UP) === SupportType::FULL){
			return 0;
		}
		foreach(Facing::HORIZONTAL as $facing){
			$side = $world->getBlock($pos->getSide($facing));
			if($side instanceof Scaffolding){
				$stability = min($stability, $side->getStability() + 1);
				if($stability === 1){
					break;
				}
			}
		}
		return $stability;
	}

	/**
	 * Returns the first position above the scaffolding column at the given position that isn't scaffolding, or null
	 * if the column reaches the top of the world.
	 */
	private static function findColumnTop(World $world, Vector3 $pos) : ?Vector3{
		$x = $pos->getFloorX();
		$z = $pos->getFloorZ();
		$maxY = $world->getMaxY();
		for($y = $pos->getFloorY() + 1; $y < $maxY; ++$y){
			if(!$world->getBlockAt($x, $y, $z) instanceof Scaffolding){
				return new Vector3($x, $y, $z);
			}
		}
		return null;
	}

	private static function canPlaceInto(Block $block) : bool{
		//Bedrock: scaffolding can't be placed in lava
		return $block->canBeReplaced() && !$block instanceof Lava;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		if(!$isClickedBlock && (
			$blockReplace instanceof Scaffolding ||
			($face === Facing::DOWN && $blockReplace->getSide(Facing::UP) instanceof Scaffolding)
		)){
			//goes on top of the scaffolding column (see place())
			return true;
		}
		return self::canPlaceInto($blockReplace);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$world = $blockReplace->getPosition()->getWorld();
		$target = $blockReplace->getPosition();
		if($face === Facing::DOWN && $blockClicked instanceof Scaffolding && !$blockClicked->getPosition()->equals($target)){
			$target = self::findColumnTop($world, $blockClicked->getPosition());
		}elseif($blockReplace instanceof Scaffolding){
			$target = self::findColumnTop($world, $target);
		}
		if($target === null || !self::canPlaceInto($world->getBlock($target))){
			return false;
		}
		$stability = self::calculateStability($world, $target);
		if($stability >= self::MAX_STABILITY && !$world->getBlock($target->getSide(Facing::DOWN))->canBeReplaced()){
			//it would be neither supported nor able to fall
			return false;
		}
		//with stability 7 (out of reach or in mid-air) it is placed and falls one tick later
		$this->stability = $stability;
		$this->stabilityCheck = true;
		$tx->addBlock($target, $this);
		return true;
	}

	public function onNearbyBlockChange() : void{
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$stability = self::calculateStability($world, $this->position);
		if($stability >= self::MAX_STABILITY){
			if($this->stability >= self::MAX_STABILITY && $world->getBlock($this->position->getSide(Facing::DOWN))->canBeReplaced()){
				//never supported: falls like sand
				$world->setBlock($this->position, VanillaBlocks::AIR());
				$fall = new FallingBlock(Location::fromObject($this->position->add(0.5, 0, 0.5), $world), (clone $this)->setStabilityCheck(false));
				$fall->spawnToAll();
			}else{
				//lost its support: breaks and drops itself
				$item = null;
				$world->useBreakOn($this->position, $item, null, true);
			}
		}elseif($stability !== $this->stability || $this->stabilityCheck){
			$this->stability = $stability;
			$this->stabilityCheck = false;
			$world->setBlock($this->position, $this);
		}
	}

	public function tickFalling() : ?Block{
		$block = $this->position->getWorld()->getBlock($this->position);
		if($block instanceof Lava || $block instanceof BaseFire){
			//Bedrock: falling into lava or fire destroys it (handing back the block that is there leaves it unchanged)
			return $block;
		}
		return null;
	}

	public function onHitGround(FallingBlock $blockEntity) : bool{
		//the cell FallingBlock lands in
		$bb = $blockEntity->getBoundingBox();
		$landing = new Vector3(floor($bb->minX), floor($bb->maxY), floor($bb->minZ));
		$world = $blockEntity->getWorld();
		if(self::calculateStability($world, $landing) >= self::MAX_STABILITY){
			//it can't stand where it landed
			$world->dropItem($blockEntity->getPosition(), $this->asItem());
			return false;
		}
		return true;
	}

	public function getFallDamagePerBlock() : float{
		return 0.0;
	}

	public function getMaxFallDamage() : float{
		return 0.0;
	}

	public function getLandSound() : ?Sound{
		return null;
	}

	public function getFuelTime() : int{
		return 50;
	}
}
