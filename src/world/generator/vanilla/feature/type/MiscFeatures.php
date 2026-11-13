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
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\PlacedFeature;
use pocketmine\world\generator\vanilla\feature\Predicates;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function ceil;
use function intdiv;
use function is_array;

/**
 * Small vanilla features: underwater_magma, freeze_top_layer, blue_ice, spike (ice spikes), vines, block_blob
 * (forest rocks) and overlay.
 */
final class MiscFeatures{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param mixed[] $json
	 */
	public static function underwaterMagma(FeatureRegistry $registry, array $json) : Feature{
		$range = WorldgenData::int($json, "floor_search_range");
		$radius = WorldgenData::int($json, "placement_radius_around_floor");
		$chance = FloatMath::fround(WorldgenData::float($json, "placement_probability_per_valid_position"));
		$magma = $registry->blocks->defaultOf("magma_block");
		return new class($range, $radius, $chance, $magma) implements Feature{
			public function __construct(private int $range, private int $radius, private float $chance, private int $magma){}

			private function isWater(FeatureContext $c, int $x, int $y, int $z) : bool{
				return $c->blocks->blockOf[$c->level->getState($x, $y, $z)] === $c->blocks->waterBlock;
			}

			private function visible(FeatureContext $c, int $x, int $y, int $z) : bool{
				return !$c->survival->isSolidRender($c->level->getState($x, $y, $z));
			}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				//Column.scan() downwards in the water column
				if(!$this->isWater($c, $x, $y, $z)){
					return false;
				}
				$fy = $y;
				for($i = 1; $i < $this->range && $this->isWater($c, $x, $fy, $z); ++$i){
					--$fy;
				}
				if($this->isWater($c, $x, $fy, $z)){
					return false;
				}
				$placed = 0;
				$r = $this->radius;
				for($pz = $z - $r; $pz <= $z + $r; ++$pz){
					for($py = $fy - $r; $py <= $fy + $r; ++$py){
						for($px = $x - $r; $px <= $x + $r; ++$px){
							if(!($c->random->nextFloat() < $this->chance)){
								continue;
							}
							$state = $c->level->getState($px, $py, $pz);
							if($c->blocks->isAir($state) || $c->blocks->blockOf[$state] === $c->blocks->waterBlock || $this->visible($c, $px, $py - 1, $pz)){
								continue;
							}
							$ok = true;
							foreach(Direction::PLANE_HORIZONTAL as $d){
								if($this->visible($c, $px + Direction::STEP_X[$d], $py, $pz + Direction::STEP_Z[$d])){
									$ok = false;
									break;
								}
							}
							if($ok){
								$c->level->setState($px, $py, $pz, $this->magma);
								++$placed;
							}
						}
					}
				}
				return $placed > 0;
			}
		};
	}

	public static function freezeTopLayer(FeatureRegistry $registry) : Feature{
		$ice = $registry->blocks->defaultOf("ice");
		$snow = $registry->blocks->defaultOf("snow");
		return new class($ice, $snow) implements Feature{
			public function __construct(private int $ice, private int $snow){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$climate = $c->registry->climate();
				$level = $c->level;
				$blocks = $c->blocks;
				for($dx = 0; $dx < 16; ++$dx){
					for($dz = 0; $dz < 16; ++$dz){
						$px = $x + $dx;
						$pz = $z + $dz;
						$py = $level->getHeight(FeatureLevel::MOTION_BLOCKING, $px, $pz);
						if($climate->shouldFreeze($c, $px, $py - 1, $pz, false, $level->getBiome($px, $py, $pz))){
							$level->setState($px, $py - 1, $pz, $this->ice);
						}
						if($climate->shouldSnow($c, $px, $py, $pz, $level->getBiome($px, $py, $pz))){
							$level->setState($px, $py, $pz, $this->snow);
							$below = $level->getState($px, $py - 1, $pz);
							if($blocks->hasProperty($below, "snowy")){
								$level->setState($px, $py - 1, $pz, $blocks->withBool($below, "snowy", true));
							}
						}
					}
				}
				return true;
			}
		};
	}

	/**
	 * minecraft:block_pile (BlockPileFeature): a small rounded pile (hay, melons, pumpkins, snow, ice in villages).
	 * @param mixed[] $json
	 */
	public static function blockPile(FeatureRegistry $registry, array $json) : Feature{
		$provider = $registry->stateProvider($json["state_provider"] ?? null);
		$dirtPath = $registry->blocks->hasBlock("dirt_path") ? $registry->blocks->blockIndex("dirt_path") : -1;
		return new class($provider, $dirtPath) implements Feature{
			/** @phpstan-param \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int $provider */
			public function __construct(private \Closure $provider, private int $dirtPath){}

			private function tryPlace(FeatureContext $c, int $x, int $y, int $z) : void{
				$level = $c->level;
				$blocks = $c->blocks;
				if(!$blocks->isAir($level->getState($x, $y, $z))){
					return;
				}
				$below = $level->getState($x, $y - 1, $z);
				$mayPlace = $blocks->blockOf[$below] === $this->dirtPath ? $c->random->nextBoolean() : $blocks->isFaceSturdy($below, Direction::UP);
				if($mayPlace){
					$state = ($this->provider)($c, $c->random, $x, $y, $z);
					if($state !== null){
						$level->setState($x, $y, $z, $state);
					}
				}
			}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				if($y < $c->level->minY + 5){
					return false;
				}
				$random = $c->random;
				$i = 2 + $random->nextBoundedInt(2);
				$j = 2 + $random->nextBoundedInt(2);
				//BlockPos.betweenClosed(): x fastest, then y, then z
				for($pz = $z - $j; $pz <= $z + $j; ++$pz){
					for($py = $y; $py <= $y + 1; ++$py){
						for($px = $x - $i; $px <= $x + $i; ++$px){
							$dx = $x - $px;
							$dz = $z - $pz;
							if($dx * $dx + $dz * $dz <= $random->nextFloat() * 10.0 - $random->nextFloat() * 6.0){
								$this->tryPlace($c, $px, $py, $pz);
							}elseif($random->nextFloat() < 0.031){
								$this->tryPlace($c, $px, $py, $pz);
							}
						}
					}
				}
				return true;
			}
		};
	}

	public static function blueIce(FeatureRegistry $registry) : Feature{
		$blocks = $registry->blocks;
		$blueIce = $blocks->defaultOf("blue_ice");
		$packedIce = $blocks->blockIndex("packed_ice");
		$iceBlock = $blocks->blockIndex("ice");
		$blueIceBlock = $blocks->blockIndex("blue_ice");
		return new class($blueIce, $packedIce, $iceBlock, $blueIceBlock) implements Feature{
			public function __construct(private int $blueIce, private int $packedIce, private int $iceBlock, private int $blueIceBlock){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$blocks = $c->blocks;
				$random = $c->random;
				if($y > $c->seaLevel - 1){
					return false;
				}
				if($blocks->blockOf[$level->getState($x, $y, $z)] !== $blocks->waterBlock && $blocks->blockOf[$level->getState($x, $y - 1, $z)] !== $blocks->waterBlock){
					return false;
				}
				$found = false;
				for($d = 1; $d < 6; ++$d){
					if($blocks->blockOf[$level->getState($x + Direction::STEP_X[$d], $y + Direction::STEP_Y[$d], $z + Direction::STEP_Z[$d])] === $this->packedIce){
						$found = true;
						break;
					}
				}
				if(!$found){
					return false;
				}
				$level->setState($x, $y, $z, $this->blueIce);
				for($i = 0; $i < 200; ++$i){
					$yOff = $random->nextBoundedInt(5) - $random->nextBoundedInt(6);
					$xzDiff = 3;
					if($yOff < 2){
						$xzDiff += intdiv($yOff, 2);
					}
					if($xzDiff < 1){
						continue;
					}
					$px = $x + $random->nextBoundedInt($xzDiff) - $random->nextBoundedInt($xzDiff);
					$py = $y + $yOff;
					$pz = $z + $random->nextBoundedInt($xzDiff) - $random->nextBoundedInt($xzDiff);
					$state = $level->getState($px, $py, $pz);
					$block = $blocks->blockOf[$state];
					if(!$blocks->isAir($state) && $block !== $blocks->waterBlock && $block !== $this->packedIce && $block !== $this->iceBlock){
						continue;
					}
					for($d = 0; $d < 6; ++$d){
						if($blocks->blockOf[$level->getState($px + Direction::STEP_X[$d], $py + Direction::STEP_Y[$d], $pz + Direction::STEP_Z[$d])] === $this->blueIceBlock){
							$level->setState($px, $py, $pz, $this->blueIce);
							break;
						}
					}
				}
				return true;
			}
		};
	}

	/**
	 * @param mixed[] $json
	 */
	public static function spike(FeatureRegistry $registry, array $json) : Feature{
		$state = $registry->blocks->stateFromJson($json["state"] ?? null);
		$canPlaceOn = Predicates::block($registry->blocks, $json["can_place_on"] ?? null);
		$canReplace = Predicates::block($registry->blocks, $json["can_replace"] ?? null);
		return new class($state, $canPlaceOn, $canReplace) implements Feature{
			/**
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $canPlaceOn
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $canReplace
			 */
			public function __construct(private int $state, private \Closure $canPlaceOn, private \Closure $canReplace){}

			private function replaceable(FeatureContext $c, int $x, int $y, int $z) : bool{
				return $c->blocks->isAir($c->level->getState($x, $y, $z)) || ($this->canReplace)($c, $x, $y, $z);
			}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$random = $c->random;
				while($level->isEmptyBlock($x, $y, $z) && $y > $level->minY + 2){
					--$y;
				}
				if(!($this->canPlaceOn)($c, $x, $y, $z)){
					return false;
				}
				$y += $random->nextBoundedInt(4);
				$height = $random->nextBoundedInt(4) + 7;
				$width = intdiv($height, 4) + $random->nextBoundedInt(2);
				if($width > 1 && $random->nextBoundedInt(60) === 0){
					$y += 10 + $random->nextBoundedInt(30);
				}
				for($yOff = 0; $yOff < $height; ++$yOff){
					$scale = FloatMath::fround(FloatMath::fround(1.0 - FloatMath::fround($yOff / $height)) * $width);
					$newWidth = (int) ceil($scale);
					for($xo = -$newWidth; $xo <= $newWidth; ++$xo){
						$dx = FloatMath::fround(abs($xo) - 0.25);
						for($zo = -$newWidth; $zo <= $newWidth; ++$zo){
							$dz = FloatMath::fround(abs($zo) - 0.25);
							if(($xo !== 0 || $zo !== 0) && FloatMath::fround(FloatMath::fround($dx * $dx) + FloatMath::fround($dz * $dz)) > FloatMath::fround($scale * $scale)){
								continue;
							}
							if(($xo === -$newWidth || $xo === $newWidth || $zo === -$newWidth || $zo === $newWidth) && $random->nextFloat() > 0.75){
								continue;
							}
							if($this->replaceable($c, $x + $xo, $y + $yOff, $z + $zo)){
								$level->setState($x + $xo, $y + $yOff, $z + $zo, $this->state);
							}
							if($yOff !== 0 && $newWidth > 1 && $this->replaceable($c, $x + $xo, $y - $yOff, $z + $zo)){
								$level->setState($x + $xo, $y - $yOff, $z + $zo, $this->state);
							}
						}
					}
				}
				$pillarWidth = $width - 1;
				if($pillarWidth < 0){
					$pillarWidth = 0;
				}elseif($pillarWidth > 1){
					$pillarWidth = 1;
				}
				for($xo = -$pillarWidth; $xo <= $pillarWidth; ++$xo){
					for($zo = -$pillarWidth; $zo <= $pillarWidth; ++$zo){
						$cy = $y - 1;
						$runLength = 50;
						if(abs($xo) === 1 && abs($zo) === 1){
							$runLength = $random->nextBoundedInt(5);
						}
						while($cy > 50){
							$current = $level->getState($x + $xo, $cy, $z + $zo);
							if(!$c->blocks->isAir($current) && !($this->canReplace)($c, $x + $xo, $cy, $z + $zo) && $current !== $this->state){
								break;
							}
							$level->setState($x + $xo, $cy, $z + $zo, $this->state);
							--$cy;
							if(--$runLength <= 0){
								$cy -= $random->nextBoundedInt(5) + 1;
								$runLength = $random->nextBoundedInt(5);
							}
						}
					}
				}
				return true;
			}
		};
	}

	public static function vines(FeatureRegistry $registry) : Feature{
		$vine = $registry->blocks->defaultOf("vine");
		return new class($vine) implements Feature{
			public function __construct(private int $vine){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				if(!$c->level->isEmptyBlock($x, $y, $z)){
					return false;
				}
				for($d = 1; $d < 6; ++$d){
					if($c->survival->canAttachTo($c->level, $x, $y, $z, $d)){
						$c->level->setState($x, $y, $z, $c->blocks->withBool($this->vine, Direction::NAMES[$d], true));
						return true;
					}
				}
				return false;
			}
		};
	}

	/**
	 * @param mixed[] $json
	 */
	public static function blockBlob(FeatureRegistry $registry, array $json) : Feature{
		$state = $registry->blocks->stateFromJson($json["state"] ?? null);
		$canPlaceOn = Predicates::block($registry->blocks, $json["can_place_on"] ?? null);
		return new class($state, $canPlaceOn) implements Feature{
			/**
			 * @phpstan-param \Closure(FeatureContext, int, int, int) : bool $canPlaceOn
			 */
			public function __construct(private int $state, private \Closure $canPlaceOn){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$random = $c->random;
				while($y > $level->minY + 3 && !($this->canPlaceOn)($c, $x, $y - 1, $z)){
					--$y;
				}
				if($y <= $level->minY + 3){
					return false;
				}
				for($n = 0; $n < 3; ++$n){
					$xr = $random->nextBoundedInt(2);
					$yr = $random->nextBoundedInt(2);
					$zr = $random->nextBoundedInt(2);
					$tr = FloatMath::fround(FloatMath::fround(($xr + $yr + $zr) * 0.3330000042915344) + 0.5);
					for($pz = $z - $zr; $pz <= $z + $zr; ++$pz){
						for($py = $y - $yr; $py <= $y + $yr; ++$py){
							for($px = $x - $xr; $px <= $x + $xr; ++$px){
								$dx = $px - $x;
								$dy = $py - $y;
								$dz = $pz - $z;
								if((float) ($dx * $dx + $dy * $dy + $dz * $dz) <= FloatMath::fround($tr * $tr)){
									$level->setState($px, $py, $pz, $this->state);
								}
							}
						}
					}
					$x += -1 + $random->nextBoundedInt(2);
					$y += -$random->nextBoundedInt(2);
					$z += -1 + $random->nextBoundedInt(2);
				}
				return true;
			}
		};
	}

	/**
	 * @param mixed[] $json
	 */
	public static function overlay(FeatureRegistry $registry, array $json) : Feature{
		$features = [];
		$list = $json["features"] ?? null;
		foreach(is_array($list) ? $list : [$list] as $entry){
			$features[] = $registry->placedRef($entry);
		}
		return new class($features) implements Feature{
			/**
			 * @param PlacedFeature[] $features
			 */
			public function __construct(private array $features){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$placed = false;
				foreach($this->features as $feature){
					$placed = $feature->place($c, $x, $y, $z) || $placed;
				}
				return $placed;
			}
		};
	}
}
