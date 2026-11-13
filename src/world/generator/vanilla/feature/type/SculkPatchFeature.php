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

namespace pocketmine\world\generator\vanilla\feature\type;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function count;
use function max;
use function min;
use function sqrt;

/**
 * minecraft:sculk_patch: SculkPatchFeature with the world generation SculkSpreader, its charge cursors and the sculk
 * behaviours of sculk, sculk veins and other blocks (SculkBlock, SculkVeinBlock, SculkBehaviour.DEFAULT), including
 * the sculk vein MultifaceSpreader.
 */
final class SculkPatchFeature implements Feature{
	//SculkSpreader.createWorldGenSpreader()
	private const GROWTH_SPAWN_COST = 50;
	private const NO_GROWTH_RADIUS = 1;
	private const CHARGE_DECAY_RATE = 5;
	private const ADDITIONAL_DECAY_RATE = 10;
	private const MAX_CURSORS = 32;
	private const MAX_CHARGE = 1000;
	private const MAX_GROWTH_RATE_RADIUS = 24;
	private const MAX_WORLDGEN_SPREAD = 12;

	private const BEHAVIOUR_DEFAULT = 0;
	private const BEHAVIOUR_SCULK = 1;
	private const BEHAVIOUR_VEIN = 2;

	private const SAME_POSITION = 0;
	private const SAME_PLANE = 1;
	private const WRAP_AROUND = 2;
	private const DEFAULT_SPREAD_ORDER = [self::SAME_POSITION, self::SAME_PLANE, self::WRAP_AROUND];

	private const C_X = 0;
	private const C_Y = 1;
	private const C_Z = 2;
	private const C_CHARGE = 3;
	private const C_UPDATE_DELAY = 4;
	private const C_DECAY_DELAY = 5;
	private const C_FACINGS = 6; //bitmask of Direction ordinals, -1 for none (null)

	/**
	 * ChargeCursor.NON_CORNER_NEIGHBOURS: BlockPos.betweenClosed((-1,-1,-1), (1,1,1)) (x fastest, then y, then z)
	 * with at least one zero coordinate, except the origin.
	 * @var int[][]
	 * @phpstan-var list<array{int, int, int}>
	 */
	private static array $nonCornerNeighbours = [];

	private int $chargeCount;
	private int $amountPerCharge;
	private int $spreadAttempts;
	private int $growthRounds;
	private int $spreadRounds;

	private int $sculk;
	private int $sculkVein;
	private int $sculkCatalyst;
	private int $movingPiston;
	private int $sculkState;
	private int $veinState;
	private int $sensorState;
	private int $shriekerState;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$this->chargeCount = WorldgenData::int($json, "charge_count");
		$this->amountPerCharge = WorldgenData::int($json, "amount_per_charge");
		$this->spreadAttempts = WorldgenData::int($json, "spread_attempts");
		$this->growthRounds = WorldgenData::int($json, "growth_rounds");
		$this->spreadRounds = WorldgenData::int($json, "spread_rounds");
		$blocks = $registry->blocks;
		$this->sculk = $blocks->blockIndex("sculk");
		$this->sculkVein = $blocks->blockIndex("sculk_vein");
		$this->sculkCatalyst = $blocks->blockIndex("sculk_catalyst");
		$this->movingPiston = $blocks->blockIndex("moving_piston");
		$this->sculkState = $blocks->defaultOf("sculk");
		$this->veinState = $blocks->defaultOf("sculk_vein");
		$this->sensorState = $blocks->defaultOf("sculk_sensor");
		$this->shriekerState = $blocks->withBool($blocks->defaultOf("sculk_shrieker"), "can_summon", true);
		if(self::$nonCornerNeighbours === []){
			for($z = -1; $z <= 1; ++$z){
				for($y = -1; $y <= 1; ++$y){
					for($x = -1; $x <= 1; ++$x){
						if(($x === 0 || $y === 0 || $z === 0) && ($x !== 0 || $y !== 0 || $z !== 0)){
							self::$nonCornerNeighbours[] = [$x, $y, $z];
						}
					}
				}
			}
		}
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		if(!$this->canSpreadFrom($c, $x, $y, $z)){
			return false;
		}
		$totalRounds = $this->spreadRounds + $this->growthRounds;
		for($round = 0; $round < $totalRounds; ++$round){
			/** @phpstan-var list<array{int, int, int, int, int, int, int}> $cursors */
			$cursors = [];
			for($i = 0; $i < $this->chargeCount; ++$i){
				//SculkSpreader.addCursors()
				$charge = $this->amountPerCharge;
				while($charge > 0){
					$current = min($charge, self::MAX_CHARGE);
					if(count($cursors) < self::MAX_CURSORS){
						$cursors[] = [$x, $y, $z, $current, 0, 1, -1];
					}
					$charge -= $current;
				}
			}
			$spreadVeins = $round < $this->spreadRounds;
			for($i = 0; $i < $this->spreadAttempts && $cursors !== []; ++$i){
				//SculkSpreader.updateCursors(): world generation never merges cursors
				$processed = [];
				foreach($cursors as $cursor){
					if(max(abs($cursor[self::C_X] - $x), abs($cursor[self::C_Y] - $y), abs($cursor[self::C_Z] - $z)) > 1024){
						continue;
					}
					$this->updateCursor($c, $cursor, $x, $y, $z, $spreadVeins);
					if($cursor[self::C_CHARGE] > 0){
						$processed[] = $cursor;
					}
				}
				$cursors = $processed;
			}
		}
		return true;
	}

	private function canSpreadFrom(FeatureContext $c, int $x, int $y, int $z) : bool{
		$blocks = $c->blocks;
		$start = $c->level->getState($x, $y, $z);
		$block = $blocks->blockOf[$start];
		if($block === $this->sculk || $block === $this->sculkVein){
			return true;
		}
		if(!$blocks->isAir($start) && !($block === $blocks->waterBlock && $blocks->fluidIsSource($start))){
			return false;
		}
		for($d = 0; $d < 6; ++$d){
			if($blocks->isFullCube($c->level->getState($x + Direction::STEP_X[$d], $y + Direction::STEP_Y[$d], $z + Direction::STEP_Z[$d]))){
				return true;
			}
		}
		return false;
	}

	private function behaviour(FeatureContext $c, int $state) : int{
		$block = $c->blocks->blockOf[$state];
		return $block === $this->sculk ? self::BEHAVIOUR_SCULK : ($block === $this->sculkVein ? self::BEHAVIOUR_VEIN : self::BEHAVIOUR_DEFAULT);
	}

	/**
	 * ChargeCursor.update()
	 * @param int[] $cursor
	 * @phpstan-param array{int, int, int, int, int, int, int} $cursor
	 */
	private function updateCursor(FeatureContext $c, array &$cursor, int $ox, int $oy, int $oz, bool $spreadVeins) : void{
		if($cursor[self::C_CHARGE] <= 0){
			return;
		}
		if($cursor[self::C_UPDATE_DELAY] > 0){
			--$cursor[self::C_UPDATE_DELAY];
			return;
		}
		$level = $c->level;
		[$x, $y, $z] = $cursor;
		$state = $level->getState($x, $y, $z);
		$behaviour = $this->behaviour($c, $state);
		if($spreadVeins && $this->attemptSpreadVein($c, $behaviour, $x, $y, $z, $state, $cursor[self::C_FACINGS])){
			if($behaviour !== self::BEHAVIOUR_SCULK){ //canChangeBlockStateOnSpread()
				$state = $level->getState($x, $y, $z);
				$behaviour = $this->behaviour($c, $state);
			}
		}
		$cursor[self::C_CHARGE] = $this->attemptUseCharge($c, $behaviour, $cursor, $ox, $oy, $oz, $spreadVeins);
		if($cursor[self::C_CHARGE] <= 0){
			$this->onDischarged($c, $behaviour, $state, $x, $y, $z);
			return;
		}
		$transfer = $this->getValidMovementPos($c, $x, $y, $z, $ox, $oz);
		if($transfer !== null){
			$this->onDischarged($c, $behaviour, $state, $x, $y, $z);
			[$cursor[self::C_X], $cursor[self::C_Y], $cursor[self::C_Z]] = $transfer;
			$state = $level->getState($transfer[0], $transfer[1], $transfer[2]);
		}else{
			$this->onDischarged($c, $behaviour, $state, $x, $y, $z);
			$cursor[self::C_CHARGE] = 0;
			return;
		}
		if($this->behaviour($c, $state) !== self::BEHAVIOUR_DEFAULT){
			$cursor[self::C_FACINGS] = $this->availableFaces($c, $state);
		}
		$cursor[self::C_DECAY_DELAY] = $behaviour === self::BEHAVIOUR_DEFAULT ? max($cursor[self::C_DECAY_DELAY] - 1, 0) : 1;
		$cursor[self::C_UPDATE_DELAY] = 1;
	}

	private function hasFace(FeatureContext $c, int $state, int $direction) : bool{
		return $c->blocks->blockOf[$state] === $this->sculkVein && $c->blocks->getProperty($state, Direction::NAMES[$direction]) === "true";
	}

	private function availableFaces(FeatureContext $c, int $state) : int{
		$faces = 0;
		if($c->blocks->blockOf[$state] === $this->sculkVein){
			for($d = 0; $d < 6; ++$d){
				if($this->hasFace($c, $state, $d)){
					$faces |= 1 << $d;
				}
			}
		}
		return $faces;
	}

	private function attemptSpreadVein(FeatureContext $c, int $behaviour, int $x, int $y, int $z, int $state, int $facings) : bool{
		if($behaviour === self::BEHAVIOUR_DEFAULT){
			if($facings === -1){
				return $this->spreadAll($c, [self::SAME_POSITION], $c->level->getState($x, $y, $z), $x, $y, $z) > 0;
			}
			if($facings !== 0){
				if($c->blocks->isAir($state) || ($c->blocks->isWater($state) && $c->blocks->fluidIsSource($state))){
					return $this->regrow($c, $x, $y, $z, $state, $facings);
				}
				return false;
			}
		}
		return $this->spreadAll($c, self::DEFAULT_SPREAD_ORDER, $state, $x, $y, $z) > 0;
	}

	/**
	 * SculkVeinBlock.regrow()
	 */
	private function regrow(FeatureContext $c, int $x, int $y, int $z, int $existing, int $faces) : bool{
		$blocks = $c->blocks;
		$newState = $this->veinState;
		$any = false;
		for($d = 0; $d < 6; ++$d){
			if(($faces & (1 << $d)) !== 0 && $c->survival->canAttachTo($c->level, $x, $y, $z, $d)){
				$newState = $blocks->withBool($newState, Direction::NAMES[$d], true);
				$any = true;
			}
		}
		if(!$any){
			return false;
		}
		if($blocks->fluid($existing) !== 0){
			$newState = $blocks->withBool($newState, "waterlogged", true);
		}
		$c->level->setState($x, $y, $z, $newState);
		return true;
	}

	/**
	 * @param int[] $cursor
	 * @phpstan-param array{int, int, int, int, int, int, int} $cursor
	 */
	private function attemptUseCharge(FeatureContext $c, int $behaviour, array $cursor, int $ox, int $oy, int $oz, bool $spreadVeins) : int{
		$charge = $cursor[self::C_CHARGE];
		$random = $c->random;
		if($behaviour === self::BEHAVIOUR_DEFAULT){
			return $cursor[self::C_DECAY_DELAY] > 0 ? $charge : 0;
		}
		[$x, $y, $z] = $cursor;
		if($behaviour === self::BEHAVIOUR_VEIN){
			if($spreadVeins && $this->attemptPlaceSculk($c, $x, $y, $z)){
				return $charge - 1;
			}
			return $random->nextBoundedInt(self::CHARGE_DECAY_RATE) === 0 ? (int) FloatMath::fround($charge * 0.5) : $charge;
		}
		//SculkBlock
		if($charge === 0 || $random->nextBoundedInt(self::CHARGE_DECAY_RATE) !== 0){
			return $charge;
		}
		$dx = $x - $ox;
		$dy = $y - $oy;
		$dz = $z - $oz;
		$distSqr = $dx * $dx + $dy * $dy + $dz * $dz;
		$closeToCatalyst = $distSqr < self::NO_GROWTH_RADIUS * self::NO_GROWTH_RADIUS;
		if(!$closeToCatalyst && $this->canPlaceGrowth($c, $x, $y, $z)){
			$cost = self::GROWTH_SPAWN_COST;
			if($random->nextBoundedInt($cost) < $charge){
				$gy = $y + 1;
				$growth = $random->nextBoundedInt(11) === 0 ? $this->shriekerState : $this->sensorState;
				if($c->blocks->fluid($c->level->getState($x, $gy, $z)) !== 0){
					$growth = $c->blocks->withBool($growth, "waterlogged", true);
				}
				$c->level->setState($x, $gy, $z, $growth);
			}
			return max(0, $charge - $cost);
		}
		if($random->nextBoundedInt(self::ADDITIONAL_DECAY_RATE) !== 0){
			return $charge;
		}
		return $charge - ($closeToCatalyst ? 1 : self::getDecayPenalty($distSqr, $charge));
	}

	private static function getDecayPenalty(int $distSqr, int $charge) : int{
		$outer = FloatMath::fround(FloatMath::fround(sqrt($distSqr)) - (float) self::NO_GROWTH_RADIUS);
		$outerSquared = FloatMath::fround($outer * $outer);
		$maxReachSquared = (self::MAX_GROWTH_RATE_RADIUS - self::NO_GROWTH_RADIUS) ** 2;
		$distanceFactor = min(1.0, FloatMath::fround($outerSquared / $maxReachSquared));
		return max(1, (int) FloatMath::fround(FloatMath::fround($charge * $distanceFactor) * 0.5));
	}

	private function canPlaceGrowth(FeatureContext $c, int $x, int $y, int $z) : bool{
		$blocks = $c->blocks;
		$level = $c->level;
		$above = $level->getState($x, $y + 1, $z);
		if(!$blocks->isAir($above) && !($blocks->blockOf[$above] === $blocks->waterBlock && $blocks->fluidIsSource($above))){
			return false;
		}
		$found = 0;
		for($xx = $x - 4; $xx <= $x + 4; ++$xx){
			for($yy = $y; $yy <= $y + 2; ++$yy){
				for($zz = $z - 4; $zz <= $z + 4; ++$zz){
					if($blocks->isTagged($level->getState($xx, $yy, $zz), "sculk_growth_inhibitors") && ++$found > 2){
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * SculkVeinBlock.attemptPlaceSculk()
	 */
	private function attemptPlaceSculk(FeatureContext $c, int $x, int $y, int $z) : bool{
		$level = $c->level;
		$blocks = $c->blocks;
		$state = $level->getState($x, $y, $z);
		foreach(PlantFeatures::shuffle([0, 1, 2, 3, 4, 5], $c->random) as $support){
			if(!$this->hasFace($c, $state, $support)){
				continue;
			}
			$sx = $x + Direction::STEP_X[$support];
			$sy = $y + Direction::STEP_Y[$support];
			$sz = $z + Direction::STEP_Z[$support];
			if(!$blocks->isTagged($level->getState($sx, $sy, $sz), "sculk_replaceable_world_gen")){
				continue;
			}
			$level->setState($sx, $sy, $sz, $this->sculkState);
			$this->spreadAll($c, self::DEFAULT_SPREAD_ORDER, $this->sculkState, $sx, $sy, $sz);
			$skip = Direction::OPPOSITE[$support];
			for($d = 0; $d < 6; ++$d){
				if($d === $skip){
					continue;
				}
				$vx = $sx + Direction::STEP_X[$d];
				$vy = $sy + Direction::STEP_Y[$d];
				$vz = $sz + Direction::STEP_Z[$d];
				$vein = $level->getState($vx, $vy, $vz);
				if($blocks->blockOf[$vein] === $this->sculkVein){
					$this->onDischarged($c, self::BEHAVIOUR_VEIN, $vein, $vx, $vy, $vz);
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * SculkVeinBlock.onDischarged(): faces against sculk are removed.
	 */
	private function onDischarged(FeatureContext $c, int $behaviour, int $state, int $x, int $y, int $z) : void{
		$blocks = $c->blocks;
		if($behaviour !== self::BEHAVIOUR_VEIN || $blocks->blockOf[$state] !== $this->sculkVein){
			return;
		}
		$level = $c->level;
		$any = false;
		for($d = 0; $d < 6; ++$d){
			if(!$this->hasFace($c, $state, $d)){
				continue;
			}
			if($blocks->blockOf[$level->getState($x + Direction::STEP_X[$d], $y + Direction::STEP_Y[$d], $z + Direction::STEP_Z[$d])] === $this->sculk){
				$state = $blocks->withBool($state, Direction::NAMES[$d], false);
			}else{
				$any = true;
			}
		}
		if(!$any){
			$state = $blocks->fluid($level->getState($x, $y, $z)) !== 0 ? $blocks->water : $blocks->air;
		}
		$level->setState($x, $y, $z, $state);
	}

	/**
	 * ChargeCursor.getValidMovementPos()
	 * @phpstan-return array{int, int, int}|null
	 */
	private function getValidMovementPos(FeatureContext $c, int $x, int $y, int $z, int $ox, int $oz) : ?array{
		$level = $c->level;
		$blocks = $c->blocks;
		$random = $c->random;
		$offsets = self::$nonCornerNeighbours;
		for($i = count($offsets); $i > 1; --$i){
			$swapTo = $random->nextBoundedInt($i);
			$tmp = $offsets[$i - 1];
			$offsets[$i - 1] = $offsets[$swapTo];
			$offsets[$swapTo] = $tmp;
		}
		$result = null;
		foreach($offsets as [$dx, $dy, $dz]){
			$nx = $x + $dx;
			$ny = $y + $dy;
			$nz = $z + $dz;
			$mx = $ox - $nx;
			$mz = $oz - $nz;
			if($mx * $mx + $mz * $mz > self::MAX_WORLDGEN_SPREAD * self::MAX_WORLDGEN_SPREAD){
				continue;
			}
			$transferee = $level->getState($nx, $ny, $nz);
			$block = $blocks->blockOf[$transferee];
			if(($block !== $this->sculk && $block !== $this->sculkVein) || !$this->isMovementUnobstructed($c, $x, $y, $z, $dx, $dy, $dz)){
				continue;
			}
			$result = [$nx, $ny, $nz];
			if($this->hasSubstrateAccess($c, $transferee, $nx, $ny, $nz)){
				break;
			}
		}
		return $result;
	}

	private function hasSubstrateAccess(FeatureContext $c, int $state, int $x, int $y, int $z) : bool{
		if($c->blocks->blockOf[$state] !== $this->sculkVein){
			return false;
		}
		for($d = 0; $d < 6; ++$d){
			if($this->hasFace($c, $state, $d) && $c->blocks->isTagged($c->level->getState($x + Direction::STEP_X[$d], $y + Direction::STEP_Y[$d], $z + Direction::STEP_Z[$d]), "sculk_replaceable")){
				return true;
			}
		}
		return false;
	}

	private function isMovementUnobstructed(FeatureContext $c, int $x, int $y, int $z, int $dx, int $dy, int $dz) : bool{
		if(abs($dx) + abs($dy) + abs($dz) === 1){
			return true;
		}
		$directionX = $dx < 0 ? Direction::WEST : Direction::EAST;
		$directionY = $dy < 0 ? Direction::DOWN : Direction::UP;
		$directionZ = $dz < 0 ? Direction::NORTH : Direction::SOUTH;
		if($dx === 0){
			return $this->isUnobstructed($c, $x, $y, $z, $directionY) || $this->isUnobstructed($c, $x, $y, $z, $directionZ);
		}
		if($dy === 0){
			return $this->isUnobstructed($c, $x, $y, $z, $directionX) || $this->isUnobstructed($c, $x, $y, $z, $directionZ);
		}
		return $this->isUnobstructed($c, $x, $y, $z, $directionX) || $this->isUnobstructed($c, $x, $y, $z, $directionY);
	}

	private function isUnobstructed(FeatureContext $c, int $x, int $y, int $z, int $direction) : bool{
		$state = $c->level->getState($x + Direction::STEP_X[$direction], $y + Direction::STEP_Y[$direction], $z + Direction::STEP_Z[$direction]);
		return !$c->blocks->isFaceSturdy($state, Direction::OPPOSITE[$direction]);
	}

	/**
	 * MultifaceSpreader.spreadAll() with SculkVeinSpreaderConfig.
	 * @param int[] $spreadTypes
	 */
	private function spreadAll(FeatureContext $c, array $spreadTypes, int $state, int $x, int $y, int $z) : int{
		$total = 0;
		$otherSource = $c->blocks->blockOf[$state] !== $this->sculkVein;
		for($face = 0; $face < 6; ++$face){
			if(!$otherSource && !$this->hasFace($c, $state, $face)){
				continue;
			}
			for($spread = 0; $spread < 6; ++$spread){
				if($this->spreadFromFaceTowardDirection($c, $spreadTypes, $otherSource, $state, $x, $y, $z, $face, $spread)){
					++$total;
				}
			}
		}
		return $total;
	}

	/**
	 * @param int[] $spreadTypes
	 */
	private function spreadFromFaceTowardDirection(FeatureContext $c, array $spreadTypes, bool $otherSource, int $state, int $x, int $y, int $z, int $startingFace, int $spread) : bool{
		if(Direction::AXIS[$spread] === Direction::AXIS[$startingFace]){
			return false;
		}
		if(!$otherSource && (!$this->hasFace($c, $state, $startingFace) || $this->hasFace($c, $state, $spread))){
			return false;
		}
		foreach($spreadTypes as $type){
			if($type === self::SAME_POSITION){
				[$px, $py, $pz, $face] = [$x, $y, $z, $spread];
			}elseif($type === self::SAME_PLANE){
				[$px, $py, $pz, $face] = [$x + Direction::STEP_X[$spread], $y + Direction::STEP_Y[$spread], $z + Direction::STEP_Z[$spread], $startingFace];
			}else{
				[$px, $py, $pz, $face] = [
					$x + Direction::STEP_X[$spread] + Direction::STEP_X[$startingFace],
					$y + Direction::STEP_Y[$spread] + Direction::STEP_Y[$startingFace],
					$z + Direction::STEP_Z[$spread] + Direction::STEP_Z[$startingFace],
					Direction::OPPOSITE[$spread]
				];
			}
			$existing = $c->level->getState($px, $py, $pz);
			if($this->stateCanBeReplaced($c, $x, $y, $z, $px, $py, $pz, $face, $existing) && $this->isValidStateForPlacement($c, $existing, $px, $py, $pz, $face)){
				//SpreadConfig.placeBlock()
				$newState = $this->getStateForPlacement($c, $existing, $px, $py, $pz, $face);
				if($newState === null){
					return false;
				}
				return $c->level->setState($px, $py, $pz, $newState);
			}
		}
		return false;
	}

	private function isValidStateForPlacement(FeatureContext $c, int $oldState, int $x, int $y, int $z, int $direction) : bool{
		if($this->hasFace($c, $oldState, $direction)){
			return false;
		}
		return $c->survival->canAttachTo($c->level, $x, $y, $z, $direction);
	}

	private function getStateForPlacement(FeatureContext $c, int $oldState, int $x, int $y, int $z, int $direction) : ?int{
		if(!$this->isValidStateForPlacement($c, $oldState, $x, $y, $z, $direction)){
			return null;
		}
		$blocks = $c->blocks;
		if($blocks->blockOf[$oldState] === $this->sculkVein){
			$state = $oldState;
		}elseif($blocks->isWater($oldState) && $blocks->fluidIsSource($oldState)){
			$state = $blocks->withBool($this->veinState, "waterlogged", true);
		}else{
			$state = $this->veinState;
		}
		return $blocks->withBool($state, Direction::NAMES[$direction], true);
	}

	/**
	 * SculkVeinSpreaderConfig.stateCanBeReplaced()
	 */
	private function stateCanBeReplaced(FeatureContext $c, int $sx, int $sy, int $sz, int $x, int $y, int $z, int $face, int $existing) : bool{
		$blocks = $c->blocks;
		$level = $c->level;
		$against = $blocks->blockOf[$level->getState($x + Direction::STEP_X[$face], $y + Direction::STEP_Y[$face], $z + Direction::STEP_Z[$face])];
		if($against === $this->sculk || $against === $this->sculkCatalyst || $against === $this->movingPiston){
			return false;
		}
		if(abs($sx - $x) + abs($sy - $y) + abs($sz - $z) === 2){
			$o = Direction::OPPOSITE[$face];
			$nx = $sx + Direction::STEP_X[$o];
			$ny = $sy + Direction::STEP_Y[$o];
			$nz = $sz + Direction::STEP_Z[$o];
			if($blocks->isFaceSturdy($level->getState($nx, $ny, $nz), $face)){
				return false;
			}
		}
		if($blocks->fluid($existing) !== 0 && !$blocks->isWater($existing)){
			return false;
		}
		if($blocks->isTagged($existing, "fire")){
			return false;
		}
		if($blocks->isTagged($existing, "replaceable")){
			return true;
		}
		return $blocks->isAir($existing) || $blocks->blockOf[$existing] === $this->sculkVein || ($blocks->blockOf[$existing] === $blocks->waterBlock && $blocks->fluidIsSource($existing));
	}
}
