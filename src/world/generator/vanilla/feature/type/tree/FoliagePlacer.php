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
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function floor;
use function intdiv;
use function max;
use function min;

/**
 * Vanilla's foliage placers (Java 26.3): blob, spruce, pine, acacia, bush, fancy, jungle, mega_pine, dark_oak,
 * random_spread, cherry and poplar.
 */
final class FoliagePlacer{
	private const BLOB = 0;
	private const SPRUCE = 1;
	private const PINE = 2;
	private const ACACIA = 3;
	private const BUSH = 4;
	private const FANCY = 5;
	private const JUNGLE = 6;
	private const MEGA_PINE = 7;
	private const DARK_OAK = 8;
	private const RANDOM_SPREAD = 9;
	private const CHERRY = 10;
	private const POPLAR = 11;

	private int $type;
	private IntProvider $radius;
	private IntProvider $offset;
	private int $height = 0;
	private ?IntProvider $heightProvider = null;
	private int $leafPlacementAttempts = 0;
	private float $wideBottomLayerHoleChance = 0.0;
	private float $cornerHoleChance = 0.0;
	private float $hangingLeavesChance = 0.0;
	private float $hangingLeavesExtensionChance = 0.0;
	private float $sideHoleChance = 0.0;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(array $json){
		$type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		$this->type = match($type){
			"blob_foliage_placer" => self::BLOB,
			"spruce_foliage_placer" => self::SPRUCE,
			"pine_foliage_placer" => self::PINE,
			"acacia_foliage_placer" => self::ACACIA,
			"bush_foliage_placer" => self::BUSH,
			"fancy_foliage_placer" => self::FANCY,
			"jungle_foliage_placer" => self::JUNGLE,
			"mega_pine_foliage_placer" => self::MEGA_PINE,
			"dark_oak_foliage_placer" => self::DARK_OAK,
			"random_spread_foliage_placer" => self::RANDOM_SPREAD,
			"cherry_foliage_placer" => self::CHERRY,
			"poplar_foliage_placer" => self::POPLAR,
			default => throw new \RuntimeException("Unknown foliage placer $type")
		};
		$this->radius = Providers::int($json["radius"] ?? null);
		$this->offset = Providers::int($json["offset"] ?? null);
		switch($this->type){
			case self::BLOB:
			case self::BUSH:
			case self::FANCY:
			case self::JUNGLE:
				$this->height = WorldgenData::int($json, "height");
				break;
			case self::SPRUCE:
				$this->heightProvider = Providers::int($json["trunk_height"] ?? null);
				break;
			case self::PINE:
			case self::POPLAR:
			case self::CHERRY:
				$this->heightProvider = Providers::int($json["height"] ?? null);
				break;
			case self::MEGA_PINE:
				$this->heightProvider = Providers::int($json["crown_height"] ?? null);
				break;
			case self::RANDOM_SPREAD:
				$this->heightProvider = Providers::int($json["foliage_height"] ?? null);
				$this->leafPlacementAttempts = WorldgenData::int($json, "leaf_placement_attempts");
				break;
		}
		if($this->type === self::CHERRY){
			$this->wideBottomLayerHoleChance = FloatMath::fround(WorldgenData::float($json, "wide_bottom_layer_hole_chance"));
			$this->cornerHoleChance = FloatMath::fround(WorldgenData::float($json, "corner_hole_chance"));
			$this->hangingLeavesChance = FloatMath::fround(WorldgenData::float($json, "hanging_leaves_chance"));
			$this->hangingLeavesExtensionChance = FloatMath::fround(WorldgenData::float($json, "hanging_leaves_extension_chance"));
		}
		if($this->type === self::POPLAR){
			$this->sideHoleChance = FloatMath::fround(WorldgenData::float($json, "side_hole_chance"));
		}
	}

	public function foliageHeight(FeatureRandom $random, int $treeHeight) : int{
		return match($this->type){
			self::BLOB, self::BUSH, self::FANCY, self::JUNGLE => $this->height,
			self::ACACIA, self::DARK_OAK => $this->type === self::DARK_OAK ? 4 : 0,
			self::SPRUCE => max(4, $treeHeight - ($this->heightProvider?->sample($random) ?? 0)),
			default => $this->heightProvider?->sample($random) ?? 0
		};
	}

	public function foliageRadius(FeatureRandom $random, int $trunkHeight) : int{
		$radius = $this->radius->sample($random);
		if($this->type === self::PINE){
			return $radius + $random->nextBoundedInt(max($trunkHeight + 1, 1));
		}
		return $radius;
	}

	/**
	 * @param int[]|bool[] $a [x, y, z, radiusOffset, foliageHeightOffset, doubleTrunk]
	 * @phpstan-param array{int, int, int, int, int, bool} $a
	 */
	public function createFoliage(TreeFeature $t, int $treeHeight, array $a, int $foliageHeight, int $leafRadius) : void{
		$random = $t->c->random;
		$offset = $this->offset->sample($random);
		[$ax, $ay, $az, $radiusOffset, $heightOffset, $doubleTrunk] = $a;
		switch($this->type){
			case self::BLOB:
				$h = $foliageHeight + $heightOffset;
				for($yo = $offset; $yo >= $offset - $h; --$yo){
					$r = max($leafRadius + $radiusOffset - 1 - intdiv($yo, 2), 0);
					$this->placeLeavesRow($t, $ax, $ay, $az, $r, $yo, $doubleTrunk);
				}
				break;
			case self::BUSH:
				$h = $foliageHeight + $heightOffset;
				for($yo = $offset; $yo >= $offset - $h; --$yo){
					$this->placeLeavesRow($t, $ax, $ay, $az, $leafRadius + $radiusOffset - 1 - $yo, $yo, $doubleTrunk);
				}
				break;
			case self::FANCY:
				for($yo = $offset; $yo >= $offset - $foliageHeight; --$yo){
					$r = $leafRadius + ($yo === $offset || $yo === $offset - $foliageHeight ? 0 : 1);
					$this->placeLeavesRow($t, $ax, $ay, $az, $r, $yo, $doubleTrunk);
				}
				break;
			case self::ACACIA:
				$fy = $ay + $offset;
				$h = $foliageHeight + $heightOffset;
				$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius + $radiusOffset, -1 - $h, $doubleTrunk);
				$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius - 1, -$h, $doubleTrunk);
				$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius + $radiusOffset - 1, 0, $doubleTrunk);
				break;
			case self::DARK_OAK:
				$fy = $ay + $offset;
				if($doubleTrunk){
					$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius + 2, -1, $doubleTrunk);
					$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius + 3, 0, $doubleTrunk);
					$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius + 2, 1, $doubleTrunk);
					if($random->nextBoolean()){
						$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius, 2, $doubleTrunk);
					}
				}else{
					$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius + 2, -1, $doubleTrunk);
					$this->placeLeavesRow($t, $ax, $fy, $az, $leafRadius + 1, 0, $doubleTrunk);
				}
				break;
			case self::JUNGLE:
				$leafHeight = ($doubleTrunk ? $foliageHeight : 1 + $random->nextBoundedInt(2)) + $heightOffset;
				for($yo = $offset; $yo >= $offset - $leafHeight; --$yo){
					$this->placeLeavesRow($t, $ax, $ay, $az, $leafRadius + $radiusOffset + 1 - $yo, $yo, $doubleTrunk);
				}
				break;
			case self::MEGA_PINE:
				$prevRadius = 0;
				$h = $foliageHeight + $heightOffset;
				for($yy = $ay - $h + $offset; $yy <= $ay + $offset; ++$yy){
					$yo = $ay - $yy;
					$smooth = $leafRadius + $radiusOffset + (int) floor(FloatMath::fround(FloatMath::fround($yo / $h) * 3.5));
					$jagged = ($yo > 0 && $smooth === $prevRadius && ($yy & 1) === 0) ? $smooth + 1 : $smooth;
					$this->placeLeavesRow($t, $ax, $yy, $az, $jagged, 0, $doubleTrunk);
					$prevRadius = $smooth;
				}
				break;
			case self::PINE:
				$current = 0;
				$h = $foliageHeight + $heightOffset;
				for($yo = $offset; $yo >= $offset - $h; --$yo){
					$this->placeLeavesRow($t, $ax, $ay, $az, $current, $yo, $doubleTrunk);
					if($current >= 1 && $yo === $offset - $h + 1){
						--$current;
					}elseif($current < $leafRadius + $radiusOffset){
						++$current;
					}
				}
				break;
			case self::SPRUCE:
				$current = $random->nextBoundedInt(2);
				$maxRadius = 1;
				$minRadius = 0;
				$h = $foliageHeight + $heightOffset;
				for($yo = $offset; $yo >= -$h; --$yo){
					$this->placeLeavesRow($t, $ax, $ay, $az, $current, $yo, $doubleTrunk);
					if($current >= $maxRadius){
						$current = $minRadius;
						$minRadius = 1;
						$maxRadius = min($maxRadius + 1, $leafRadius + $radiusOffset);
					}else{
						++$current;
					}
				}
				break;
			case self::RANDOM_SPREAD:
				for($i = 0; $i < $this->leafPlacementAttempts; ++$i){
					$dx = $random->nextBoundedInt($leafRadius) - $random->nextBoundedInt($leafRadius);
					$dy = $random->nextBoundedInt($foliageHeight) - $random->nextBoundedInt($foliageHeight);
					$dz = $random->nextBoundedInt($leafRadius) - $random->nextBoundedInt($leafRadius);
					$t->tryPlaceLeaf($ax + $dx, $ay + $dy, $az + $dz);
				}
				break;
			case self::CHERRY:
				$fy = $ay + $offset;
				$r = $leafRadius + $radiusOffset - 1;
				$h = $foliageHeight + $heightOffset;
				$this->placeLeavesRow($t, $ax, $fy, $az, $r - 2, $h - 3, $doubleTrunk);
				$this->placeLeavesRow($t, $ax, $fy, $az, $r - 1, $h - 4, $doubleTrunk);
				for($y = $h - 5; $y >= 0; --$y){
					$this->placeLeavesRow($t, $ax, $fy, $az, $r, $y, $doubleTrunk);
				}
				$this->placeLeavesRowWithHangingLeavesBelow($t, $ax, $fy, $az, $r, -1, $doubleTrunk, $this->hangingLeavesChance, $this->hangingLeavesExtensionChance);
				$this->placeLeavesRowWithHangingLeavesBelow($t, $ax, $fy, $az, $r - 1, -2, $doubleTrunk, $this->hangingLeavesChance, $this->hangingLeavesExtensionChance);
				break;
			case self::POPLAR:
				$this->createPoplar($t, $ax, $ay + $offset, $az, $leafRadius + $radiusOffset - 1, $foliageHeight + $heightOffset, $doubleTrunk);
				break;
		}
	}

	private function placeLeavesRow(TreeFeature $t, int $ox, int $oy, int $oz, int $radius, int $y, bool $doubleTrunk) : void{
		$extra = $doubleTrunk ? 1 : 0;
		for($dx = -$radius; $dx <= $radius + $extra; ++$dx){
			for($dz = -$radius; $dz <= $radius + $extra; ++$dz){
				if(!$this->shouldSkipLocationSigned($t->c->random, $dx, $y, $dz, $radius, $doubleTrunk)){
					$t->tryPlaceLeaf($ox + $dx, $oy + $y, $oz + $dz);
				}
			}
		}
	}

	private function shouldSkipLocationSigned(FeatureRandom $random, int $dx, int $y, int $dz, int $radius, bool $doubleTrunk) : bool{
		if($this->type === self::DARK_OAK && $y === 0 && $doubleTrunk && ($dx === -$radius || $dx >= $radius) && ($dz === -$radius || $dz >= $radius)){
			return true;
		}
		if($doubleTrunk){
			$mx = min(abs($dx), abs($dx - 1));
			$mz = min(abs($dz), abs($dz - 1));
		}else{
			$mx = abs($dx);
			$mz = abs($dz);
		}
		return $this->shouldSkipLocation($random, $mx, $y, $mz, $radius, $doubleTrunk);
	}

	private function shouldSkipLocation(FeatureRandom $random, int $dx, int $y, int $dz, int $radius, bool $doubleTrunk) : bool{
		switch($this->type){
			case self::BLOB:
				return $dx === $radius && $dz === $radius && ($random->nextBoundedInt(2) === 0 || $y === 0);
			case self::BUSH:
				return $dx === $radius && $dz === $radius && $random->nextBoundedInt(2) === 0;
			case self::FANCY:
				$fx = FloatMath::fround($dx + 0.5);
				$fz = FloatMath::fround($dz + 0.5);
				return FloatMath::fround(FloatMath::fround($fx * $fx) + FloatMath::fround($fz * $fz)) > (float) ($radius * $radius);
			case self::ACACIA:
				if($y === 0){
					return ($dx > 1 || $dz > 1) && $dx !== 0 && $dz !== 0;
				}
				return $dx === $radius && $dz === $radius && $radius > 0;
			case self::DARK_OAK:
				if($y === -1 && !$doubleTrunk){
					return $dx === $radius && $dz === $radius;
				}
				if($y === 1){
					return $dx + $dz > $radius * 2 - 2;
				}
				return false;
			case self::JUNGLE:
			case self::MEGA_PINE:
				if($dx + $dz >= 7){
					return true;
				}
				return $dx * $dx + $dz * $dz > $radius * $radius;
			case self::PINE:
			case self::SPRUCE:
				return $dx === $radius && $dz === $radius && $radius > 0;
			case self::CHERRY:
				if($y === -1 && ($dx === $radius || $dz === $radius) && $random->nextFloat() < $this->wideBottomLayerHoleChance){
					return true;
				}
				$corner = $dx === $radius && $dz === $radius;
				if($radius > 2){
					return $corner || ($dx + $dz > $radius * 2 - 2 && $random->nextFloat() < $this->cornerHoleChance);
				}
				return $corner && $random->nextFloat() < $this->cornerHoleChance;
			default:
				return false;
		}
	}

	private function placeLeavesRowWithHangingLeavesBelow(TreeFeature $t, int $ox, int $oy, int $oz, int $radius, int $y, bool $doubleTrunk, float $hangingChance, float $extensionChance) : void{
		$this->placeLeavesRow($t, $ox, $oy, $oz, $radius, $y, $doubleTrunk);
		$extra = $doubleTrunk ? 1 : 0;
		$logX = $ox;
		$logY = $oy - 1;
		$logZ = $oz;
		foreach(Direction::PLANE_HORIZONTAL as $alongEdge){
			$toEdge = Direction::clockWise($alongEdge);
			$offsetToEdge = (Direction::STEP_X[$toEdge] + Direction::STEP_Z[$toEdge]) > 0 ? $radius + $extra : $radius;
			$px = $ox + Direction::STEP_X[$toEdge] * $offsetToEdge - Direction::STEP_X[$alongEdge] * $radius;
			$py = $oy + $y - 1;
			$pz = $oz + Direction::STEP_Z[$toEdge] * $offsetToEdge - Direction::STEP_Z[$alongEdge] * $radius;
			for($along = -$radius; $along < $radius + $extra; ++$along){
				$leavesAbove = $t->isFoliageSet($px, $py + 1, $pz);
				if($leavesAbove && $this->tryPlaceExtension($t, $hangingChance, $logX, $logY, $logZ, $px, $py, $pz)){
					$this->tryPlaceExtension($t, $extensionChance, $logX, $logY, $logZ, $px, $py - 1, $pz);
				}
				$px += Direction::STEP_X[$alongEdge];
				$pz += Direction::STEP_Z[$alongEdge];
			}
		}
	}

	private function tryPlaceExtension(TreeFeature $t, float $chance, int $logX, int $logY, int $logZ, int $x, int $y, int $z) : bool{
		$distance = (int) FloatMath::fround(FloatMath::fround((float) abs($logX - $x) + (float) abs($logY - $y)) + (float) abs($logZ - $z));
		if($distance >= 7){
			return false;
		}
		if($t->c->random->nextFloat() > $chance){
			return false;
		}
		return $t->tryPlaceLeaf($x, $y, $z);
	}

	private function createPoplar(TreeFeature $t, int $ox, int $oy, int $oz, int $radius, int $foliageHeight, bool $doubleTrunk) : void{
		$random = $t->c->random;
		$flip = $random->nextBoolean();
		$this->poplarRow($t, $ox, $oy, $oz, $radius - 2, $foliageHeight - 1, $doubleTrunk, $foliageHeight, $flip);
		$this->poplarRow($t, $ox, $oy, $oz, $radius - 1, $foliageHeight - 2, $doubleTrunk, $foliageHeight, $flip);
		$this->poplarRow($t, $ox, $oy, $oz, $radius - 1, $foliageHeight - 3, $doubleTrunk, $foliageHeight, $flip);
		for($y = $foliageHeight - 4; $y >= 1; --$y){
			$this->poplarRow($t, $ox, $oy, $oz, $radius, $y, $doubleTrunk, $foliageHeight, $flip);
		}
		$this->poplarLogs($t, $ox, $oy, $oz, $radius, $foliageHeight - 4, $doubleTrunk, $foliageHeight, $flip);
		$this->poplarRow($t, $ox, $oy, $oz, $radius - 1, 0, $doubleTrunk, $foliageHeight, $flip);
		$this->poplarRow($t, $ox, $oy, $oz, max(1, min(2, $radius - 2)), -1, $doubleTrunk, $foliageHeight, $flip);
	}

	private static function poplarPartialRow(int $foliageHeight, int $y) : bool{
		return $foliageHeight - 1 === $y || $foliageHeight - 2 === $y;
	}

	private static function poplarCornerCut(int $dx, int $dz, int $radius, bool $partial, bool $flip) : int{
		$small = $flip ? (($dx > 0 && $dz > 0) || ($dz < 0 && $dx < 0)) : (($dx > 0 && $dz < 0) || ($dz > 0 && $dx < 0));
		if($small){
			return $radius - 1;
		}
		return $partial ? $radius + 1 : $radius;
	}

	private static function withinRhombus(int $radius, int $absDx, int $absDz, int $cornerCut, int $additional) : bool{
		return $absDx + $absDz <= $radius * 2 - ($cornerCut + $additional);
	}

	private function poplarRow(TreeFeature $t, int $ox, int $oy, int $oz, int $radius, int $y, bool $doubleTrunk, int $foliageHeight, bool $flip) : void{
		$extra = $doubleTrunk ? 1 : 0;
		$random = $t->c->random;
		for($dx = -$radius; $dx <= $radius + $extra; ++$dx){
			for($dz = -$radius; $dz <= $radius + $extra; ++$dz){
				$partial = self::poplarPartialRow($foliageHeight, $y);
				$cut = self::poplarCornerCut($dx, $dz, $radius, $partial, $flip);
				$absDx = abs($dx);
				$absDz = abs($dz);
				$edge = $absDx === $radius || $absDz === $radius;
				if($partial && $edge){
					continue;
				}
				$additional = $random->nextFloat() <= $this->sideHoleChance ? 1 : 0;
				if(!self::withinRhombus($radius, $absDx, $absDz, $cut, $additional)){
					continue;
				}
				$t->tryPlaceLeaf($ox + $dx, $oy + $y, $oz + $dz);
			}
		}
	}

	private function poplarLogs(TreeFeature $t, int $ox, int $oy, int $oz, int $radius, int $y, bool $doubleTrunk, int $foliageHeight, bool $flip) : void{
		$extra = $doubleTrunk ? 1 : 0;
		$blocks = $t->blocks;
		$level = $t->c->level;
		for($dx = -$radius; $dx <= $radius + $extra; ++$dx){
			for($dz = -$radius; $dz <= $radius + $extra; ++$dz){
				$absDz = abs($dz);
				$absDx = abs($dx);
				if(!self::withinRhombus($radius, $absDx, $absDz, self::poplarCornerCut($dx, $dz, $radius, self::poplarPartialRow($foliageHeight, $y), $flip), 2)){
					continue;
				}
				if(!(($absDz === 0 && $radius - $absDx >= 4) || ($absDx === 0 && $radius - $absDz >= 4))){
					continue;
				}
				$x = $ox + $dx;
				$py = $oy + $y;
				$z = $oz + $dz;
				//PoplarFoliagePlacer.tryPlaceLog(): only where the foliage provider's state is (getState() consumes randoms)
				$current = $level->getState($x, $py, $z);
				if($current !== $t->foliageState($x, $py, $z)){
					continue;
				}
				$axis = $absDz === 0 ? "x" : "z";
				$state = $blocks->with($t->trunkState($x, $py, $z), "axis", $axis);
				$t->setFoliage($x, $py, $z, $state);
			}
		}
	}
}
