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

use pocketmine\block\tile\Conduit as TileConduit;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\block\utils\WaterloggedHelper;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Monster;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\sound\ConduitActivateSound;
use pocketmine\world\sound\ConduitAmbientShortSound;
use pocketmine\world\sound\ConduitAmbientSound;
use pocketmine\world\sound\ConduitAttackSound;
use pocketmine\world\sound\ConduitDeactivateSound;
use pocketmine\world\World;
use function abs;
use function count;
use function floor;
use function intdiv;
use function mt_rand;

/**
 * Conduit (https://minecraft.wiki/w/Conduit), ported from Java's ConduitBlockEntity.
 *
 * Every 40 ticks (scheduled block update, nothing runs on the other ticks) the conduit checks that the 3x3x3 cube
 * around it (itself included, so it must be waterlogged) is water, and counts the prismarine, prismarine bricks, dark
 * prismarine and sea lanterns in the three 5x5 rings around it (42 positions). With 16 or more it is active:
 * - players in water or rain within 16 blocks per 7 frame blocks (at most 96) get Conduit Power for 13 seconds;
 * - with the full frame (42 blocks) it attacks one hostile mob in water or rain within 8 blocks, 4 damage every
 *   2 seconds.
 * The client animates the eye and the particles from the block entity ("Active", "Target").
 *
 * Like Bedrock, the water of a waterlogged conduit lives in the liquid layer of the subchunk (the conduit has no
 * waterlogged block state), so the waterlogged flag is read from there.
 */
class Conduit extends Transparent implements Waterlogged{
	public const UPDATE_INTERVAL_TICKS = 40;
	public const MIN_ACTIVE_FRAME_BLOCKS = 16;
	public const MIN_ATTACK_FRAME_BLOCKS = 42;
	public const ATTACK_RANGE = 8;
	public const ATTACK_DAMAGE = 4.0;
	public const EFFECT_DURATION_TICKS = 260;
	public const AMBIENT_SOUND_INTERVAL_TICKS = 80;

	protected bool $waterlogged = false;

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$this->waterlogged = self::hasWaterInLiquidLayer($this->position->getWorld(), $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		return $this;
	}

	public function isWaterlogged() : bool{ return $this->waterlogged; }

	/** @return $this */
	public function setWaterlogged(bool $waterlogged) : self{
		$this->waterlogged = $waterlogged;
		return $this;
	}

	public function getLightLevel() : int{
		return 15;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(5 / 16, 5 / 16, 5 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->waterlogged = WaterloggedHelper::isWaterSource($blockReplace);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if($this->waterlogged){
			WaterloggedHelper::scheduleFlow($this);
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->waterlogged){
			WaterloggedHelper::leaveWater($this);
			return true;
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function onScheduledUpdate() : void{
		if($this->waterlogged){
			WaterloggedHelper::flow($this);
		}
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileConduit){
			return;
		}
		//several update chains may be started (tile placed or loaded, water flow...): keep a single one every 40 ticks
		$now = $world->getServer()->getTick();
		$last = $tile->getLastUpdateTick();
		if($last >= 0 && $now - $last < self::UPDATE_INTERVAL_TICKS){
			$world->scheduleDelayedBlockUpdate($this->position, self::UPDATE_INTERVAL_TICKS - ($now - $last));
			return;
		}
		$firstUpdate = $last < 0;
		$tile->setLastUpdateTick($now);
		$world->scheduleDelayedBlockUpdate($this->position, self::UPDATE_INTERVAL_TICKS);

		$this->updateConduit($tile, $firstUpdate);
	}

	/**
	 * Recomputes the frame, plays the sounds, gives Conduit Power and attacks hostile mobs. Called every 40 ticks.
	 *
	 * @param bool $firstUpdate first update since the tile was created or loaded: a conduit loaded from disk which was
	 *                          already active doesn't play its activation sound again
	 */
	public function updateConduit(TileConduit $tile, bool $firstUpdate = false) : void{
		$world = $this->position->getWorld();
		$frameBlocks = self::countFrameBlocks($world, $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
		$active = $frameBlocks >= self::MIN_ACTIVE_FRAME_BLOCKS;
		$wasActive = $tile->isActive();
		$tile->setFrameBlocks($frameBlocks);
		$center = $this->position->add(0.5, 0.5, 0.5);
		$changed = false;
		if($active !== $wasActive){
			$tile->setActive($active);
			$changed = true;
			$world->addSound($center, $active ? new ConduitActivateSound() : new ConduitDeactivateSound());
		}elseif($firstUpdate && $active){
			$changed = true; //make sure the viewers have the loaded state
		}

		$targetId = -1;
		if($active){
			$this->applyEffects($frameBlocks);
			if($frameBlocks >= self::MIN_ATTACK_FRAME_BLOCKS){
				$target = $this->updateTarget($tile->getTargetId());
				if($target !== null){
					$targetId = $target->getId();
					$world->addSound($target->getPosition(), new ConduitAttackSound());
					$target->attack(new EntityDamageEvent($target, EntityDamageEvent::CAUSE_MAGIC, self::ATTACK_DAMAGE));
				}
			}

			$now = $world->getServer()->getTick();
			//vanilla: every 80 game ticks, and a short one every 3-5 seconds
			if(intdiv($now, self::AMBIENT_SOUND_INTERVAL_TICKS) !== intdiv($now - self::UPDATE_INTERVAL_TICKS, self::AMBIENT_SOUND_INTERVAL_TICKS)){
				$world->addSound($center, new ConduitAmbientSound());
			}
			if($now > $tile->getNextAmbientShortTick()){
				$tile->setNextAmbientShortTick($now + 60 + mt_rand(0, 39));
				$world->addSound($center, new ConduitAmbientShortSound());
			}
		}
		if($targetId !== $tile->getTargetId()){
			$tile->setTargetId($targetId);
			$changed = true;
		}
		if($changed){
			$tile->broadcastState();
		}
	}

	/**
	 * Returns the radius in blocks within which players get Conduit Power: 16 per 7 frame blocks (16-96).
	 */
	public static function getEffectRange(int $frameBlocks) : int{
		return intdiv($frameBlocks, 7) * 16;
	}

	/**
	 * Returns whether a position is within the given range of the conduit (vanilla BlockPos.closerThan(): distance
	 * between the block positions, strictly less than the range).
	 */
	private function isInRange(Vector3 $pos, int $range) : bool{
		$dx = (int) floor($pos->x) - $this->position->getFloorX();
		$dy = (int) floor($pos->y) - $this->position->getFloorY();
		$dz = (int) floor($pos->z) - $this->position->getFloorZ();
		return $dx * $dx + $dy * $dy + $dz * $dz < $range * $range;
	}

	private function applyEffects(int $frameBlocks) : void{
		$range = self::getEffectRange($frameBlocks);
		foreach($this->position->getWorld()->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator() && $this->isInRange($player->getPosition(), $range) && self::isInWaterOrRain($player)){
				$player->getEffects()->add(new EffectInstance(VanillaEffects::CONDUIT_POWER(), self::EFFECT_DURATION_TICKS, 0, true, true));
			}
		}
	}

	/**
	 * Keeps the current target if it is still alive and in range, otherwise picks a random hostile mob in water or
	 * rain within 8 blocks. Like vanilla, a lost target is only replaced on the next update.
	 */
	private function updateTarget(int $currentTargetId) : ?Living{
		$world = $this->position->getWorld();
		if($currentTargetId !== -1){
			$current = $world->getEntity($currentTargetId);
			if($current instanceof Living && $current->isAlive() && !$current->isClosed() && $this->isInRange($current->getPosition(), self::ATTACK_RANGE)){
				return $current;
			}
			return null;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$range = self::ATTACK_RANGE;
		$box = new AxisAlignedBB($x - $range, $y - $range, $z - $range, $x + 1 + $range, $y + 1 + $range, $z + 1 + $range);
		$candidates = [];
		foreach($world->getNearbyEntities($box) as $entity){
			if(self::isHostile($entity) && $entity instanceof Living && $entity->isAlive() && self::isInWaterOrRain($entity)){
				$candidates[] = $entity;
			}
		}
		return count($candidates) > 0 ? $candidates[mt_rand(0, count($candidates) - 1)] : null;
	}

	private static function isHostile(Entity $entity) : bool{
		return $entity instanceof Monster;
	}

	/**
	 * Returns whether the entity is in water (feet or eyes) or in the rain.
	 */
	public static function isInWaterOrRain(Living $entity) : bool{
		if($entity instanceof Mob){
			return $entity->isInContactWithWater();
		}
		$pos = $entity->getPosition();
		return self::isWaterAt($entity->getWorld(), (int) floor($pos->x), (int) floor($pos->y + 0.1), (int) floor($pos->z)) || $entity->isUnderwater() || $entity->isInRain();
	}

	/**
	 * Returns whether the block holds water: water (source or flowing), a waterlogged block or water in the liquid
	 * layer (blocks of worlds made by vanilla).
	 */
	public static function isWaterAt(World $world, int $x, int $y, int $z) : bool{
		return LiquidLayer::hasWaterAt($world, $x, $y, $z);
	}

	private static function hasWaterInLiquidLayer(World $world, int $x, int $y, int $z) : bool{
		if(!$world->isInWorld($x, $y, $z)){
			return false;
		}
		$chunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null){
			return false;
		}
		$stateId = $chunk->getSubChunk($y >> SubChunk::COORD_BIT_SIZE)->getLiquidLayerStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK);
		return $stateId !== Block::EMPTY_STATE_ID && RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId) instanceof Water;
	}

	/**
	 * Blocks a conduit frame can be built with.
	 */
	public static function isFrameBlock(Block $block) : bool{
		return match($block->getTypeId()){
			BlockTypeIds::PRISMARINE,
			BlockTypeIds::PRISMARINE_BRICKS,
			BlockTypeIds::DARK_PRISMARINE,
			BlockTypeIds::SEA_LANTERN => true,
			default => false
		};
	}

	/**
	 * Returns the number of frame blocks (0-42) around a conduit at the given position, or 0 if the 3x3x3 cube around
	 * it (itself included) isn't all water.
	 */
	public static function countFrameBlocks(World $world, int $x, int $y, int $z) : int{
		for($ox = -1; $ox <= 1; ++$ox){
			for($oy = -1; $oy <= 1; ++$oy){
				for($oz = -1; $oz <= 1; ++$oz){
					if(!self::isWaterAt($world, $x + $ox, $y + $oy, $z + $oz)){
						return 0;
					}
				}
			}
		}
		$count = 0;
		for($ox = -2; $ox <= 2; ++$ox){
			$ax = abs($ox);
			for($oy = -2; $oy <= 2; ++$oy){
				$ay = abs($oy);
				for($oz = -2; $oz <= 2; ++$oz){
					$az = abs($oz);
					if($ax <= 1 && $ay <= 1 && $az <= 1){
						continue;
					}
					//the three rings: in a plane through the conduit, on the edge of the 5x5 square
					if(
						(($ox === 0 && ($ay === 2 || $az === 2)) || ($oy === 0 && ($ax === 2 || $az === 2)) || ($oz === 0 && ($ax === 2 || $ay === 2))) &&
						$world->isInWorld($x + $ox, $y + $oy, $z + $oz) &&
						self::isFrameBlock($world->getBlockAt($x + $ox, $y + $oy, $z + $oz))
					){
						++$count;
					}
				}
			}
		}
		return $count;
	}
}
