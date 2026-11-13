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

namespace pocketmine\world\generator\vanilla\feature\type\tree;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\Mth;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function cos;
use function floor;
use function intdiv;
use function max;
use function min;
use function sin;
use function sqrt;

/**
 * Vanilla's trunk placers (Java 26.3): straight, forking, giant, mega_jungle, dark_oak, fancy, bending, cherry,
 * upwards_branching and poplar.
 */
final class TrunkPlacer{
	private const STRAIGHT = 0;
	private const FORKING = 1;
	private const GIANT = 2;
	private const MEGA_JUNGLE = 3;
	private const DARK_OAK = 4;
	private const FANCY = 5;
	private const BENDING = 6;
	private const CHERRY = 7;
	private const UPWARDS_BRANCHING = 8;
	private const POPLAR = 9;

	private int $type;
	private int $baseHeight;
	private int $heightRandA;
	private int $heightRandB;

	//bending
	private int $minHeightForLeaves = 0;
	private ?IntProvider $bendLength = null;
	//cherry
	private ?IntProvider $branchCount = null;
	private ?IntProvider $branchHorizontalLength = null;
	private ?IntProvider $branchStartOffsetFromTop = null;
	private ?IntProvider $secondBranchStartOffsetFromTop = null;
	private ?IntProvider $branchEndOffsetFromTop = null;
	//upwards branching
	private ?IntProvider $extraBranchSteps = null;
	private float $placeBranchPerLogProbability = 0.0;
	private ?IntProvider $extraBranchLength = null;
	/** @phpstan-var array<int, true> */
	private array $canGrowThrough = [];
	//poplar
	private ?IntProvider $trunkHeightAboveBranches = null;
	private ?IntProvider $branchAmount = null;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		$this->type = match($type){
			"straight_trunk_placer" => self::STRAIGHT,
			"forking_trunk_placer" => self::FORKING,
			"giant_trunk_placer" => self::GIANT,
			"mega_jungle_trunk_placer" => self::MEGA_JUNGLE,
			"dark_oak_trunk_placer" => self::DARK_OAK,
			"fancy_trunk_placer" => self::FANCY,
			"bending_trunk_placer" => self::BENDING,
			"cherry_trunk_placer" => self::CHERRY,
			"upwards_branching_trunk_placer" => self::UPWARDS_BRANCHING,
			"poplar_trunk_placer" => self::POPLAR,
			default => throw new \RuntimeException("Unknown trunk placer $type")
		};
		$this->baseHeight = WorldgenData::int($json, "base_height");
		$this->heightRandA = WorldgenData::int($json, "height_rand_a");
		$this->heightRandB = WorldgenData::int($json, "height_rand_b");
		switch($this->type){
			case self::BENDING:
				$this->minHeightForLeaves = WorldgenData::int($json, "min_height_for_leaves", 1);
				$this->bendLength = Providers::int($json["bend_length"] ?? null);
				break;
			case self::CHERRY:
				$this->branchCount = Providers::int($json["branch_count"] ?? null);
				$this->branchHorizontalLength = Providers::int($json["branch_horizontal_length"] ?? null);
				$start = WorldgenData::array($json, "branch_start_offset_from_top");
				$min = WorldgenData::int($start, "min_inclusive");
				$max = WorldgenData::int($start, "max_inclusive");
				$this->branchStartOffsetFromTop = Providers::int(["type" => "uniform", "min_inclusive" => $min, "max_inclusive" => $max]);
				$this->secondBranchStartOffsetFromTop = Providers::int(["type" => "uniform", "min_inclusive" => $min, "max_inclusive" => $max - 1]);
				$this->branchEndOffsetFromTop = Providers::int($json["branch_end_offset_from_top"] ?? null);
				break;
			case self::UPWARDS_BRANCHING:
				$this->extraBranchSteps = Providers::int($json["extra_branch_steps"] ?? null);
				$this->placeBranchPerLogProbability = FloatMath::fround(WorldgenData::float($json, "place_branch_per_log_probability"));
				$this->extraBranchLength = Providers::int($json["extra_branch_length"] ?? null);
				$this->canGrowThrough = $registry->blocks->blockSet($json["can_grow_through"] ?? null);
				break;
			case self::POPLAR:
				$this->trunkHeightAboveBranches = Providers::int($json["trunk_height_above_branches"] ?? null);
				$this->branchAmount = Providers::int($json["branch_amount"] ?? null);
				break;
		}
	}

	public function getTreeHeight(FeatureRandom $random) : int{
		return $this->baseHeight + $random->nextBoundedInt($this->heightRandA + 1) + $random->nextBoundedInt($this->heightRandB + 1);
	}

	public function validTreePos(TreeFeature $t, int $x, int $y, int $z) : bool{
		if($t->validTreePos($x, $y, $z)){
			return true;
		}
		return $this->type === self::UPWARDS_BRANCHING && isset($this->canGrowThrough[$t->blocks->blockOf[$t->c->level->getState($x, $y, $z)]]);
	}

	public function isFree(TreeFeature $t, int $x, int $y, int $z) : bool{
		return $this->validTreePos($t, $x, $y, $z) || $t->isLog($x, $y, $z);
	}

	/**
	 * @phpstan-param (\Closure(int) : int)|null $modifier
	 */
	private function placeLog(TreeFeature $t, int $x, int $y, int $z, ?\Closure $modifier = null) : bool{
		if(!$this->validTreePos($t, $x, $y, $z)){
			return false;
		}
		$state = $t->trunkState($x, $y, $z);
		if($modifier !== null){
			$state = $modifier($state);
		}
		$t->setTrunk($x, $y, $z, $state);
		return true;
	}

	private function placeLogIfFree(TreeFeature $t, int $x, int $y, int $z) : void{
		if($this->isFree($t, $x, $y, $z)){
			$this->placeLog($t, $x, $y, $z);
		}
	}

	/**
	 * @phpstan-return \Closure(int) : int
	 */
	private static function axisModifier(TreeFeature $t, string $axis) : \Closure{
		$blocks = $t->blocks;
		return fn(int $state) : int => $blocks->with($state, "axis", $axis);
	}

	private static function axisOf(int $direction) : string{
		return ["x", "y", "z"][Direction::AXIS[$direction]];
	}

	/**
	 * @return int[][] foliage attachments [x, y, z, radiusOffset, foliageHeightOffset, doubleTrunk]
	 * @phpstan-return list<array{int, int, int, int, int, bool}>
	 */
	public function placeTrunk(TreeFeature $t, int $treeHeight, int $x, int $y, int $z) : array{
		$random = $t->c->random;
		switch($this->type){
			case self::STRAIGHT:
				$t->placeBelowTrunkBlock($x, $y - 1, $z);
				for($i = 0; $i < $treeHeight; ++$i){
					$this->placeLog($t, $x, $y + $i, $z);
				}
				return [[$x, $y + $treeHeight, $z, 0, 0, false]];

			case self::FORKING:
				$t->placeBelowTrunkBlock($x, $y - 1, $z);
				$attachments = [];
				$lean = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
				$leanHeight = $treeHeight - $random->nextBoundedInt(4) - 1;
				$leanSteps = 3 - $random->nextBoundedInt(3);
				$tx = $x;
				$tz = $z;
				$ey = null;
				for($yo = 0; $yo < $treeHeight; ++$yo){
					$yy = $y + $yo;
					if($yo >= $leanHeight && $leanSteps > 0){
						$tx += Direction::STEP_X[$lean];
						$tz += Direction::STEP_Z[$lean];
						--$leanSteps;
					}
					if($this->placeLog($t, $tx, $yy, $tz)){
						$ey = $yy + 1;
					}
				}
				if($ey !== null){
					$attachments[] = [$tx, $ey, $tz, 1, 0, false];
				}
				$tx = $x;
				$tz = $z;
				$branch = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
				if($branch !== $lean){
					$branchPos = $leanHeight - $random->nextBoundedInt(2) - 1;
					$branchSteps = 1 + $random->nextBoundedInt(3);
					$ey = null;
					for($yo = $branchPos; $yo < $treeHeight && $branchSteps > 0; ++$yo, --$branchSteps){
						if($yo < 1){
							continue;
						}
						$yy = $y + $yo;
						$tx += Direction::STEP_X[$branch];
						$tz += Direction::STEP_Z[$branch];
						if($this->placeLog($t, $tx, $yy, $tz)){
							$ey = $yy + 1;
						}
					}
					if($ey !== null){
						$attachments[] = [$tx, $ey, $tz, 0, 0, false];
					}
				}
				return $attachments;

			case self::GIANT:
				return $this->placeGiant($t, $treeHeight, $x, $y, $z);

			case self::MEGA_JUNGLE:
				$attachments = $this->placeGiant($t, $treeHeight, $x, $y, $z);
				for($branchHeight = $treeHeight - 2 - $random->nextBoundedInt(4); $branchHeight > intdiv($treeHeight, 2); $branchHeight -= 2 + $random->nextBoundedInt(4)){
					$angle = FloatMath::fround($random->nextFloat() * 6.2831854820251465);
					$bx = 0;
					$bz = 0;
					for($b = 0; $b < 5; ++$b){
						$bx = (int) FloatMath::fround(1.5 + FloatMath::fround(Mth::cos($angle) * $b));
						$bz = (int) FloatMath::fround(1.5 + FloatMath::fround(Mth::sin($angle) * $b));
						$this->placeLog($t, $x + $bx, $y + $branchHeight - 3 + intdiv($b, 2), $z + $bz);
					}
					$attachments[] = [$x + $bx, $y + $branchHeight, $z + $bz, -2, 0, false];
				}
				return $attachments;

			case self::DARK_OAK:
				$attachments = [];
				$t->placeBelowTrunkBlock($x, $y - 1, $z);
				$t->placeBelowTrunkBlock($x + 1, $y - 1, $z);
				$t->placeBelowTrunkBlock($x, $y - 1, $z + 1);
				$t->placeBelowTrunkBlock($x + 1, $y - 1, $z + 1);
				$lean = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
				$leanHeight = $treeHeight - $random->nextBoundedInt(4);
				$leanSteps = 2 - $random->nextBoundedInt(3);
				$tx = $x;
				$tz = $z;
				$ey = $y + $treeHeight - 1;
				for($dy = 0; $dy < $treeHeight; ++$dy){
					if($dy >= $leanHeight && $leanSteps > 0){
						$tx += Direction::STEP_X[$lean];
						$tz += Direction::STEP_Z[$lean];
						--$leanSteps;
					}
					$yy = $y + $dy;
					if($t->isAirOrLeaves($tx, $yy, $tz)){
						$this->placeLog($t, $tx, $yy, $tz);
						$this->placeLog($t, $tx + 1, $yy, $tz);
						$this->placeLog($t, $tx, $yy, $tz + 1);
						$this->placeLog($t, $tx + 1, $yy, $tz + 1);
					}
				}
				$attachments[] = [$tx, $ey, $tz, 0, 0, true];
				for($ox = -1; $ox <= 2; ++$ox){
					for($oz = -1; $oz <= 2; ++$oz){
						if($ox >= 0 && $ox <= 1 && $oz >= 0 && $oz <= 1){
							continue;
						}
						if($random->nextBoundedInt(3) > 0){
							continue;
						}
						$length = $random->nextBoundedInt(3) + 2;
						for($branchY = 0; $branchY < $length; ++$branchY){
							$this->placeLog($t, $x + $ox, $ey - $branchY - 1, $z + $oz);
						}
						$attachments[] = [$x + $ox, $ey, $z + $oz, 0, 0, false];
					}
				}
				return $attachments;

			case self::FANCY:
				return $this->placeFancy($t, $treeHeight, $x, $y, $z);

			case self::BENDING:
				$direction = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
				$logHeight = $treeHeight - 1;
				$px = $x;
				$py = $y;
				$pz = $z;
				$t->placeBelowTrunkBlock($px, $py - 1, $pz);
				$attachments = [];
				for($i = 0; $i <= $logHeight; ++$i){
					if($i + 1 >= $logHeight + $random->nextBoundedInt(2)){
						$px += Direction::STEP_X[$direction];
						$pz += Direction::STEP_Z[$direction];
					}
					if($t->validTreePos($px, $py, $pz)){
						$this->placeLog($t, $px, $py, $pz);
					}
					if($i >= $this->minHeightForLeaves){
						$attachments[] = [$px, $py, $pz, 0, 0, false];
					}
					++$py;
				}
				$dirLength = $this->bendLength?->sample($random) ?? 0;
				for($i = 0; $i <= $dirLength; ++$i){
					if($t->validTreePos($px, $py, $pz)){
						$this->placeLog($t, $px, $py, $pz);
					}
					$attachments[] = [$px, $py, $pz, 0, 0, false];
					$px += Direction::STEP_X[$direction];
					$pz += Direction::STEP_Z[$direction];
				}
				return $attachments;

			case self::CHERRY:
				return $this->placeCherry($t, $treeHeight, $x, $y, $z);

			case self::UPWARDS_BRANCHING:
				$attachments = [];
				for($heightPos = 0; $heightPos < $treeHeight; ++$heightPos){
					$currentHeight = $y + $heightPos;
					if($this->placeLog($t, $x, $currentHeight, $z) && $heightPos < $treeHeight - 1 && $random->nextFloat() < $this->placeBranchPerLogProbability){
						$branchDir = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
						$branchLen = $this->extraBranchLength?->sample($random) ?? 0;
						$branchPos = max(0, $branchLen - ($this->extraBranchLength?->sample($random) ?? 0) - 1);
						$branchSteps = $this->extraBranchSteps?->sample($random) ?? 0;
						$this->placeUpwardsBranch($t, $treeHeight, $attachments, $x, $z, $currentHeight, $branchDir, $branchPos, $branchSteps);
					}
					if($heightPos === $treeHeight - 1){
						$attachments[] = [$x, $currentHeight + 1, $z, 0, 0, false];
					}
				}
				return $attachments;

			case self::POPLAR:
				$t->placeBelowTrunkBlock($x, $y - 1, $z);
				$upToBranches = $treeHeight - ($this->trunkHeightAboveBranches?->sample($random) ?? 0);
				for($i = 0; $i < $treeHeight; ++$i){
					$this->placeLog($t, $x, $y + $i, $z);
					$directions = self::shuffledHorizontalDirections($random);
					if($upToBranches - 1 === $i){
						$branches = $this->branchAmount?->sample($random) ?? 0;
						for($b = 0; $b < $branches; ++$b){
							$d = $directions[$b];
							$this->placeLog($t, $x + Direction::STEP_X[$d], $y + $i, $z + Direction::STEP_Z[$d], self::axisModifier($t, self::axisOf($d)));
						}
					}
				}
				return [[$x, $y + $upToBranches, $z, 0, 0, false]];
		}
		return [];
	}

	/**
	 * Direction.allShuffled(random) without the vertical directions.
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private static function shuffledHorizontalDirections(FeatureRandom $random) : array{
		$list = [Direction::DOWN, Direction::UP, Direction::NORTH, Direction::SOUTH, Direction::WEST, Direction::EAST];
		for($i = 6; $i > 1; --$i){
			$swapTo = $random->nextBoundedInt($i);
			$tmp = $list[$i - 1];
			$list[$i - 1] = $list[$swapTo];
			$list[$swapTo] = $tmp;
		}
		$out = [];
		foreach($list as $d){
			if($d !== Direction::UP && $d !== Direction::DOWN){
				$out[] = $d;
			}
		}
		return $out;
	}

	/**
	 * @phpstan-return list<array{int, int, int, int, int, bool}>
	 */
	private function placeGiant(TreeFeature $t, int $treeHeight, int $x, int $y, int $z) : array{
		$t->placeBelowTrunkBlock($x, $y - 1, $z);
		$t->placeBelowTrunkBlock($x + 1, $y - 1, $z);
		$t->placeBelowTrunkBlock($x, $y - 1, $z + 1);
		$t->placeBelowTrunkBlock($x + 1, $y - 1, $z + 1);
		for($hh = 0; $hh < $treeHeight; ++$hh){
			$this->placeLogIfFree($t, $x, $y + $hh, $z);
			if($hh < $treeHeight - 1){
				$this->placeLogIfFree($t, $x + 1, $y + $hh, $z);
				$this->placeLogIfFree($t, $x + 1, $y + $hh, $z + 1);
				$this->placeLogIfFree($t, $x, $y + $hh, $z + 1);
			}
		}
		return [[$x, $y + $treeHeight, $z, 0, 0, true]];
	}

	/**
	 * @param int[][] $attachments
	 * @phpstan-param list<array{int, int, int, int, int, bool}> $attachments
	 */
	private function placeUpwardsBranch(TreeFeature $t, int $treeHeight, array &$attachments, int $logX, int $logZ, int $currentHeight, int $branchDir, int $branchPos, int $branchSteps) : void{
		$heightAlongBranch = $currentHeight + $branchPos;
		for($index = $branchPos; $index < $treeHeight && $branchSteps > 0; ++$index, --$branchSteps){
			if($index < 1){
				continue;
			}
			$placementHeight = $currentHeight + $index;
			$logX += Direction::STEP_X[$branchDir];
			$logZ += Direction::STEP_Z[$branchDir];
			$heightAlongBranch = $placementHeight;
			if($this->placeLog($t, $logX, $placementHeight, $logZ)){
				++$heightAlongBranch;
			}
			$attachments[] = [$logX, $placementHeight, $logZ, 0, 0, false];
		}
		if($heightAlongBranch - $currentHeight > 1){
			$attachments[] = [$logX, $heightAlongBranch, $logZ, 0, 0, false];
			$attachments[] = [$logX, $heightAlongBranch - 2, $logZ, 0, 0, false];
		}
	}

	/**
	 * @phpstan-return list<array{int, int, int, int, int, bool}>
	 */
	private function placeFancy(TreeFeature $t, int $treeHeight, int $x, int $y, int $z) : array{
		$random = $t->c->random;
		$height = $treeHeight + 2;
		$trunkHeight = (int) floor($height * 0.618);
		$t->placeBelowTrunkBlock($x, $y - 1, $z);
		$clustersPerY = min(1, (int) floor(1.382 + ((1.0 * $height) / 13.0) ** 2));
		$trunkTop = $y + $trunkHeight;
		$relativeY = $height - 5;
		$foliageCoords = [[$x, $y + $relativeY, $z, $trunkTop]];
		for(; $relativeY >= 0; --$relativeY){
			$treeShape = self::fancyTreeShape($height, $relativeY);
			if($treeShape < 0.0){
				continue;
			}
			for($i = 0; $i < $clustersPerY; ++$i){
				$radius = 1.0 * $treeShape * ($random->nextFloat() + 0.328);
				$angle = FloatMath::fround($random->nextFloat() * 2.0) * 3.141592653589793;
				$px = $radius * sin($angle) + 0.5;
				$pz = $radius * cos($angle) + 0.5;
				$sx = $x + (int) floor($px);
				$sy = $y + $relativeY - 1;
				$sz = $z + (int) floor($pz);
				if($this->makeLimb($t, $sx, $sy, $sz, $sx, $sy + 5, $sz, false)){
					$dx = $x - $sx;
					$dz = $z - $sz;
					$branchHeight = $sy - sqrt($dx * $dx + $dz * $dz) * 0.381;
					$branchTop = $branchHeight > $trunkTop ? $trunkTop : (int) $branchHeight;
					if($this->makeLimb($t, $x, $branchTop, $z, $sx, $sy, $sz, false)){
						$foliageCoords[] = [$sx, $sy, $sz, $branchTop];
					}
				}
			}
		}
		$this->makeLimb($t, $x, $y, $z, $x, $y + $trunkHeight, $z, true);
		foreach($foliageCoords as [$fx, $fy, $fz, $branchBase]){
			if(self::trimBranches($height, $branchBase - $y)){
				$this->makeLimb($t, $x, $branchBase, $z, $fx, $fy, $fz, true);
			}
		}
		$attachments = [];
		foreach($foliageCoords as [$fx, $fy, $fz, $branchBase]){
			if(self::trimBranches($height, $branchBase - $y)){
				$attachments[] = [$fx, $fy, $fz, 0, 0, false];
			}
		}
		return $attachments;
	}

	private static function trimBranches(int $height, int $localY) : bool{
		return $localY >= $height * 0.2;
	}

	private static function fancyTreeShape(int $height, int $y) : float{
		if(FloatMath::fround((float) $y) < FloatMath::fround($height * 0.30000001192092896)){
			return -1.0;
		}
		$radius = FloatMath::fround($height / 2.0);
		$adjacent = FloatMath::fround($radius - $y);
		$distance = FloatMath::fround(sqrt(FloatMath::fround(FloatMath::fround($radius * $radius) - FloatMath::fround($adjacent * $adjacent))));
		if($adjacent === 0.0){
			$distance = $radius;
		}elseif(abs($adjacent) >= $radius){
			return 0.0;
		}
		return FloatMath::fround($distance * 0.5);
	}

	private function makeLimb(TreeFeature $t, int $x0, int $y0, int $z0, int $x1, int $y1, int $z1, bool $doPlace) : bool{
		if(!$doPlace && $x0 === $x1 && $y0 === $y1 && $z0 === $z1){
			return true;
		}
		$dx = $x1 - $x0;
		$dy = $y1 - $y0;
		$dz = $z1 - $z0;
		$steps = max(abs($dx), abs($dy), abs($dz));
		if($steps === 0){
			//vanilla divides by zero (NaN offsets, floored to 0): only the start block
			if($doPlace){
				$this->placeLog($t, $x0, $y0, $z0, self::axisModifier($t, "y"));
				return true;
			}
			return $this->isFree($t, $x0, $y0, $z0);
		}
		$fx = FloatMath::fround($dx / $steps);
		$fy = FloatMath::fround($dy / $steps);
		$fz = FloatMath::fround($dz / $steps);
		for($i = 0; $i <= $steps; ++$i){
			$bx = $x0 + (int) floor(FloatMath::fround(0.5 + FloatMath::fround($i * $fx)));
			$by = $y0 + (int) floor(FloatMath::fround(0.5 + FloatMath::fround($i * $fy)));
			$bz = $z0 + (int) floor(FloatMath::fround(0.5 + FloatMath::fround($i * $fz)));
			if($doPlace){
				$xdiff = abs($bx - $x0);
				$zdiff = abs($bz - $z0);
				$maxdiff = max($xdiff, $zdiff);
				$axis = $maxdiff > 0 ? ($xdiff === $maxdiff ? "x" : "z") : "y";
				$this->placeLog($t, $bx, $by, $bz, self::axisModifier($t, $axis));
			}elseif(!$this->isFree($t, $bx, $by, $bz)){
				return false;
			}
		}
		return true;
	}

	/**
	 * @phpstan-return list<array{int, int, int, int, int, bool}>
	 */
	private function placeCherry(TreeFeature $t, int $treeHeight, int $x, int $y, int $z) : array{
		$random = $t->c->random;
		$t->placeBelowTrunkBlock($x, $y - 1, $z);
		$first = max(0, $treeHeight - 1 + ($this->branchStartOffsetFromTop?->sample($random) ?? 0));
		$second = max(0, $treeHeight - 1 + ($this->secondBranchStartOffsetFromTop?->sample($random) ?? 0));
		if($second >= $first){
			++$second;
		}
		$branchCount = $this->branchCount?->sample($random) ?? 0;
		$hasMiddle = $branchCount === 3;
		$hasBoth = $branchCount >= 2;
		$trunkHeight = $hasMiddle ? $treeHeight : ($hasBoth ? max($first, $second) + 1 : $first + 1);
		for($i = 0; $i < $trunkHeight; ++$i){
			$this->placeLog($t, $x, $y + $i, $z);
		}
		$attachments = [];
		if($hasMiddle){
			$attachments[] = [$x, $y + $trunkHeight, $z, 0, 0, false];
		}
		$direction = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
		$sideways = self::axisModifier($t, self::axisOf($direction));
		$attachments[] = $this->cherryBranch($t, $treeHeight, $x, $y, $z, $sideways, $direction, $first, $first < $trunkHeight - 1);
		if($hasBoth){
			$attachments[] = $this->cherryBranch($t, $treeHeight, $x, $y, $z, $sideways, Direction::OPPOSITE[$direction], $second, $second < $trunkHeight - 1);
		}
		return $attachments;
	}

	/**
	 * @phpstan-param \Closure(int) : int $sideways
	 * @phpstan-return array{int, int, int, int, int, bool}
	 */
	private function cherryBranch(TreeFeature $t, int $treeHeight, int $x, int $y, int $z, \Closure $sideways, int $direction, int $offset, bool $middleContinuesUpwards) : array{
		$random = $t->c->random;
		$lx = $x;
		$ly = $y + $offset;
		$lz = $z;
		$endOffset = $treeHeight - 1 + ($this->branchEndOffsetFromTop?->sample($random) ?? 0);
		$extend = $middleContinuesUpwards || $endOffset < $offset;
		$distanceToTrunk = ($this->branchHorizontalLength?->sample($random) ?? 0) + ($extend ? 1 : 0);
		$ex = $x + Direction::STEP_X[$direction] * $distanceToTrunk;
		$ey = $y + $endOffset;
		$ez = $z + Direction::STEP_Z[$direction] * $distanceToTrunk;
		$stepsHorizontally = $extend ? 2 : 1;
		for($i = 0; $i < $stepsHorizontally; ++$i){
			$lx += Direction::STEP_X[$direction];
			$lz += Direction::STEP_Z[$direction];
			$this->placeLog($t, $lx, $ly, $lz, $sideways);
		}
		$vertical = $ey > $ly ? 1 : -1;
		while(true){
			$distance = (int) FloatMath::fround(FloatMath::fround((float) abs($ex - $lx) + (float) abs($ey - $ly)) + (float) abs($ez - $lz));
			if($distance === 0){
				break;
			}
			$chance = FloatMath::fround(abs($ey - $ly) / $distance);
			$growVertically = $random->nextFloat() < $chance;
			if($growVertically){
				$ly += $vertical;
				$this->placeLog($t, $lx, $ly, $lz);
			}else{
				$lx += Direction::STEP_X[$direction];
				$lz += Direction::STEP_Z[$direction];
				$this->placeLog($t, $lx, $ly, $lz, $sideways);
			}
		}
		return [$ex, $ey + 1, $ez, 0, 0, false];
	}
}
