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

namespace pocketmine\block\utils;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Liquid;
use pocketmine\block\Sculk;
use pocketmine\block\SculkCatalyst;
use pocketmine\block\SculkSensor;
use pocketmine\block\SculkShrieker;
use pocketmine\block\SculkVein;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\particle\SculkChargeParticle;
use pocketmine\world\sound\BlockPlaceSound;
use pocketmine\world\sound\SculkSpreadSound;
use pocketmine\world\World;
use function abs;
use function count;
use function floor;
use function log1p;
use function max;
use function min;
use function mt_rand;
use function shuffle;
use function sqrt;

/**
 * Spreads sculk from a sculk catalyst with the experience of the mobs dying near it: port of Java's SculkSpreader
 * (level spreader), its charge cursors and the sculk behaviours of sculk, sculk veins and other blocks (SculkBlock,
 * SculkVeinBlock, SculkBehaviour.DEFAULT), including the sculk vein MultifaceSpreader.
 *
 * Differences: sculk veins are not waterlogged on the server, so the spread skips water; random numbers come from
 * mt_rand() instead of the level's random source.
 */
final class SculkSpreader{
	public const GROWTH_SPAWN_COST = 10;
	public const NO_GROWTH_RADIUS = 4;
	public const CHARGE_DECAY_RATE = 10;
	public const ADDITIONAL_DECAY_RATE = 5;
	public const MAX_CURSORS = 32;
	public const MAX_CHARGE = 1000;
	public const MAX_GROWTH_RATE_RADIUS = 24;
	public const SHRIEKER_PLACEMENT_RATE = 11;
	public const MAX_CURSOR_DISTANCE = 1024;

	private const BEHAVIOUR_DEFAULT = 0;
	private const BEHAVIOUR_SCULK = 1;
	private const BEHAVIOUR_VEIN = 2;

	private const SAME_POSITION = 0;
	private const SAME_PLANE = 1;
	private const WRAP_AROUND = 2;
	private const DEFAULT_SPREAD_ORDER = [self::SAME_POSITION, self::SAME_PLANE, self::WRAP_AROUND];

	/** Bedrock multi_face_direction_bits, used by the charge particles */
	private const PARTICLE_FACE_BITS = [
		Facing::DOWN => 1,
		Facing::UP => 2,
		Facing::SOUTH => 4,
		Facing::WEST => 8,
		Facing::NORTH => 16,
		Facing::EAST => 32
	];

	/**
	 * @var int[][]
	 * @phpstan-var list<array{int, int, int}>
	 */
	private static array $nonCornerNeighbours = [];

	/**
	 * @var true[]|null block type ID => true
	 * @phpstan-var array<int, true>|null
	 */
	private static ?array $replaceable = null;

	/**
	 * @var SculkChargeCursor[]
	 * @phpstan-var list<SculkChargeCursor>
	 */
	private array $cursors = [];

	/**
	 * Blocks that sculk veins turn into sculk (Java tag sculk_replaceable).
	 */
	public static function isSculkReplaceable(Block $block) : bool{
		if(self::$replaceable === null){
			self::$replaceable = [];
			foreach([
				BlockTypeIds::STONE, BlockTypeIds::GRANITE, BlockTypeIds::DIORITE, BlockTypeIds::ANDESITE, BlockTypeIds::TUFF, BlockTypeIds::DEEPSLATE,
				BlockTypeIds::DIRT, BlockTypeIds::MUD, BlockTypeIds::MUDDY_MANGROVE_ROOTS, BlockTypeIds::MOSS_BLOCK, BlockTypeIds::PALE_MOSS_BLOCK,
				BlockTypeIds::GRASS, BlockTypeIds::PODZOL, BlockTypeIds::MYCELIUM,
				BlockTypeIds::HARDENED_CLAY, BlockTypeIds::STAINED_CLAY,
				BlockTypeIds::CRIMSON_NYLIUM, BlockTypeIds::WARPED_NYLIUM,
				BlockTypeIds::NETHERRACK, BlockTypeIds::BASALT, BlockTypeIds::BLACKSTONE,
				BlockTypeIds::SAND, BlockTypeIds::RED_SAND, BlockTypeIds::GRAVEL, BlockTypeIds::SOUL_SAND, BlockTypeIds::SOUL_SOIL,
				BlockTypeIds::CALCITE, BlockTypeIds::SMOOTH_BASALT, BlockTypeIds::CLAY, BlockTypeIds::DRIPSTONE_BLOCK, BlockTypeIds::END_STONE,
				BlockTypeIds::RED_SANDSTONE, BlockTypeIds::SANDSTONE, BlockTypeIds::SULFUR, BlockTypeIds::CINNABAR
			] as $typeId){
				self::$replaceable[$typeId] = true;
			}
		}
		return isset(self::$replaceable[$block->getTypeId()]);
	}

	/**
	 * Adds charge cursors at the given position: one per 1000 charge, at most 32 cursors in total.
	 */
	public function addCursors(int $x, int $y, int $z, int $charge) : void{
		while($charge > 0){
			$current = min($charge, self::MAX_CHARGE);
			if(count($this->cursors) < self::MAX_CURSORS){
				$this->cursors[] = new SculkChargeCursor($x, $y, $z, $current);
			}
			$charge -= $current;
		}
	}

	/**
	 * @return SculkChargeCursor[]
	 * @phpstan-return list<SculkChargeCursor>
	 */
	public function getCursors() : array{
		return $this->cursors;
	}

	public function hasCursors() : bool{
		return count($this->cursors) > 0;
	}

	public function clear() : void{
		$this->cursors = [];
	}

	/**
	 * Moves every cursor one step (Java SculkSpreader.updateCursors() with spreadVeins = true); called every tick by
	 * the catalyst at the given origin. Cursors on the same block are merged.
	 */
	public function updateCursors(World $world, int $ox, int $oy, int $oz) : void{
		if(count($this->cursors) === 0){
			return;
		}
		$processed = [];
		/** @phpstan-var array<int, SculkChargeCursor> $mergeable */
		$mergeable = [];
		/** @phpstan-var array<int, int> $chargeAt */
		$chargeAt = [];
		foreach($this->cursors as $cursor){
			if(max(abs($cursor->x - $ox), abs($cursor->y - $oy), abs($cursor->z - $oz)) > self::MAX_CURSOR_DISTANCE){
				continue;
			}
			$this->updateCursor($world, $cursor, $ox, $oy, $oz);
			if($cursor->charge <= 0){
				$world->addParticle(new Vector3($cursor->x + 0.5, $cursor->y + 0.5, $cursor->z + 0.5), new SculkChargeParticle(0, 0, true));
				continue;
			}
			$hash = World::blockHash($cursor->x, $cursor->y, $cursor->z);
			$chargeAt[$hash] = ($chargeAt[$hash] ?? 0) + $cursor->charge;
			$existing = $mergeable[$hash] ?? null;
			if($existing === null){
				$mergeable[$hash] = $cursor;
				$processed[] = $cursor;
			}elseif($cursor->charge + $existing->charge <= self::MAX_CHARGE){
				$existing->charge += $cursor->charge;
				$cursor->charge = 0;
				$existing->updateDelay = min($existing->updateDelay, $cursor->updateDelay);
			}else{
				$processed[] = $cursor;
				if($cursor->charge < $existing->charge){
					$mergeable[$hash] = $cursor;
				}
			}
		}
		foreach($chargeAt as $hash => $charge){
			$cursor = $mergeable[$hash] ?? null;
			if($cursor === null || $cursor->facings === null){
				continue;
			}
			$faces = 0;
			foreach($cursor->facings as $face){
				$faces |= self::PARTICLE_FACE_BITS[$face];
			}
			$count = (int) (log1p($charge) / 2.299999952316284) + 1;
			$world->addParticle(new Vector3($cursor->x + 0.5, $cursor->y + 0.5, $cursor->z + 0.5), new SculkChargeParticle($count, $faces));
		}
		$this->cursors = $processed;
	}

	private static function behaviour(Block $block) : int{
		return $block instanceof Sculk ? self::BEHAVIOUR_SCULK : ($block instanceof SculkVein ? self::BEHAVIOUR_VEIN : self::BEHAVIOUR_DEFAULT);
	}

	private function updateCursor(World $world, SculkChargeCursor $cursor, int $ox, int $oy, int $oz) : void{
		if($cursor->charge <= 0 || !$world->isInWorld($cursor->x, $cursor->y, $cursor->z) || !$world->isChunkLoaded($cursor->x >> 4, $cursor->z >> 4)){
			return;
		}
		if($cursor->updateDelay > 0){
			--$cursor->updateDelay;
			return;
		}
		$block = $world->getBlockAt($cursor->x, $cursor->y, $cursor->z);
		$behaviour = self::behaviour($block);
		if($this->attemptSpreadVein($world, $behaviour, $block, $cursor->facings)){
			if($behaviour !== self::BEHAVIOUR_SCULK){ //canChangeBlockStateOnSpread()
				$block = $world->getBlockAt($cursor->x, $cursor->y, $cursor->z);
				$behaviour = self::behaviour($block);
			}
			$world->addSound($block->getPosition()->add(0.5, 0.5, 0.5), new SculkSpreadSound());
		}
		$cursor->charge = $this->attemptUseCharge($world, $behaviour, $cursor, $ox, $oy, $oz);
		if($cursor->charge <= 0){
			$this->onDischarged($world, $behaviour, $cursor->x, $cursor->y, $cursor->z);
			return;
		}
		$transfer = $this->getValidMovementPos($world, $cursor->x, $cursor->y, $cursor->z);
		if($transfer !== null){
			$this->onDischarged($world, $behaviour, $cursor->x, $cursor->y, $cursor->z);
			[$cursor->x, $cursor->y, $cursor->z] = $transfer;
			$block = $world->getBlockAt($cursor->x, $cursor->y, $cursor->z);
		}
		if($block instanceof Sculk || $block instanceof SculkVein){
			$cursor->facings = $block instanceof SculkVein ? $block->getFaces() : [];
		}
		$cursor->decayDelay = $behaviour === self::BEHAVIOUR_DEFAULT ? max($cursor->decayDelay - 1, 0) : 1;
		$cursor->updateDelay = 1;
	}

	/**
	 * @param int[]|null $facings
	 */
	private function attemptSpreadVein(World $world, int $behaviour, Block $block, ?array $facings) : bool{
		if($behaviour === self::BEHAVIOUR_DEFAULT){
			if($facings === null){
				return $this->spreadAll($world, [self::SAME_POSITION], $block) > 0;
			}
			if(count($facings) > 0){
				return $block->getTypeId() === BlockTypeIds::AIR && $this->regrow($world, $block, $facings);
			}
		}
		return $this->spreadAll($world, self::DEFAULT_SPREAD_ORDER, $block) > 0;
	}

	private static function canAttachTo(Block $block, int $face) : bool{
		return $block->getSide($face)->getSupportType(Facing::opposite($face)) === SupportType::FULL;
	}

	/**
	 * SculkVeinBlock.regrow()
	 * @param int[] $faces
	 */
	private function regrow(World $world, Block $at, array $faces) : bool{
		$vein = VanillaBlocks::SCULK_VEIN();
		$any = false;
		foreach($faces as $face){
			if(self::canAttachTo($at, $face)){
				$vein->setFace($face, true);
				$any = true;
			}
		}
		if(!$any){
			return false;
		}
		$world->setBlock($at->getPosition(), $vein);
		return true;
	}

	private function attemptUseCharge(World $world, int $behaviour, SculkChargeCursor $cursor, int $ox, int $oy, int $oz) : int{
		$charge = $cursor->charge;
		if($behaviour === self::BEHAVIOUR_DEFAULT){
			return $cursor->decayDelay > 0 ? $charge : 0;
		}
		if($behaviour === self::BEHAVIOUR_VEIN){
			if($this->attemptPlaceSculk($world, $cursor->x, $cursor->y, $cursor->z)){
				return $charge - 1;
			}
			return mt_rand(0, self::CHARGE_DECAY_RATE - 1) === 0 ? (int) floor($charge * 0.5) : $charge;
		}
		//SculkBlock.attemptUseCharge()
		if($charge === 0 || mt_rand(0, self::CHARGE_DECAY_RATE - 1) !== 0){
			return $charge;
		}
		$dx = $cursor->x - $ox;
		$dy = $cursor->y - $oy;
		$dz = $cursor->z - $oz;
		$distSqr = $dx * $dx + $dy * $dy + $dz * $dz;
		$closeToCatalyst = $distSqr < self::NO_GROWTH_RADIUS * self::NO_GROWTH_RADIUS;
		if(!$closeToCatalyst && $this->canPlaceGrowth($world, $cursor->x, $cursor->y, $cursor->z)){
			if(mt_rand(0, self::GROWTH_SPAWN_COST - 1) < $charge){
				//shriekers grown by a catalyst can't summon wardens
				$growth = mt_rand(0, self::SHRIEKER_PLACEMENT_RATE - 1) === 0 ? VanillaBlocks::SCULK_SHRIEKER()->setCanSummon(false) : VanillaBlocks::SCULK_SENSOR();
				$pos = new Vector3($cursor->x, $cursor->y + 1, $cursor->z);
				$world->setBlock($pos, $growth);
				$world->addSound($pos->add(0.5, 0.5, 0.5), new BlockPlaceSound($growth));
			}
			return max(0, $charge - self::GROWTH_SPAWN_COST);
		}
		if(mt_rand(0, self::ADDITIONAL_DECAY_RATE - 1) !== 0){
			return $charge;
		}
		return $charge - ($closeToCatalyst ? 1 : self::getDecayPenalty($distSqr, $charge));
	}

	private static function getDecayPenalty(int $distSqr, int $charge) : int{
		$outer = sqrt($distSqr) - self::NO_GROWTH_RADIUS;
		$maxReachSquared = (self::MAX_GROWTH_RATE_RADIUS - self::NO_GROWTH_RADIUS) ** 2;
		$distanceFactor = min(1.0, ($outer * $outer) / $maxReachSquared);
		return max(1, (int) ($charge * $distanceFactor * 0.5));
	}

	/**
	 * A sensor or shrieker can grow on the sculk block if the block above is air and there are at most 2 others
	 * around (9x3x9 box).
	 */
	private function canPlaceGrowth(World $world, int $x, int $y, int $z) : bool{
		if(!$world->isInWorld($x, $y + 1, $z) || $world->getBlockAt($x, $y + 1, $z)->getTypeId() !== BlockTypeIds::AIR){
			return false;
		}
		$found = 0;
		for($xx = $x - 4; $xx <= $x + 4; ++$xx){
			for($yy = $y; $yy <= $y + 2; ++$yy){
				for($zz = $z - 4; $zz <= $z + 4; ++$zz){
					if(!$world->isInWorld($xx, $yy, $zz)){
						continue;
					}
					$block = $world->getBlockAt($xx, $yy, $zz);
					if(($block instanceof SculkSensor || $block instanceof SculkShrieker) && ++$found > 2){
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * SculkVeinBlock.attemptPlaceSculk(): turns a block the vein lies on into sculk.
	 */
	private function attemptPlaceSculk(World $world, int $x, int $y, int $z) : bool{
		$vein = $world->getBlockAt($x, $y, $z);
		if(!$vein instanceof SculkVein){
			return false;
		}
		$faces = Facing::ALL;
		shuffle($faces);
		foreach($faces as $support){
			if(!$vein->hasFace($support)){
				continue;
			}
			$supportBlock = $vein->getSide($support);
			if(!self::isSculkReplaceable($supportBlock)){
				continue;
			}
			$supportPos = $supportBlock->getPosition();
			$world->setBlock($supportPos, VanillaBlocks::SCULK());
			$world->addSound($supportPos->add(0.5, 0.5, 0.5), new SculkSpreadSound());
			$this->spreadAll($world, self::DEFAULT_SPREAD_ORDER, $world->getBlock($supportPos));
			$skip = Facing::opposite($support);
			foreach(Facing::ALL as $face){
				if($face === $skip){
					continue;
				}
				[$dx, $dy, $dz] = Facing::OFFSET[$face];
				$vx = $supportPos->getFloorX() + $dx;
				$vy = $supportPos->getFloorY() + $dy;
				$vz = $supportPos->getFloorZ() + $dz;
				if($world->isInWorld($vx, $vy, $vz) && $world->getBlockAt($vx, $vy, $vz) instanceof SculkVein){
					$this->onDischarged($world, self::BEHAVIOUR_VEIN, $vx, $vy, $vz);
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * SculkVeinBlock.onDischarged(): faces lying on sculk are removed (the vein disappears if none is left).
	 */
	private function onDischarged(World $world, int $behaviour, int $x, int $y, int $z) : void{
		if($behaviour !== self::BEHAVIOUR_VEIN){
			return;
		}
		$vein = $world->getBlockAt($x, $y, $z);
		if(!$vein instanceof SculkVein){
			return;
		}
		$changed = false;
		foreach($vein->getFaces() as $face){
			if($vein->getSide($face) instanceof Sculk){
				$vein->setFace($face, false);
				$changed = true;
			}
		}
		if(count($vein->getFaces()) === 0){
			$world->setBlock($vein->getPosition(), VanillaBlocks::AIR());
		}elseif($changed){
			$world->setBlock($vein->getPosition(), $vein);
		}
	}

	/**
	 * ChargeCursor.getValidMovementPos(): a random sculk or sculk vein block around (no corners), preferring a vein
	 * lying on a block that can turn into sculk.
	 *
	 * @phpstan-return array{int, int, int}|null
	 */
	private function getValidMovementPos(World $world, int $x, int $y, int $z) : ?array{
		if(count(self::$nonCornerNeighbours) === 0){
			for($oz = -1; $oz <= 1; ++$oz){
				for($oy = -1; $oy <= 1; ++$oy){
					for($ox = -1; $ox <= 1; ++$ox){
						if(($ox === 0 || $oy === 0 || $oz === 0) && ($ox !== 0 || $oy !== 0 || $oz !== 0)){
							self::$nonCornerNeighbours[] = [$ox, $oy, $oz];
						}
					}
				}
			}
		}
		$offsets = self::$nonCornerNeighbours;
		shuffle($offsets);
		$result = null;
		foreach($offsets as [$dx, $dy, $dz]){
			$nx = $x + $dx;
			$ny = $y + $dy;
			$nz = $z + $dz;
			if(!$world->isInWorld($nx, $ny, $nz)){
				continue;
			}
			$neighbour = $world->getBlockAt($nx, $ny, $nz);
			if((!$neighbour instanceof Sculk && !$neighbour instanceof SculkVein) || !$this->isMovementUnobstructed($world, $x, $y, $z, $dx, $dy, $dz)){
				continue;
			}
			$result = [$nx, $ny, $nz];
			if($neighbour instanceof SculkVein && self::hasSubstrateAccess($neighbour)){
				break;
			}
		}
		return $result;
	}

	private static function hasSubstrateAccess(SculkVein $vein) : bool{
		foreach($vein->getFaces() as $face){
			if(self::isSculkReplaceable($vein->getSide($face))){
				return true;
			}
		}
		return false;
	}

	private function isMovementUnobstructed(World $world, int $x, int $y, int $z, int $dx, int $dy, int $dz) : bool{
		if(abs($dx) + abs($dy) + abs($dz) === 1){
			return true;
		}
		$directionX = $dx < 0 ? Facing::WEST : Facing::EAST;
		$directionY = $dy < 0 ? Facing::DOWN : Facing::UP;
		$directionZ = $dz < 0 ? Facing::NORTH : Facing::SOUTH;
		if($dx === 0){
			return $this->isUnobstructed($world, $x, $y, $z, $directionY) || $this->isUnobstructed($world, $x, $y, $z, $directionZ);
		}
		if($dy === 0){
			return $this->isUnobstructed($world, $x, $y, $z, $directionX) || $this->isUnobstructed($world, $x, $y, $z, $directionZ);
		}
		return $this->isUnobstructed($world, $x, $y, $z, $directionX) || $this->isUnobstructed($world, $x, $y, $z, $directionY);
	}

	private function isUnobstructed(World $world, int $x, int $y, int $z, int $direction) : bool{
		[$dx, $dy, $dz] = Facing::OFFSET[$direction];
		if(!$world->isInWorld($x + $dx, $y + $dy, $z + $dz)){
			return true;
		}
		return $world->getBlockAt($x + $dx, $y + $dy, $z + $dz)->getSupportType(Facing::opposite($direction)) !== SupportType::FULL;
	}

	/**
	 * MultifaceSpreader.spreadAll() with the sculk vein config: spreads veins from every face of a vein (or from every
	 * side of any other block).
	 *
	 * @param int[] $spreadTypes
	 */
	private function spreadAll(World $world, array $spreadTypes, Block $source) : int{
		$total = 0;
		$otherSource = !$source instanceof SculkVein;
		foreach(Facing::ALL as $face){
			if(!$otherSource && !$source->hasFace($face)){
				continue;
			}
			foreach(Facing::ALL as $spread){
				if($this->spreadFromFaceTowardDirection($world, $spreadTypes, $otherSource, $source, $face, $spread)){
					++$total;
				}
			}
		}
		return $total;
	}

	/**
	 * @param int[] $spreadTypes
	 */
	private function spreadFromFaceTowardDirection(World $world, array $spreadTypes, bool $otherSource, Block $source, int $startingFace, int $spread) : bool{
		if(Facing::axis($spread) === Facing::axis($startingFace)){
			return false;
		}
		if(!$otherSource && $source instanceof SculkVein && (!$source->hasFace($startingFace) || $source->hasFace($spread))){
			return false;
		}
		$sx = $source->getPosition()->getFloorX();
		$sy = $source->getPosition()->getFloorY();
		$sz = $source->getPosition()->getFloorZ();
		[$spx, $spy, $spz] = Facing::OFFSET[$spread];
		[$stx, $sty, $stz] = Facing::OFFSET[$startingFace];
		foreach($spreadTypes as $type){
			if($type === self::SAME_POSITION){
				[$px, $py, $pz, $face] = [$sx, $sy, $sz, $spread];
			}elseif($type === self::SAME_PLANE){
				[$px, $py, $pz, $face] = [$sx + $spx, $sy + $spy, $sz + $spz, $startingFace];
			}else{
				[$px, $py, $pz, $face] = [$sx + $spx + $stx, $sy + $spy + $sty, $sz + $spz + $stz, Facing::opposite($spread)];
			}
			if(!$world->isInWorld($px, $py, $pz)){
				continue;
			}
			$existing = $world->getBlockAt($px, $py, $pz);
			if($this->stateCanBeReplaced($world, $sx, $sy, $sz, $existing, $face) && self::isValidStateForPlacement($existing, $face)){
				$vein = $existing instanceof SculkVein ? $existing : VanillaBlocks::SCULK_VEIN();
				$vein->setFace($face, true);
				$world->setBlock($existing->getPosition(), $vein);
				return true;
			}
		}
		return false;
	}

	private static function isValidStateForPlacement(Block $existing, int $face) : bool{
		if($existing instanceof SculkVein && $existing->hasFace($face)){
			return false;
		}
		return self::canAttachTo($existing, $face);
	}

	/**
	 * SculkVeinSpreaderConfig.stateCanBeReplaced()
	 */
	private function stateCanBeReplaced(World $world, int $sx, int $sy, int $sz, Block $existing, int $face) : bool{
		$against = $existing->getSide($face);
		if($against instanceof Sculk || $against instanceof SculkCatalyst || $against->getTypeId() === BlockTypeIds::MOVING_BLOCK){
			return false;
		}
		$pos = $existing->getPosition();
		if(abs($sx - $pos->getFloorX()) + abs($sy - $pos->getFloorY()) + abs($sz - $pos->getFloorZ()) === 2){
			[$dx, $dy, $dz] = Facing::OFFSET[Facing::opposite($face)];
			if($world->isInWorld($sx + $dx, $sy + $dy, $sz + $dz) && $world->getBlockAt($sx + $dx, $sy + $dy, $sz + $dz)->getSupportType($face) === SupportType::FULL){
				return false;
			}
		}
		//veins can't be waterlogged on the server: they don't spread into liquids
		if($existing instanceof Liquid || $existing->getTypeId() === BlockTypeIds::FIRE || $existing->getTypeId() === BlockTypeIds::SOUL_FIRE){
			return false;
		}
		return $existing instanceof SculkVein || $existing->canBeReplaced();
	}
}
