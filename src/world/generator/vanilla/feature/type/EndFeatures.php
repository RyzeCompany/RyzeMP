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

use pocketmine\world\generator\vanilla\EndSpike;
use pocketmine\world\generator\vanilla\EndSpikes;
use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function ceil;
use function floor;
use function is_array;

/**
 * Feature types of the End: end_spike (the obsidian spikes; the End crystals are spawned by the dragon fight, see
 * DragonFight), end_platform (the obsidian arrival platform), end_gateway (return gateways of the outer islands),
 * chorus_plant and end_island (small floating islands).
 */
final class EndFeatures{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param mixed[] $json
	 */
	public static function spike(FeatureRegistry $registry, array $json) : Feature{
		$blocks = $registry->blocks;
		$spikes = [];
		foreach(is_array($json["spikes"] ?? null) ? $json["spikes"] : [] as $spike){
			if(is_array($spike)){
				$spikes[] = new EndSpike(
					WorldgenData::int($spike, "centerX", 0),
					WorldgenData::int($spike, "centerZ", 0),
					WorldgenData::int($spike, "radius", 0),
					WorldgenData::int($spike, "height", 0),
					($spike["guarded"] ?? false) === true
				);
			}
		}
		return new class(
			$spikes,
			$blocks->defaultOf("obsidian"),
			$blocks->defaultOf("iron_bars"),
			$blocks->defaultOf("bedrock"),
			$blocks->defaultOf("fire")
		) implements Feature{
			/**
			 * @param EndSpike[] $spikes
			 * @phpstan-param list<EndSpike> $spikes
			 */
			public function __construct(
				private array $spikes,
				private int $obsidian,
				private int $ironBars,
				private int $bedrock,
				private int $fire
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$spikes = $this->spikes !== [] ? $this->spikes : EndSpikes::forSeed($c->seed);
				foreach($spikes as $spike){
					if($spike->isCenterWithinChunk($x >> 4, $z >> 4)){
						$this->placeSpike($c, $spike);
					}
				}
				return true;
			}

			private function placeSpike(FeatureContext $c, EndSpike $spike) : void{
				$level = $c->level;
				$blocks = $c->blocks;
				$radius = $spike->radius;
				$cx = $spike->centerX;
				$cz = $spike->centerZ;
				$limit = $radius * $radius + 1;
				for($z = $cz - $radius; $z <= $cz + $radius; ++$z){
					for($y = $level->minY; $y <= $spike->height + 10; ++$y){
						for($x = $cx - $radius; $x <= $cx + $radius; ++$x){
							$dx = $x - $cx;
							$dz = $z - $cz;
							if($dx * $dx + $dz * $dz <= $limit && $y < $spike->height){
								$level->setState($x, $y, $z, $this->obsidian);
							}elseif($y > 65){
								$level->setState($x, $y, $z, $blocks->air);
							}
						}
					}
				}
				if($spike->guarded){
					for($dx = -2; $dx <= 2; ++$dx){
						for($dz = -2; $dz <= 2; ++$dz){
							for($dy = 0; $dy <= 3; ++$dy){
								$edgeX = abs($dx) === 2;
								$edgeZ = abs($dz) === 2;
								$top = $dy === 3;
								if(!$edgeX && !$edgeZ && !$top){
									continue;
								}
								$north = $edgeX || $top;
								$east = $edgeZ || $top;
								$state = $blocks->withBool($this->ironBars, "north", $north && $dz !== -2);
								$state = $blocks->withBool($state, "south", $north && $dz !== 2);
								$state = $blocks->withBool($state, "west", $east && $dx !== -2);
								$state = $blocks->withBool($state, "east", $east && $dx !== 2);
								$level->setState($cx + $dx, $spike->height + $dy, $cz + $dz, $state);
							}
						}
					}
				}
				//the End crystal: its rotation is random; it sits on bedrock, in fire
				$c->random->nextFloat();
				$level->setState($cx, $spike->height, $cz, $this->bedrock);
				$level->setState($cx, $spike->height + 1, $cz, $this->fire);
			}
		};
	}

	public static function platform(FeatureRegistry $registry) : Feature{
		$blocks = $registry->blocks;
		return new class($blocks->defaultOf("obsidian")) implements Feature{
			public function __construct(private int $obsidian){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$blocks = $c->blocks;
				for($dz = -2; $dz <= 2; ++$dz){
					for($dx = -2; $dx <= 2; ++$dx){
						for($dy = -1; $dy < 3; ++$dy){
							$state = $dy === -1 ? $this->obsidian : $blocks->air;
							if($blocks->blockOf[$level->getState($x + $dx, $y + $dy, $z + $dz)] !== $blocks->blockOf[$state]){
								$level->setState($x + $dx, $y + $dy, $z + $dz, $state);
							}
						}
					}
				}
				return true;
			}
		};
	}

	/**
	 * End gateway with its bedrock frame. The exit of the gateway (block entity data) is not stored: the generator
	 * writes no block entities; gateways without one far from the main island lead to the End spawn platform like
	 * vanilla's end_gateway_return (see EndGatewayTravel).
	 */
	public static function gateway(FeatureRegistry $registry) : Feature{
		$blocks = $registry->blocks;
		return new class($blocks->defaultOf("end_gateway"), $blocks->defaultOf("bedrock")) implements Feature{
			public function __construct(private int $gateway, private int $bedrock){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				$air = $c->blocks->air;
				for($pz = $z - 1; $pz <= $z + 1; ++$pz){
					for($py = $y - 2; $py <= $y + 2; ++$py){
						for($px = $x - 1; $px <= $x + 1; ++$px){
							$sameX = $px === $x;
							$sameY = $py === $y;
							$sameZ = $pz === $z;
							$edgeY = abs($py - $y) === 2;
							if($sameX && $sameY && $sameZ){
								$level->setState($px, $py, $pz, $this->gateway);
							}elseif($sameY){
								$level->setState($px, $py, $pz, $air);
							}elseif($edgeY && $sameX && $sameZ){
								$level->setState($px, $py, $pz, $this->bedrock);
							}elseif(($sameX || $sameZ) && !$edgeY){
								$level->setState($px, $py, $pz, $this->bedrock);
							}else{
								$level->setState($px, $py, $pz, $air);
							}
						}
					}
				}
				return true;
			}
		};
	}

	public static function chorusPlant(FeatureRegistry $registry) : Feature{
		$blocks = $registry->blocks;
		return new class(
			$blocks->defaultOf("chorus_plant"),
			$blocks->withInt($blocks->defaultOf("chorus_flower"), "age", 5),
			$blocks->blockIndex("chorus_plant"),
			$blocks->blockIndex("chorus_flower"),
			$blocks->blockIndex("end_stone")
		) implements Feature{
			private const MAX_HORIZONTAL_SPREAD = 8;

			public function __construct(
				private int $plant,
				private int $flower,
				private int $plantBlock,
				private int $flowerBlock,
				private int $endStoneBlock
			){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$level = $c->level;
				if(!$level->isEmptyBlock($x, $y, $z) || $c->blocks->blockOf[$level->getState($x, $y - 1, $z)] !== $this->endStoneBlock){
					return false;
				}
				//ChorusFlowerBlock.generatePlant()
				$this->setPlant($c, $x, $y, $z);
				$this->growTreeRecursive($c, $x, $y, $z, $x, $z, 0);
				return true;
			}

			/**
			 * ChorusPlantBlock.getStateWithConnections()
			 */
			private function setPlant(FeatureContext $c, int $x, int $y, int $z) : void{
				$level = $c->level;
				$blocks = $c->blocks;
				$blockOf = $blocks->blockOf;
				$connects = fn(int $dx, int $dy, int $dz) : bool => ($b = $blockOf[$level->getState($x + $dx, $y + $dy, $z + $dz)]) === $this->plantBlock || $b === $this->flowerBlock;
				$down = $blockOf[$level->getState($x, $y - 1, $z)];
				$state = $blocks->withBool($this->plant, "down", $down === $this->plantBlock || $down === $this->flowerBlock || $down === $this->endStoneBlock);
				$state = $blocks->withBool($state, "up", $connects(0, 1, 0));
				$state = $blocks->withBool($state, "north", $connects(0, 0, -1));
				$state = $blocks->withBool($state, "east", $connects(1, 0, 0));
				$state = $blocks->withBool($state, "south", $connects(0, 0, 1));
				$state = $blocks->withBool($state, "west", $connects(-1, 0, 0));
				$level->setState($x, $y, $z, $state);
			}

			private function allNeighborsEmpty(FeatureContext $c, int $x, int $y, int $z, ?int $ignore) : bool{
				foreach(Direction::PLANE_HORIZONTAL as $d){
					if($d !== $ignore && !$c->level->isEmptyBlock($x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d])){
						return false;
					}
				}
				return true;
			}

			private function growTreeRecursive(FeatureContext $c, int $x, int $y, int $z, int $startX, int $startZ, int $depth) : void{
				$random = $c->random;
				$level = $c->level;
				$height = $random->nextBoundedInt(4) + 1;
				if($depth === 0){
					++$height;
				}
				for($i = 0; $i < $height; ++$i){
					$ty = $y + $i + 1;
					if(!$this->allNeighborsEmpty($c, $x, $ty, $z, null)){
						return;
					}
					$this->setPlant($c, $x, $ty, $z);
					$this->setPlant($c, $x, $ty - 1, $z);
				}
				$placedStem = false;
				if($depth < 4){
					$stems = $random->nextBoundedInt(4);
					if($depth === 0){
						++$stems;
					}
					for($i = 0; $i < $stems; ++$i){
						$direction = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
						$tx = $x + Direction::STEP_X[$direction];
						$ty = $y + $height;
						$tz = $z + Direction::STEP_Z[$direction];
						if(
							abs($tx - $startX) < self::MAX_HORIZONTAL_SPREAD && abs($tz - $startZ) < self::MAX_HORIZONTAL_SPREAD &&
							$level->isEmptyBlock($tx, $ty, $tz) && $level->isEmptyBlock($tx, $ty - 1, $tz) &&
							$this->allNeighborsEmpty($c, $tx, $ty, $tz, Direction::OPPOSITE[$direction])
						){
							$placedStem = true;
							$this->setPlant($c, $tx, $ty, $tz);
							$this->setPlant($c, $tx - Direction::STEP_X[$direction], $ty, $tz - Direction::STEP_Z[$direction]);
							$this->growTreeRecursive($c, $tx, $ty, $tz, $startX, $startZ, $depth + 1);
						}
					}
				}
				if(!$placedStem){
					$level->setState($x, $y + $height, $z, $this->flower);
				}
			}
		};
	}

	public static function island(FeatureRegistry $registry) : Feature{
		return new class($registry->blocks->defaultOf("end_stone")) implements Feature{
			public function __construct(private int $endStone){}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				$random = $c->random;
				$level = $c->level;
				$size = FloatMath::fround($random->nextBoundedInt(3) + 4.0);
				for($dy = 0; $size > 0.5; --$dy){
					$limit = FloatMath::fround(($size + 1.0) * ($size + 1.0));
					for($dx = (int) floor(-$size); $dx <= (int) ceil($size); ++$dx){
						for($dz = (int) floor(-$size); $dz <= (int) ceil($size); ++$dz){
							if($dx * $dx + $dz * $dz <= $limit){
								$level->setState($x + $dx, $y + $dy, $z + $dz, $this->endStone);
							}
						}
					}
					$size = FloatMath::fround($size - FloatMath::fround($random->nextBoundedInt(2) + 0.5));
				}
				return true;
			}
		};
	}
}
