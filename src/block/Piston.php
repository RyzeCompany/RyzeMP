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

use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\tile\MovingBlock as TileMovingBlock;
use pocketmine\block\tile\PistonArm as TilePistonArm;
use pocketmine\block\tile\Spawnable;
use pocketmine\block\tile\Tile;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\AnyFacingTrait;
use pocketmine\block\utils\PistonPushReaction;
use pocketmine\block\utils\PistonStructureResolver;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\format\Chunk;
use pocketmine\world\redstone\RedstoneEngine;
use pocketmine\world\sound\PistonExtendSound;
use pocketmine\world\sound\PistonRetractSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function abs;
use function array_reverse;
use function count;

/**
 * Piston and sticky piston (Bedrock behaviour).
 *
 * - Powered from any side except its front (no quasi-connectivity in Bedrock); redstone wire next to it powers it even
 *   when it doesn't point into it.
 * - Starts moving one redstone tick (2 game ticks) after its power changes, and the arm takes 2 game ticks to move.
 *   Power changes during a movement are only looked at once it ends.
 * - Pushes up to 12 blocks (slime and honey blocks drag the blocks stuck to them), destroys blocks which break when
 *   pushed, and a sticky piston pulls the block in front of its head back when it retracts.
 * - Moved blocks keep their block entity data (chests, furnaces...).
 *
 * The moving blocks are moving_block blocks with a MovingBlock block entity at their destination, and the PistonArm
 * block entity of the piston carries the animation state; see {@link TilePistonArm} and {@link TileMovingBlock}.
 */
class Piston extends Opaque implements AnyFacing{
	use AnyFacingTrait;

	/** Delay between a power change and the start of the movement. */
	public const START_DELAY_TICKS = RedstoneEngine::REDSTONE_TICK;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
	}

	public function isSticky() : bool{
		return $this->getTypeId() === BlockTypeIds::STICKY_PISTON;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		//pistons face the player who places them
		if($player !== null){
			if(abs($player->getPosition()->x - $this->position->x) < 2 && abs($player->getPosition()->z - $this->position->z) < 2){
				$y = $player->getEyePos()->y;
				if($y - $this->position->y > 2){
					$this->facing = Facing::UP;
				}elseif($this->position->y - $y > 0){
					$this->facing = Facing::DOWN;
				}else{
					$this->facing = Facing::opposite($player->getHorizontalFacing());
				}
			}else{
				$this->facing = Facing::opposite($player->getHorizontalFacing());
			}
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TilePistonArm && $tile->isSticky() !== $this->isSticky()){
			$tile->setSticky($this->isSticky());
		}
	}

	public function isRedstoneConductor() : bool{
		return false;
	}

	protected function getArm() : ?TilePistonArm{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TilePistonArm ? $tile : null;
	}

	/**
	 * Returns whether the piston's arm is out (or moving).
	 */
	public function isExtended() : bool{
		$arm = $this->getArm();
		if($arm !== null){
			return $arm->getState() !== TilePistonArm::STATE_RETRACTED;
		}
		$head = $this->getSide($this->facing);
		return $head instanceof PistonHead && $head->getFacing() === $this->facing;
	}

	public function getPistonPushReaction() : PistonPushReaction{
		return $this->isExtended() ? PistonPushReaction::BLOCK : PistonPushReaction::NORMAL;
	}

	public function getAffectedBlocks() : array{
		$head = $this->getSide($this->facing);
		if($head instanceof PistonHead && $head->getFacing() === $this->facing && $head->isSticky() === $this->isSticky()){
			return [$this, $head];
		}
		return parent::getAffectedBlocks();
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$arm = $this->getArm();
		if($arm !== null && $arm->isMoving()){
			$this->finishMovement($arm);
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	/**
	 * Returns whether the piston receives power from any side except its front.
	 */
	public function isReceivingPower() : bool{
		$world = $this->position->getWorld();
		$redstone = $world->getRedstone();
		if($redstone === null){
			return false;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		foreach(Facing::ALL as $face){
			if($face === $this->facing){
				continue;
			}
			if($redstone->getPowerFrom($x, $y, $z, $face) > 0){
				return true;
			}
			if(Facing::axis($face) !== Axis::Y){
				//Bedrock redirects wire towards pistons: any powered wire next to the piston powers it
				[$dx, , $dz] = Facing::OFFSET[$face];
				$side = $world->getBlockAt($x + $dx, $y, $z + $dz);
				if($side instanceof RedstoneWire && $side->getOutputSignalStrength() > 0){
					return true;
				}
			}
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if($world->getRedstone() === null){
			return;
		}
		$arm = $this->getArm();
		if($arm === null || $arm->isMoving() || $world->isBlockUpdateScheduled($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ())){
			return;
		}
		$this->checkPower($arm);
	}

	/**
	 * Remembers the power change (if any) and schedules the movement after the start delay.
	 */
	private function checkPower(TilePistonArm $arm) : void{
		$powered = $this->isReceivingPower();
		if($powered !== ($arm->getState() === TilePistonArm::STATE_EXTENDED)){
			$arm->setPendingPower($powered);
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::START_DELAY_TICKS);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$arm = $this->getArm();
		if($arm === null){
			return;
		}
		if($arm->isMoving()){
			$tick = $world->getServer()->getTick();
			if($arm->getLastMoveTick() !== $tick){ //never move twice in one tick, even if updates were scheduled twice
				$arm->setLastMoveTick($tick);
				$this->stepMovement($arm);
			}
			return;
		}
		if($world->getRedstone() === null){
			return;
		}

		$pending = $arm->getPendingPower();
		$arm->setPendingPower(null);
		if($pending === null){
			$this->checkPower($arm);
			return;
		}
		$state = $arm->getState();
		if($pending && $state === TilePistonArm::STATE_RETRACTED){
			if(!$this->startMovement($arm, true)){
				//blocked: try again later while powered
				$world->scheduleDelayedBlockUpdate($this->position, self::START_DELAY_TICKS);
			}
		}elseif(!$pending && $state === TilePistonArm::STATE_EXTENDED){
			$this->startMovement($arm, false);
		}
	}

	/**
	 * Starts extending or retracting the arm. Returns false if the blocks in front can't be pushed.
	 */
	public function startMovement(TilePistonArm $arm, bool $extending) : bool{
		$world = $this->position->getWorld();
		$pistonPos = $this->position->asVector3();
		$headPos = $pistonPos->getSide($this->facing);
		if(!$world->isInWorld($headPos->getFloorX(), $headPos->getFloorY(), $headPos->getFloorZ())){
			return false;
		}

		$toPush = [];
		$toDestroy = [];
		if($extending || $this->isSticky()){
			$resolver = new PistonStructureResolver($world, $pistonPos, $this->facing, $extending);
			if($resolver->resolve()){
				$toPush = $resolver->getBlocksToPush();
				$toDestroy = $resolver->getBlocksToDestroy();
			}elseif($extending){
				return false;
			}
		}
		$direction = $extending ? $this->facing : Facing::opposite($this->facing);

		//destroy the blocks which break when pushed, dropping their items
		foreach(array_reverse($toDestroy) as $pos){
			$block = $world->getBlock($pos);
			if($block->canBeReplaced() || $block instanceof Liquid){
				$world->setBlock($pos, VanillaBlocks::AIR());
			}else{
				$world->useBreakOn($pos, createParticles: true);
			}
		}

		//take the moved blocks and their block entities out of the world
		$moved = [];
		foreach($toPush as $pos){
			$block = $world->getBlock($pos);
			$tile = $world->getTile($pos);
			$tileData = null;
			$tileSpawnData = null;
			if($tile !== null){
				if($tile instanceof TileChest){
					$tile->unpair();
				}
				$tileData = $tile->saveNBT();
				if($tile instanceof Spawnable){
					$tileSpawnData = $tile->getSpawnCompound();
				}
			}
			$moved[] = [$pos, $block, $tileData, $tileSpawnData];
		}
		$air = VanillaBlocks::AIR();
		foreach($toPush as $pos){
			$world->setBlock($pos, $air);
		}

		//moving blocks at the destinations (a block pulled by a sticky piston replaces the head, which otherwise stays
		//until the arm is fully retracted)
		$movingBlock = VanillaBlocks::MOVING_BLOCK();
		foreach($moved as [$pos, $block, $tileData, $tileSpawnData]){
			$destination = $pos->getSide($direction);
			$world->setBlock($destination, $movingBlock);
			$movingTile = $world->getTile($destination);
			if($movingTile instanceof TileMovingBlock){
				foreach([$tileData, $tileSpawnData] as $nbt){
					$nbt?->setInt(Tile::TAG_X, $destination->getFloorX())
						->setInt(Tile::TAG_Y, $destination->getFloorY())
						->setInt(Tile::TAG_Z, $destination->getFloorZ());
				}
				$movingTile->setMovement($block, $pistonPos, $extending, $tileData, $tileSpawnData);
			}
		}

		if($extending){
			$world->setBlock($headPos, ($this->isSticky() ? VanillaBlocks::STICKY_PISTON_HEAD() : VanillaBlocks::PISTON_HEAD())->setFacing($this->facing));
		}

		$arm->startMove($extending, $toPush, $toDestroy);
		$arm->setLastMoveTick($world->getServer()->getTick());
		$arm->broadcastState();
		$world->addSound($pistonPos->add(0.5, 0.5, 0.5), $extending ? new PistonExtendSound() : new PistonRetractSound());
		$world->addGameEvent($pistonPos, $extending ? GameEvent::BLOCK_ACTIVATE : GameEvent::BLOCK_DEACTIVATE); //Java PistonBaseBlock.triggerEvent()
		$world->scheduleDelayedBlockUpdate($this->position, 1);
		return true;
	}

	private function stepMovement(TilePistonArm $arm) : void{
		$previous = $arm->getProgress();
		$done = $arm->step();
		$this->moveEntities($arm, abs($arm->getProgress() - $previous));
		if($done){
			$this->finishMovement($arm);
		}else{
			$arm->broadcastState();
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
		}
	}

	/**
	 * Puts the moved blocks in place and ends the movement.
	 */
	public function finishMovement(TilePistonArm $arm) : void{
		$world = $this->position->getWorld();
		$extending = $arm->isExtending();
		$direction = $extending ? $this->facing : Facing::opposite($this->facing);
		foreach($arm->getAttachedBlocks() as $pos){
			$destination = $pos->getSide($direction);
			if(!$world->isInWorld($destination->getFloorX(), $destination->getFloorY(), $destination->getFloorZ()) || !$world->isChunkLoaded($destination->getFloorX() >> Chunk::COORD_BIT_SIZE, $destination->getFloorZ() >> Chunk::COORD_BIT_SIZE)){
				continue;
			}
			$tile = $world->getTile($destination);
			if($tile instanceof TileMovingBlock){
				self::placeMovedBlock($world, $destination, $tile);
			}
		}
		if(!$extending){
			$headPos = $this->position->getSide($this->facing);
			if($world->getBlock($headPos) instanceof PistonHead){
				$world->setBlock($headPos, VanillaBlocks::AIR());
			}
		}
		$arm->finishMove($extending);
		$arm->broadcastState();
		//look at the power again, it may have changed during the movement
		$world->scheduleDelayedBlockUpdate($this->position, 1);
	}

	/**
	 * Replaces a moving block by the block it carries, with its block entity data.
	 *
	 * @internal
	 */
	public static function placeMovedBlock(World $world, Vector3 $pos, TileMovingBlock $tile) : void{
		$block = $tile->getMovingBlock() ?? VanillaBlocks::AIR();
		$tileData = $tile->getMovingEntity();
		$world->setBlock($pos, $block);
		if($tileData !== null){
			$newTile = $world->getTile($pos);
			if($newTile !== null){
				$tileData->setInt(Tile::TAG_X, $pos->getFloorX())
					->setInt(Tile::TAG_Y, $pos->getFloorY())
					->setInt(Tile::TAG_Z, $pos->getFloorZ());
				$newTile->readSaveData($tileData);
				if($newTile instanceof Spawnable){
					$newTile->clearSpawnCompoundCache();
				}
			}
		}
	}

	/**
	 * Moves the entities touching the moving blocks (or the extending head) along with them. Players are left to
	 * their client, which moves itself out of the way of moving blocks.
	 */
	private function moveEntities(TilePistonArm $arm, float $delta) : void{
		$extending = $arm->isExtending();
		$direction = $extending ? $this->facing : Facing::opposite($this->facing);
		if($delta <= 0 || $direction === Facing::DOWN){
			return; //gravity takes care of entities on blocks moving down
		}
		//fraction of the movement done so far, 0 to 1
		$moved = $extending ? $arm->getProgress() : 1 - $arm->getProgress();
		$back = Facing::opposite($direction);

		$boxes = [];
		$destinations = [];
		foreach($arm->getAttachedBlocks() as $pos){
			$destinations[] = $pos->getSide($direction);
		}
		if($extending){
			$destinations[] = $this->position->getSide($this->facing);
		}
		foreach($destinations as $destination){
			$box = AxisAlignedBB::one()
				->offset($destination->x, $destination->y, $destination->z)
				->offsetTowards($back, 1 - $moved);
			if($direction !== Facing::UP){
				$box->extend(Facing::UP, 0.25); //entities standing on the block
			}
			$boxes[] = $box;
		}
		if(count($boxes) === 0){
			return;
		}

		$world = $this->position->getWorld();
		$motion = Vector3::zero()->getSide($direction)->multiply($delta);
		/** @var Entity[] $entities */
		$entities = [];
		foreach($boxes as $entityBox){
			foreach($world->getNearbyEntities($entityBox) as $entity){
				$entities[$entity->getId()] = $entity;
			}
		}
		foreach($entities as $entity){
			if($entity instanceof Player || $entity->isClosed() || $entity->isFlaggedForDespawn()){
				continue;
			}
			$entity->teleport($entity->getPosition()->addVector($motion));
		}
	}
}
