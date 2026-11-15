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

use pocketmine\block\utils\DripstoneThickness;
use pocketmine\block\utils\Fallable;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\block\utils\SupportType;
use pocketmine\block\utils\Waterlogged;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\object\FallingBlock;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\Trident;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\BlockTransaction;
use pocketmine\world\GameRules;
use pocketmine\world\particle\DripstoneDripParticle;
use pocketmine\world\sound\PointedDripstoneDripLavaIntoCauldronSound;
use pocketmine\world\sound\PointedDripstoneDripWaterIntoCauldronSound;
use pocketmine\world\sound\PointedDripstoneLandSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\vibration\GameEvent;
use function max;
use function min;

/**
 * Pointed dripstone (also used for the sulfur spike, which has the same states).
 *
 * A standing block (stalagmite) needs a full top face or a standing block of the same type below it; a hanging block
 * (stalactite) needs a full bottom face or a hanging block of the same type above it. The thickness is recalculated
 * from the neighbouring blocks like in vanilla, including merged tips.
 *
 * Stalactites of pointed dripstone drip the water or lava source above the block they hang from into a cauldron up to
 * 11 blocks below their tip (Java PointedDripstoneBlock.maybeTransferFluid()): on a random tick of the top block of
 * the stalactite, water drips with a 17.6% chance and lava with 5.9%; the drop lands 50 ticks + 1 tick per block of
 * fall later. Water adds a third of a cauldron (2 of the 6 Bedrock levels), lava fills it. Mud above the root block
 * dries into clay instead. The dripping particles are drawn by the client from the block states.
 *
 * Like vanilla (Java SpeleothemBlock and PointedDripstoneBlock):
 * - a stalactite grows on a random tick of its top block with a 0.0114 chance: pointed dripstone hanging from a
 *   dripstone block under a water source, sulfur spikes hanging from a sulfur block. Its tip grows down or, half of
 *   the time, a stalagmite grows (or starts) on the floor up to 10 blocks below it; tips that meet merge.
 * - a stalactite that loses its support falls 2 ticks later as falling blocks, from the block that lost it down to
 *   the tip. The tip hurts what it lands on (6 damage per block fallen, or the length of the falling part if longer,
 *   at most 40) and the falling blocks shatter into items when they land. Stalagmites that lose their support break.
 * - landing on the tip of a pointed dripstone stalagmite doubles the fall damage, counting 2.5 more blocks.
 * - a trident hitting one faster than 0.6 blocks/tick breaks it (projectilescanbreakblocks).
 *
 * It is {@link Fallable} only for the falling stalactites: unlike sand and gravel it doesn't fall when the block
 * below it is removed, and as a {@link FallingBlock} it never lands as a block but shatters into its item (so a plugin
 * that makes any Fallable block fall gets the drop of a broken pointed dripstone).
 */
class PointedDripstone extends Transparent implements Fallable{
	public const MAX_DRIP_SEARCH_LENGTH = 11;
	public const WATER_TRANSFER_CHANCE = 0.17578125;
	public const LAVA_TRANSFER_CHANCE = 0.05859375;
	public const DRIP_BASE_DELAY_TICKS = 50;
	/** Bedrock cauldron levels added by one drop of water (a third of the cauldron, like one Java level) */
	public const WATER_DRIP_FILL_AMOUNT = 2;

	public const FLUID_NONE = 0;
	public const FLUID_WATER = 1;
	public const FLUID_LAVA = 2;

	/** Chance that a random tick of the top block of a stalactite makes it grow (5 days on average in Java). */
	private const GROWTH_CHANCE = 0.011377778;
	/** Search range for the tip of a growing stalactite (Java MAX_GROWTH_LENGTH, 2 for sulfur spikes). */
	public const MAX_GROWTH_LENGTH = 7;
	private const SULFUR_SPIKE_MAX_GROWTH_LENGTH = 2;
	/** Blocks searched below the tip of a stalactite for the floor a stalagmite grows on. */
	public const MAX_STALAGMITE_SEARCH_RANGE = 10;
	/** Ticks between a stalactite losing its support and falling. */
	private const FALL_DELAY_TICKS = 2;
	/**
	 * Damage per block fallen of a falling stalactite is the length of the falling part (BDS: (fall - 1) x length, at
	 * most 40; Java uses at least 6 per block).
	 */
	public const MIN_STALACTITE_DAMAGE_PER_BLOCK = 1;
	public const MAX_STALACTITE_DAMAGE = 40;
	/** Speed (blocks/tick) a trident must exceed to break pointed dripstone. */
	private const MIN_TRIDENT_SPEED_TO_BREAK = 0.6;
	/** Landing on a stalagmite tip: Java causeFallDamage(fallDistance + 2.5, 2.0). */
	private const STALAGMITE_FALL_DISTANCE_OFFSET = 2.5;
	private const STALAGMITE_FALL_DAMAGE_MULTIPLIER = 2.0;

	protected bool $hanging = false;
	protected DripstoneThickness $thickness = DripstoneThickness::TIP;

	/** Damage per block fallen when this block falls: only set on the falling tip of a stalactite. */
	private float $fallDamagePerBlock = 0.0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->hanging);
		$w->enum($this->thickness);
	}

	public function isHanging() : bool{ return $this->hanging; }

	/** @return $this */
	public function setHanging(bool $hanging) : self{
		$this->hanging = $hanging;
		return $this;
	}

	public function getThickness() : DripstoneThickness{ return $this->thickness; }

	/** @return $this */
	public function setThickness(DripstoneThickness $thickness) : self{
		$this->thickness = $thickness;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		//approximation of the vanilla shapes, which get narrower towards the tip
		$inset = match($this->thickness){
			DripstoneThickness::TIP, DripstoneThickness::MERGE => 5 / 16,
			DripstoneThickness::FRUSTUM => 4 / 16,
			DripstoneThickness::MIDDLE => 3 / 16,
			DripstoneThickness::BASE => 2 / 16,
		};
		return [AxisAlignedBB::one()->squash(Axis::X, $inset)->squash(Axis::Z, $inset)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	/**
	 * Direction the tip of this block points to.
	 */
	private function getTipDirection() : int{
		return $this->hanging ? Facing::DOWN : Facing::UP;
	}

	private function isSameTypeWithTip(Block $block, int $tipDirection) : bool{
		return $block instanceof PointedDripstone && $block->hasSameTypeId($this) && $block->getTipDirection() === $tipDirection;
	}

	private function canBeSupportedAt(Block $block, bool $hanging) : bool{
		$tipDirection = $hanging ? Facing::DOWN : Facing::UP;
		$baseDirection = Facing::opposite($tipDirection);
		$support = $block->getSide($baseDirection);
		return $this->isSameTypeWithTip($support, $tipDirection) || $support->getSupportType($tipDirection) === SupportType::FULL;
	}

	private function calculateThickness(Block $at, bool $tipMerge) : DripstoneThickness{
		$tipDirection = $this->getTipDirection();
		$inFront = $at->getSide($tipDirection);
		if(!$inFront instanceof PointedDripstone || !$inFront->hasSameTypeId($this)){
			return DripstoneThickness::TIP;
		}
		if($inFront->getTipDirection() !== $tipDirection){
			//tips facing each other
			return $tipMerge || $inFront->thickness === DripstoneThickness::MERGE ? DripstoneThickness::MERGE : DripstoneThickness::TIP;
		}
		if($inFront->thickness === DripstoneThickness::TIP || $inFront->thickness === DripstoneThickness::MERGE){
			return DripstoneThickness::FRUSTUM;
		}
		return $this->isSameTypeWithTip($at->getSide(Facing::opposite($tipDirection)), $tipDirection) ? DripstoneThickness::MIDDLE : DripstoneThickness::BASE;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return ($this->canBeSupportedAt($blockReplace, false) || $this->canBeSupportedAt($blockReplace, true)) &&
			parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$preferHanging = match($face){
			Facing::DOWN => true,
			Facing::UP => false,
			default => $player === null || $player->getLocation()->getPitch() < 0 //looking up: hang from the ceiling
		};
		if($this->canBeSupportedAt($blockReplace, $preferHanging)){
			$this->hanging = $preferHanging;
		}elseif($this->canBeSupportedAt($blockReplace, !$preferHanging)){
			$this->hanging = !$preferHanging;
		}else{
			return false;
		}
		//vanilla only merges with an opposite tip when the player is sneaking
		$this->thickness = $this->calculateThickness($blockReplace, $player !== null && $player->isSneaking());
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if(!$this->canBeSupportedAt($this, $this->hanging)){
			if($this->hanging){
				//the stalactite falls a little later (it keeps its thickness until then)
				$world->scheduleDelayedBlockUpdate($this->position, self::FALL_DELAY_TICKS);
			}else{
				$world->useBreakOn($this->position);
			}
			return;
		}
		$thickness = $this->calculateThickness($this, $this->thickness === DripstoneThickness::MERGE);
		if($thickness !== $this->thickness){
			$this->thickness = $thickness;
			$world->setBlock($this->position, $this);
		}
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Falling stalactites, stalagmite damage and tridents
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Makes the stalactite fall from this block (which lost its support) down to its tip: every block becomes a
	 * falling block, and the tip hurts what it lands on. Java SpeleothemBlock.spawnFallingStalactite().
	 *
	 * @internal
	 * @return int number of falling blocks spawned
	 */
	public function spawnFallingStalactite() : int{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$z = $this->position->getFloorZ();
		$startY = $this->position->getFloorY();
		$spawned = 0;
		for($y = $startY; $world->isInWorld($x, $y, $z); --$y){
			$block = $world->getBlockAt($x, $y, $z);
			if(!$block instanceof PointedDripstone || !$this->isSameTypeHanging($block)){
				break;
			}
			$block = clone $block; //the world may hand out its cached block
			$isTip = $block->thickness === DripstoneThickness::TIP || $block->thickness === DripstoneThickness::MERGE;
			if($isTip){
				$block->fallDamagePerBlock = (float) max($startY - $y + 1, self::MIN_STALACTITE_DAMAGE_PER_BLOCK);
			}
			$world->setBlockAt($x, $y, $z, LiquidLayer::holdsWater($block) ? VanillaBlocks::WATER() : VanillaBlocks::AIR());
			$falling = new FallingBlock(Location::fromObject(new Vector3($x + 0.5, $y, $z + 0.5), $world), $block);
			$falling->spawnToAll();
			++$spawned;
			if($isTip){
				break;
			}
		}
		return $spawned;
	}

	public function tickFalling() : ?Block{
		return null;
	}

	/**
	 * A falling stalactite never lands as a block (it can't hang from the ground): it shatters, dropping itself.
	 */
	public function onHitGround(FallingBlock $blockEntity) : bool{
		$world = $blockEntity->getWorld();
		$pos = $blockEntity->getPosition();
		if(GameRules::allowsEntityDrops($world)){
			$world->dropItem($pos, $this->asItem());
		}
		$world->addSound($pos, new PointedDripstoneLandSound());
		return false;
	}

	public function getFallDamagePerBlock() : float{
		if($this->fallDamagePerBlock > 0){
			return $this->fallDamagePerBlock;
		}
		//a falling tip that wasn't spawned by spawnFallingStalactite() (loaded from a saved chunk mid-fall, or made by
		//a plugin) hurts at least as much as the shortest stalactite
		return $this->hanging && ($this->thickness === DripstoneThickness::TIP || $this->thickness === DripstoneThickness::MERGE) ?
			self::MIN_STALACTITE_DAMAGE_PER_BLOCK : 0.0;
	}

	public function getMaxFallDamage() : float{
		return self::MAX_STALACTITE_DAMAGE;
	}

	public function getLandSound() : ?Sound{
		return new PointedDripstoneLandSound();
	}

	/**
	 * Landing on the tip of a pointed dripstone stalagmite hurts more (Java PointedDripstoneBlock.fallOn()): the fall
	 * counts 2.5 blocks more and its damage is doubled.
	 */
	public function onEntityLand(Entity $entity) : ?float{
		if($this->isStalagmiteTip()){
			$entity->setFallDistance(self::getStalagmiteFallDistance($entity->getFallDistance()));
		}
		return null;
	}

	/**
	 * Whether this is the tip of a stalagmite: falling onto it hurts more, with the stalagmite damage cause.
	 */
	public function isStalagmiteTip() : bool{
		return !$this->hanging && $this->thickness === DripstoneThickness::TIP && $this->getTypeId() === BlockTypeIds::POINTED_DRIPSTONE;
	}

	/**
	 * Returns the fall distance that gives the stalagmite damage, ceil((fallDistance + 2.5 - 3) * 2), with the normal
	 * fall damage formula ceil(fallDistance - 3).
	 *
	 * @internal
	 */
	public static function getStalagmiteFallDistance(float $fallDistance) : float{
		return ($fallDistance + self::STALAGMITE_FALL_DISTANCE_OFFSET - 3) * self::STALAGMITE_FALL_DAMAGE_MULTIPLIER + 3;
	}

	public function onProjectileHit(Projectile $projectile, RayTraceResult $hitResult) : void{
		$world = $this->position->getWorld();
		if(
			$projectile instanceof Trident &&
			$projectile->getMotion()->lengthSquared() > self::MIN_TRIDENT_SPEED_TO_BREAK ** 2 &&
			GameRules::allowsProjectileBlockBreaking($world)
		){
			$world->useBreakOn($this->position);
		}
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Growth
	// ---------------------------------------------------------------------------------------------------------------

	private function getMaxGrowthLength() : int{
		return $this->getTypeId() === BlockTypeIds::SULFUR_SPIKE ? self::SULFUR_SPIKE_MAX_GROWTH_LENGTH : self::MAX_GROWTH_LENGTH;
	}

	/**
	 * Returns whether the stalactite whose top block this is can grow: pointed dripstone hangs from a dripstone block
	 * under a water source (or waterlogged block), sulfur spikes from a sulfur block.
	 *
	 * @internal
	 */
	public function canGrow() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		if(!$world->isInWorld($x, $y + 2, $z)){
			return false;
		}
		$root = $world->getBlockAt($x, $y + 1, $z)->getTypeId();
		if($this->getTypeId() === BlockTypeIds::SULFUR_SPIKE){
			return $root === BlockTypeIds::SULFUR;
		}
		return $root === BlockTypeIds::DRIPSTONE_BLOCK && self::isWaterSource($world->getBlockAt($x, $y + 2, $z));
	}

	private static function isWaterSource(Block $block) : bool{
		return ($block instanceof Water && $block->isSource()) || LiquidLayer::holdsWater($block);
	}

	private static function hasFluid(Block $block) : bool{
		return $block instanceof Liquid || LiquidLayer::holdsWater($block);
	}

	/**
	 * Grows the stalactite whose top block this is, or a stalagmite below it (Java
	 * SpeleothemBlock.growStalactiteOrStalagmiteIfPossible()).
	 *
	 * @internal
	 * @param bool $growStalactite true to grow the tip of the stalactite, false to grow a stalagmite below it
	 * @return bool whether a block was added
	 */
	public function growStalactiteOrStalagmite(bool $growStalactite) : bool{
		if(!$this->isStalactiteStart() || !$this->canGrow()){
			return false;
		}
		$tip = $this->findTipWithin($this->getMaxGrowthLength());
		if($tip === null || $tip->thickness !== DripstoneThickness::TIP || LiquidLayer::holdsWater($tip) || !$tip->canTipGrow()){
			return false;
		}
		return $growStalactite ? $tip->grow() : $tip->growStalagmiteBelow();
	}

	/**
	 * Returns the unmerged tip of this stalactite found within the given number of blocks (counting this one).
	 */
	private function findTipWithin(int $maxLength) : ?PointedDripstone{
		if($this->thickness === DripstoneThickness::TIP){
			return $this;
		}
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$z = $this->position->getFloorZ();
		for($i = 1, $y = $this->position->getFloorY() - 1; $i < $maxLength && $world->isInWorld($x, $y, $z); ++$i, --$y){
			$block = $world->getBlockAt($x, $y, $z);
			if(!$block instanceof PointedDripstone || !$this->isSameTypeHanging($block)){
				return null;
			}
			if($block->thickness === DripstoneThickness::TIP){
				return $block;
			}
		}
		return null;
	}

	/**
	 * Returns whether this tip can grow: the block in front of it is air, or the unmerged tip of an opposite block.
	 */
	private function canTipGrow() : bool{
		$front = $this->getSide($this->getTipDirection());
		if(self::hasFluid($front)){
			return false;
		}
		return $front->getTypeId() === BlockTypeIds::AIR || $this->isUnmergedTipWithDirection($front, Facing::opposite($this->getTipDirection()));
	}

	private function isUnmergedTipWithDirection(Block $block, int $tipDirection) : bool{
		return $block instanceof PointedDripstone && $block->hasSameTypeId($this) && $block->thickness === DripstoneThickness::TIP && $block->getTipDirection() === $tipDirection;
	}

	/**
	 * Adds a block in front of this tip, or merges the tips if an opposite tip is there.
	 */
	private function grow() : bool{
		$direction = $this->getTipDirection();
		$target = $this->getSide($direction);
		if($this->isUnmergedTipWithDirection($target, Facing::opposite($direction))){
			$stalactite = $this->hanging ? $this->position : $target->position;
			$stalagmite = $this->hanging ? $target->position : $this->position;
			$this->createAt($stalactite, true, DripstoneThickness::MERGE);
			$this->createAt($stalagmite, false, DripstoneThickness::MERGE);
			return true;
		}
		if($target->getTypeId() === BlockTypeIds::AIR){
			$this->createAt($target->position, $this->hanging, DripstoneThickness::TIP);
			return true;
		}
		return false;
	}

	private function createAt(Vector3 $pos, bool $hanging, DripstoneThickness $thickness) : void{
		$block = (clone $this)->setHanging($hanging)->setThickness($thickness);
		$block->fallDamagePerBlock = 0.0;
		$this->position->getWorld()->setBlock($pos, $block);
	}

	/**
	 * Grows the stalagmite on the floor below this stalactite tip, or starts one (Java growStalagmiteBelow()).
	 */
	private function growStalagmiteBelow() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$z = $this->position->getFloorZ();
		$y = $this->position->getFloorY();
		$isDripstone = $this->getTypeId() === BlockTypeIds::POINTED_DRIPSTONE;
		for($i = 0; $i < self::MAX_STALAGMITE_SEARCH_RANGE; ++$i){
			--$y;
			if(!$world->isInWorld($x, $y - 1, $z)){
				return false;
			}
			$block = $world->getBlockAt($x, $y, $z);
			if(self::hasFluid($block)){
				return false;
			}
			if($block instanceof PointedDripstone && $this->isUnmergedTipWithDirection($block, Facing::UP) && $block->canTipGrow()){
				return $block->grow();
			}
			$below = $world->getBlockAt($x, $y - 1, $z);
			if(
				($below->getSupportType(Facing::UP) === SupportType::FULL || $this->isSameTypeWithTip($below, Facing::UP)) &&
				!LiquidLayer::hasWaterAt($world, $x, $y - 1, $z)
			){
				//Java grows from the block below: a new stalagmite (only into air) or the merge with this tip
				if($block->getTypeId() === BlockTypeIds::AIR){
					$this->createAt($block->position, false, DripstoneThickness::TIP);
					return true;
				}
				return false;
			}
			//pointed dripstone stops at the first block a drop can't fall through, sulfur spikes look through anything
			if($isDripstone && !self::canDripThrough($block)){
				return false;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Dripping into cauldrons
	// ---------------------------------------------------------------------------------------------------------------

	public function ticksRandomly() : bool{
		return $this->hanging;
	}

	public function onRandomTick() : void{
		if($this->getTypeId() === BlockTypeIds::POINTED_DRIPSTONE){
			$this->maybeTransferFluid(Utils::getRandomFloat());
		}
		if(Utils::getRandomFloat() < self::GROWTH_CHANCE){
			$this->growStalactiteOrStalagmite(Utils::getRandomFloat() < 0.5);
		}
	}

	/**
	 * Java PointedDripstoneBlock.maybeTransferFluid(): lets a drop of the fluid above the stalactite fall towards a
	 * cauldron below its tip (it lands in {@link PointedDripstone::onScheduledUpdate()}), or dries mud into clay.
	 * Only the top block of a stalactite drips.
	 *
	 * @param float $random random value in [0, 1) deciding whether a drop falls
	 * @return bool whether a drop fell
	 */
	public function maybeTransferFluid(float $random) : bool{
		if(($random > self::WATER_TRANSFER_CHANCE && $random > self::LAVA_TRANSFER_CHANCE) || !$this->isStalactiteStart()){
			return false;
		}
		[$fluid, $source] = $this->getFluidAboveStalactite();
		if($fluid === self::FLUID_NONE || $source === null){
			return false;
		}
		if($random >= ($fluid === self::FLUID_WATER ? self::WATER_TRANSFER_CHANCE : self::LAVA_TRANSFER_CHANCE)){
			return false;
		}
		$tip = $this->findTip();
		if($tip === null){
			return false;
		}
		$world = $this->position->getWorld();
		if($source->getTypeId() === BlockTypeIds::MUD){
			$clay = VanillaBlocks::CLAY();
			$world->setBlock($source->getPosition(), $clay);
			$world->addGameEvent($source->getPosition(), GameEvent::BLOCK_CHANGE, null, $clay);
			$world->addParticle($tip->getPosition()->add(0.5, 0.0, 0.5), new DripstoneDripParticle());
			return true;
		}
		$cauldron = self::findFillableCauldronBelow($tip, $fluid);
		if($cauldron === null){
			return false;
		}
		$world->addParticle($tip->getPosition()->add(0.5, 0.0, 0.5), new DripstoneDripParticle());
		$world->scheduleDelayedBlockUpdate($this->position, self::DRIP_BASE_DELAY_TICKS + ($tip->getPosition()->getFloorY() - $cauldron->getPosition()->getFloorY()));
		return true;
	}

	/**
	 * A drop let fall by {@link PointedDripstone::maybeTransferFluid()} lands: the cauldron below the tip is filled
	 * if the stalactite, the fluid and the cauldron are still there (Java fills from the cauldron's scheduled tick).
	 */
	public function onScheduledUpdate() : void{
		if(!$this->canBeSupportedAt($this, $this->hanging)){
			//scheduled by onNearbyBlockChange(): the stalactite falls
			if($this->hanging){
				$this->spawnFallingStalactite();
			}else{
				$this->position->getWorld()->useBreakOn($this->position);
			}
			return;
		}
		if(!$this->isStalactiteStart()){
			return;
		}
		[$fluid, ] = $this->getFluidAboveStalactite();
		$tip = $fluid !== self::FLUID_NONE ? $this->findTip() : null;
		$cauldron = $tip !== null ? self::findFillableCauldronBelow($tip, $fluid) : null;
		if($cauldron !== null){
			self::fillCauldron($cauldron, $fluid);
		}
	}

	private function isSameTypeHanging(Block $block) : bool{
		return $block instanceof PointedDripstone && $block->hasSameTypeId($this) && $block->hanging;
	}

	/**
	 * Returns whether this is the top block of a stalactite.
	 */
	public function isStalactiteStart() : bool{
		return $this->hanging && !$this->isSameTypeHanging($this->getSide(Facing::UP));
	}

	/**
	 * Returns the fluid that drips from the stalactite this block belongs to (FLUID_* constant) and the block it comes
	 * from: a water or lava source (or waterlogged block) above the block the stalactite hangs from. Mud counts as
	 * water.
	 *
	 * @return array{int, Block|null}
	 */
	public function getFluidAboveStalactite() : array{
		if(!$this->hanging){
			return [self::FLUID_NONE, null];
		}
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$z = $this->position->getFloorZ();
		for($i = 1, $y = $this->position->getFloorY() + 1; $i < self::MAX_DRIP_SEARCH_LENGTH; ++$i, ++$y){
			if(!$world->isInWorld($x, $y + 1, $z)){
				break;
			}
			$block = $world->getBlockAt($x, $y, $z);
			if($this->isSameTypeHanging($block)){
				continue;
			}
			//$block is the root block of the stalactite
			$above = $world->getBlockAt($x, $y + 1, $z);
			$fluid = match(true){
				$above->getTypeId() === BlockTypeIds::MUD,
				$above instanceof Water && $above->isSource(),
				$above instanceof Waterlogged && $above->isWaterlogged() => self::FLUID_WATER,
				$above instanceof Lava && $above->isSource() => self::FLUID_LAVA,
				default => self::FLUID_NONE
			};
			return [$fluid, $fluid !== self::FLUID_NONE ? $above : null];
		}
		return [self::FLUID_NONE, null];
	}

	/**
	 * Returns the tip of the stalactite this block belongs to (not a merged tip), or null if none is found within 11
	 * blocks.
	 */
	public function findTip() : ?PointedDripstone{
		if($this->thickness === DripstoneThickness::TIP){
			return $this;
		}
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$z = $this->position->getFloorZ();
		for($i = 1, $y = $this->position->getFloorY() - 1; $i < self::MAX_DRIP_SEARCH_LENGTH && $world->isInWorld($x, $y, $z); ++$i, --$y){
			$block = $world->getBlockAt($x, $y, $z);
			if(!$block instanceof PointedDripstone || !$this->isSameTypeHanging($block)){
				return null;
			}
			if($block->thickness === DripstoneThickness::TIP){
				return $block;
			}
		}
		return null;
	}

	/**
	 * Returns whether a drop can fall through the block: air, or a non-solid block without fluid that leaves the
	 * central 4x4 pixel column free (Java canDripThrough()).
	 */
	public static function canDripThrough(Block $block) : bool{
		if($block->getTypeId() === BlockTypeIds::AIR){
			return true;
		}
		if($block instanceof Liquid || ($block instanceof Waterlogged && $block->isWaterlogged()) || ($block->isSolid() && !$block->isTransparent())){
			return false;
		}
		$pos = $block->getPosition();
		$column = new AxisAlignedBB($pos->x + 6 / 16, $pos->y, $pos->z + 6 / 16, $pos->x + 10 / 16, $pos->y + 1, $pos->z + 10 / 16);
		foreach($block->getCollisionBoxes() as $box){
			if($box->intersectsWith($column)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns whether a drop of the fluid (FLUID_* constant) can go into the cauldron: an empty cauldron takes water
	 * and lava, a water cauldron which isn't full (nor dyed) takes water.
	 */
	public static function canCauldronReceiveDrip(Block $block, int $fluid) : bool{
		if($block instanceof Cauldron){
			return $fluid === self::FLUID_WATER || $fluid === self::FLUID_LAVA;
		}
		return $fluid === self::FLUID_WATER && $block instanceof WaterCauldron && $block->getFillLevel() < FillableCauldron::MAX_FILL_LEVEL && $block->getCustomWaterColor() === null;
	}

	/**
	 * Returns the cauldron up to 11 blocks below the tip that a drop of the fluid falls into, or null.
	 */
	public static function findFillableCauldronBelow(PointedDripstone $tip, int $fluid) : ?Block{
		$world = $tip->position->getWorld();
		$x = $tip->position->getFloorX();
		$z = $tip->position->getFloorZ();
		for($i = 1, $y = $tip->position->getFloorY() - 1; $i < self::MAX_DRIP_SEARCH_LENGTH && $world->isInWorld($x, $y, $z); ++$i, --$y){
			$block = $world->getBlockAt($x, $y, $z);
			if(self::canCauldronReceiveDrip($block, $fluid)){
				return $block;
			}
			if(!self::canDripThrough($block)){
				return null;
			}
		}
		return null;
	}

	/**
	 * Adds a drop of the fluid to the cauldron: water adds a third of a cauldron, lava fills it.
	 */
	public static function fillCauldron(Block $cauldron, int $fluid) : void{
		if(!self::canCauldronReceiveDrip($cauldron, $fluid)){
			return;
		}
		$pos = $cauldron->getPosition();
		$world = $pos->getWorld();
		if($fluid === self::FLUID_LAVA){
			$result = VanillaBlocks::LAVA_CAULDRON()->setFillLevel(FillableCauldron::MAX_FILL_LEVEL);
			$sound = new PointedDripstoneDripLavaIntoCauldronSound();
		}else{
			$level = $cauldron instanceof WaterCauldron ? $cauldron->getFillLevel() : 0;
			$result = VanillaBlocks::WATER_CAULDRON()->setFillLevel(min(FillableCauldron::MAX_FILL_LEVEL, $level + self::WATER_DRIP_FILL_AMOUNT));
			$sound = new PointedDripstoneDripWaterIntoCauldronSound();
		}
		$world->setBlock($pos, $result);
		$world->addSound($pos->add(0.5, 0.5, 0.5), $sound);
		$world->addGameEvent($pos, GameEvent::BLOCK_CHANGE, null, $result);
	}
}
