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
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\JavaHashSet;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function abs;
use function count;
use function intdiv;
use function is_array;
use function is_string;
use function max;
use function min;

/**
 * Vanilla's tree decorators (Java 26.3): beehive, place_on_ground, cocoa, trunk_vine, leave_vine, attached_to_leaves,
 * attached_to_logs, alter_ground, shelf_mushroom, pale_moss and creaking_heart.
 */
final class TreeDecorator{
	private string $type;
	private float $probability = 0.0;
	/** @phpstan-var (\Closure(FeatureContext, FeatureRandom, int, int, int) : ?int)|null */
	private ?\Closure $provider = null;
	private int $tries = 128;
	private int $radius = 2;
	private int $height = 1;
	private int $exclusionRadiusXZ = 0;
	private int $exclusionRadiusY = 0;
	private int $requiredEmptyBlocks = 1;
	/** @var int[] */
	private array $directions = [];
	private float $trunkProbability = 0.0;
	private float $groundProbability = 0.0;
	private FeatureRegistry $registry;

	/**
	 * @param mixed[]|mixed $json
	 */
	public function __construct(FeatureRegistry $registry, mixed $json){
		if(!is_array($json)){
			throw new \RuntimeException("Invalid tree decorator");
		}
		$this->registry = $registry;
		$this->type = WorldgenData::stripNamespace(WorldgenData::string($json, "type"));
		switch($this->type){
			case "beehive":
			case "cocoa":
			case "leave_vine":
			case "shelf_mushroom":
			case "creaking_heart":
				$this->probability = FloatMath::fround(WorldgenData::float($json, "probability"));
				break;
			case "trunk_vine":
				break;
			case "place_on_ground":
				$this->tries = WorldgenData::int($json, "tries", 128);
				$this->radius = WorldgenData::int($json, "radius", 2);
				$this->height = WorldgenData::int($json, "height", 1);
				$this->provider = $registry->stateProvider($json["block_state_provider"] ?? null);
				break;
			case "attached_to_leaves":
				$this->probability = FloatMath::fround(WorldgenData::float($json, "probability"));
				$this->exclusionRadiusXZ = WorldgenData::int($json, "exclusion_radius_xz");
				$this->exclusionRadiusY = WorldgenData::int($json, "exclusion_radius_y");
				$this->requiredEmptyBlocks = WorldgenData::int($json, "required_empty_blocks");
				$this->provider = $registry->stateProvider($json["block_provider"] ?? null);
				$this->directions = self::directions($json["directions"] ?? null);
				break;
			case "attached_to_logs":
				$this->probability = FloatMath::fround(WorldgenData::float($json, "probability"));
				$this->provider = $registry->stateProvider($json["block_provider"] ?? null);
				$this->directions = self::directions($json["directions"] ?? null);
				break;
			case "alter_ground":
				$this->provider = $registry->stateProvider($json["provider"] ?? null);
				break;
			case "pale_moss":
				$this->probability = FloatMath::fround(WorldgenData::float($json, "leaves_probability"));
				$this->trunkProbability = FloatMath::fround(WorldgenData::float($json, "trunk_probability"));
				$this->groundProbability = FloatMath::fround(WorldgenData::float($json, "ground_probability"));
				break;
			default:
				throw new \RuntimeException("Unknown tree decorator $this->type");
		}
	}

	/**
	 * @return int[]
	 */
	private static function directions(mixed $json) : array{
		$out = [];
		foreach(is_array($json) ? $json : [] as $name){
			if(is_string($name)){
				$out[] = Direction::fromName($name);
			}
		}
		return $out;
	}

	public function place(TreeDecoratorContext $ctx) : void{
		$c = $ctx->c;
		$random = $c->random;
		$blocks = $ctx->blocks;
		switch($this->type){
			case "beehive":
				$this->placeBeehive($ctx, $random);
				return;
			case "trunk_vine":
				foreach($ctx->logs as [$x, $y, $z]){
					$this->trunkVine($ctx, $random, $x, $y, $z);
				}
				return;
			case "leave_vine":
				foreach($ctx->leaves as [$x, $y, $z]){
					foreach([[Direction::WEST, "east"], [Direction::EAST, "west"], [Direction::NORTH, "south"], [Direction::SOUTH, "north"]] as [$d, $face]){
						if($random->nextFloat() < $this->probability){
							$vx = $x + Direction::STEP_X[$d];
							$vz = $z + Direction::STEP_Z[$d];
							if($ctx->isAir($vx, $y, $vz)){
								$this->addHangingVine($ctx, $vx, $y, $vz, $face);
							}
						}
					}
				}
				return;
			case "cocoa":
				if(!($random->nextFloat() < $this->probability) || count($ctx->logs) === 0){
					return;
				}
				$treeY = $ctx->logs[0][1];
				foreach($ctx->logs as [$x, $y, $z]){
					if($y - $treeY > 2){
						continue;
					}
					foreach(Direction::PLANE_HORIZONTAL as $direction){
						if($random->nextFloat() <= 0.25){
							$opposite = Direction::OPPOSITE[$direction];
							$cx = $x + Direction::STEP_X[$opposite];
							$cz = $z + Direction::STEP_Z[$opposite];
							if($ctx->isAir($cx, $y, $cz)){
								$state = $blocks->defaultOf("cocoa");
								$state = $blocks->withInt($state, "age", $random->nextBoundedInt(3));
								$state = $blocks->with($state, "facing", Direction::NAMES[$direction]);
								$ctx->setBlock($cx, $y, $cz, $state);
							}
						}
					}
				}
				return;
			case "place_on_ground":
				$positions = $ctx->lowestTrunkOrRoot();
				if(count($positions) === 0){
					return;
				}
				[$ox, $minY, $oz] = $positions[0];
				$minX = $maxX = $ox;
				$minZ = $maxZ = $oz;
				foreach($positions as [$x, $y, $z]){
					if($y === $minY){
						$minX = min($minX, $x);
						$maxX = max($maxX, $x);
						$minZ = min($minZ, $z);
						$maxZ = max($maxZ, $z);
					}
				}
				$bx0 = $minX - $this->radius;
				$bx1 = $maxX + $this->radius;
				$by0 = $minY - $this->height;
				$by1 = $minY + $this->height;
				$bz0 = $minZ - $this->radius;
				$bz1 = $maxZ + $this->radius;
				for($i = 0; $i < $this->tries; ++$i){
					$x = $random->nextIntBetweenInclusive($bx0, $bx1);
					$y = $random->nextIntBetweenInclusive($by0, $by1);
					$z = $random->nextIntBetweenInclusive($bz0, $bz1);
					$above = $c->level->getState($x, $y + 1, $z);
					if(
						($blocks->isAir($above) || $blocks->blockName($above) === "minecraft:vine") &&
						$c->survival->isSolidRender($c->level->getState($x, $y, $z)) &&
						$c->level->getHeight(FeatureLevel::MOTION_BLOCKING_NO_LEAVES, $x, $z) <= $y + 1
					){
						$state = $this->provider !== null ? ($this->provider)($c, $random, $x, $y + 1, $z) : null;
						if($state !== null){
							$ctx->setBlock($x, $y + 1, $z, $state);
						}
					}
				}
				return;
			case "attached_to_leaves":
				$blacklist = [];
				foreach($this->shuffledCopy($ctx->leaves, $random) as [$x, $y, $z]){
					$direction = $this->directions[$random->nextBoundedInt(count($this->directions))];
					$px = $x + Direction::STEP_X[$direction];
					$py = $y + Direction::STEP_Y[$direction];
					$pz = $z + Direction::STEP_Z[$direction];
					if(isset($blacklist[JavaHashSet::key($px, $py, $pz)])){
						continue;
					}
					if(!($random->nextFloat() < $this->probability) || !$this->hasRequiredEmptyBlocks($ctx, $x, $y, $z, $direction)){
						continue;
					}
					for($ex = -$this->exclusionRadiusXZ; $ex <= $this->exclusionRadiusXZ; ++$ex){
						for($ey = -$this->exclusionRadiusY; $ey <= $this->exclusionRadiusY; ++$ey){
							for($ez = -$this->exclusionRadiusXZ; $ez <= $this->exclusionRadiusXZ; ++$ez){
								$blacklist[JavaHashSet::key($px + $ex, $py + $ey, $pz + $ez)] = true;
							}
						}
					}
					$state = $this->provider !== null ? ($this->provider)($c, $random, $px, $py, $pz) : null;
					if($state !== null){
						$ctx->setBlock($px, $py, $pz, $state);
					}
				}
				return;
			case "attached_to_logs":
				foreach($this->shuffledCopy($ctx->logs, $random) as [$x, $y, $z]){
					$direction = $this->directions[$random->nextBoundedInt(count($this->directions))];
					$px = $x + Direction::STEP_X[$direction];
					$py = $y + Direction::STEP_Y[$direction];
					$pz = $z + Direction::STEP_Z[$direction];
					if($random->nextFloat() > $this->probability || !$ctx->isAir($px, $py, $pz)){
						continue;
					}
					$state = $this->provider !== null ? ($this->provider)($c, $random, $px, $py, $pz) : null;
					if($state !== null){
						$ctx->setBlock($px, $py, $pz, $state);
					}
				}
				return;
			case "alter_ground":
				$positions = $ctx->lowestTrunkOrRoot();
				if(count($positions) === 0){
					return;
				}
				$minY = $positions[0][1];
				foreach($positions as [$x, $y, $z]){
					if($y !== $minY){
						continue;
					}
					$this->placeCircle($ctx, $x - 1, $y, $z - 1);
					$this->placeCircle($ctx, $x + 2, $y, $z - 1);
					$this->placeCircle($ctx, $x - 1, $y, $z + 2);
					$this->placeCircle($ctx, $x + 2, $y, $z + 2);
					for($i = 0; $i < 5; ++$i){
						$placement = $random->nextBoundedInt(64);
						$xx = $placement % 8;
						$zz = intdiv($placement, 8);
						if($xx === 0 || $xx === 7 || $zz === 0 || $zz === 7){
							$this->placeCircle($ctx, $x - 3 + $xx, $y, $z - 3 + $zz);
						}
					}
				}
				return;
			case "shelf_mushroom":
				$this->placeShelfMushrooms($ctx, $random);
				return;
			case "pale_moss":
				$this->placePaleMoss($ctx, $random);
				return;
			case "creaking_heart":
				if(count($ctx->logs) === 0 || !($random->nextFloat() < $this->probability)){
					return;
				}
				$logTag = $blocks->tag("minecraft:logs");
				foreach($this->shuffledCopy($ctx->logs, $random) as [$x, $y, $z]){
					$ok = true;
					foreach([0, 1, 2, 3, 4, 5] as $d){
						$n = $c->level->getState($x + Direction::STEP_X[$d], $y + Direction::STEP_Y[$d], $z + Direction::STEP_Z[$d]);
						if(!isset($logTag[$blocks->blockOf[$n]])){
							$ok = false;
							break;
						}
					}
					if($ok){
						$state = $blocks->with($blocks->defaultOf("creaking_heart"), "creaking_heart_state", "dormant");
						$state = $blocks->withBool($state, "natural", true);
						$ctx->setBlock($x, $y, $z, $state);
						return;
					}
				}
				return;
		}
	}

	/**
	 * Util.shuffledCopy()
	 * @param int[][] $list
	 * @phpstan-param list<array{int, int, int}> $list
	 * @phpstan-return list<array{int, int, int}>
	 */
	private function shuffledCopy(array $list, FeatureRandom $random) : array{
		for($i = count($list); $i > 1; --$i){
			$swapTo = $random->nextBoundedInt($i);
			$tmp = $list[$i - 1];
			$list[$i - 1] = $list[$swapTo];
			$list[$swapTo] = $tmp;
		}
		return $list;
	}

	private function placeBeehive(TreeDecoratorContext $ctx, FeatureRandom $random) : void{
		$logs = $ctx->logs;
		$leaves = $ctx->leaves;
		if(count($logs) === 0 || !($random->nextFloat() < $this->probability)){
			return;
		}
		$hiveY = count($leaves) > 0 ?
			max($leaves[0][1] - 1, $logs[0][1] + 1) :
			min($logs[0][1] + 1 + $random->nextBoundedInt(3), $logs[count($logs) - 1][1]);
		$candidates = [];
		foreach($logs as [$x, $y, $z]){
			if($y === $hiveY){
				foreach([Direction::EAST, Direction::SOUTH, Direction::WEST] as $d){
					$candidates[] = [$x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d]];
				}
			}
		}
		if(count($candidates) === 0){
			return;
		}
		$candidates = $this->shuffledCopy($candidates, $random);
		foreach($candidates as [$x, $y, $z]){
			if($ctx->isAir($x, $y, $z) && $ctx->isAir($x, $y, $z + 1)){
				$blocks = $ctx->blocks;
				$ctx->setBlock($x, $y, $z, $blocks->with($blocks->defaultOf("bee_nest"), "facing", "south"));
				$level = $ctx->c->level;
				if(!$level->ensureCanWrite($x, $y, $z)){
					//no nest, so no block entity to put bees in
					return;
				}
				//2 or 3 bees that have been inside for 0-598 ticks
				$bees = 2 + $random->nextBoundedInt(2);
				$ticks = [];
				for($i = 0; $i < $bees; ++$i){
					$ticks[] = $random->nextBoundedInt(599);
				}
				$level->setBlockEntity($x, $y, $z, BlockEntityData::beehive($ticks));
				return;
			}
		}
	}

	private function trunkVine(TreeDecoratorContext $ctx, FeatureRandom $random, int $x, int $y, int $z) : void{
		foreach([[Direction::WEST, "east"], [Direction::EAST, "west"], [Direction::NORTH, "south"], [Direction::SOUTH, "north"]] as [$d, $face]){
			if($random->nextBoundedInt(3) > 0){
				$vx = $x + Direction::STEP_X[$d];
				$vz = $z + Direction::STEP_Z[$d];
				if($ctx->isAir($vx, $y, $vz)){
					$this->placeVine($ctx, $vx, $y, $vz, $face);
				}
			}
		}
	}

	private function placeVine(TreeDecoratorContext $ctx, int $x, int $y, int $z, string $face) : void{
		$blocks = $ctx->blocks;
		$ctx->setBlock($x, $y, $z, $blocks->withBool($blocks->defaultOf("vine"), $face, true));
	}

	private function addHangingVine(TreeDecoratorContext $ctx, int $x, int $y, int $z, string $face) : void{
		$this->placeVine($ctx, $x, $y, $z, $face);
		$max = 4;
		for($y--; $ctx->isAir($x, $y, $z) && $max > 0; $y--, $max--){
			$this->placeVine($ctx, $x, $y, $z, $face);
		}
	}

	private function hasRequiredEmptyBlocks(TreeDecoratorContext $ctx, int $x, int $y, int $z, int $direction) : bool{
		for($i = 1; $i <= $this->requiredEmptyBlocks; ++$i){
			if(!$ctx->isAir($x + Direction::STEP_X[$direction] * $i, $y + Direction::STEP_Y[$direction] * $i, $z + Direction::STEP_Z[$direction] * $i)){
				return false;
			}
		}
		return true;
	}

	private function placeCircle(TreeDecoratorContext $ctx, int $x, int $y, int $z) : void{
		for($xx = -2; $xx <= 2; ++$xx){
			for($zz = -2; $zz <= 2; ++$zz){
				if(abs($xx) === 2 && abs($zz) === 2){
					continue;
				}
				$this->placeBlockAt($ctx, $x + $xx, $y, $z + $zz);
			}
		}
	}

	private function placeBlockAt(TreeDecoratorContext $ctx, int $x, int $y, int $z) : void{
		$c = $ctx->c;
		for($dy = 2; $dy >= -3; --$dy){
			$cy = $y + $dy;
			$state = $this->provider !== null ? ($this->provider)($c, $c->random, $x, $cy, $z) : null;
			if($state !== null){
				$ctx->setBlock($x, $cy, $z, $state);
				return;
			}
			if(!$ctx->isAir($x, $cy, $z) && $dy < 0){
				return;
			}
		}
	}

	private function placeShelfMushrooms(TreeDecoratorContext $ctx, FeatureRandom $random) : void{
		if(!($random->nextFloat() < $this->probability)){
			return;
		}
		$logs = $ctx->logs;
		if(count($logs) === 0){
			return;
		}
		$first = $logs[0];
		$last = $logs[count($logs) - 1];
		if($first[1] === $last[1]){
			//fallen log
			$directions = $first[0] !== $last[0] ? [Direction::NORTH, Direction::SOUTH] : [Direction::EAST, Direction::WEST];
			foreach($logs as [$x, $y, $z]){
				foreach($directions as $facing){
					if($random->nextFloat() <= 0.25){
						$mx = $x + Direction::STEP_X[$facing];
						$mz = $z + Direction::STEP_Z[$facing];
						if($this->shelfReplaceable($ctx, $mx, $y, $mz) && !$this->adjacentShelf($ctx, $mx, $y, $mz) && !$this->adjacentShelf($ctx, $x, $y, $z)){
							$this->placeShelf($ctx, $random, $mx, $y, $mz, $facing);
						}
					}
				}
			}
			return;
		}
		$firstDir = Direction::PLANE_HORIZONTAL[$random->nextBoundedInt(4)];
		$directions = [$firstDir, Direction::clockWise($firstDir)];
		$baseY = $first[1];
		foreach($logs as [$x, $y, $z]){
			$dy = $y - $baseY;
			if($dy < 1 || $dy > 4){
				continue;
			}
			foreach($directions as $facing){
				if($random->nextFloat() <= 0.25){
					$mx = $x + Direction::STEP_X[$facing];
					$mz = $z + Direction::STEP_Z[$facing];
					if($this->shelfReplaceable($ctx, $mx, $y, $mz) && !$this->isShelf($ctx, $mx, $y - 1, $mz)){
						$this->placeShelf($ctx, $random, $mx, $y, $mz, $facing);
						break;
					}
				}
			}
		}
	}

	private function placeShelf(TreeDecoratorContext $ctx, FeatureRandom $random, int $x, int $y, int $z, int $facing) : void{
		$blocks = $ctx->blocks;
		$state = $blocks->withInt($blocks->defaultOf("shelf_mushroom"), "age", $random->nextBoundedInt(2));
		$ctx->setBlock($x, $y, $z, $blocks->with($state, "facing", Direction::NAMES[$facing]));
	}

	private function isShelf(TreeDecoratorContext $ctx, int $x, int $y, int $z) : bool{
		return $ctx->blocks->blockName($ctx->c->level->getState($x, $y, $z)) === "minecraft:shelf_mushroom";
	}

	private function adjacentShelf(TreeDecoratorContext $ctx, int $x, int $y, int $z) : bool{
		foreach(Direction::PLANE_HORIZONTAL as $d){
			if($this->isShelf($ctx, $x + Direction::STEP_X[$d], $y, $z + Direction::STEP_Z[$d])){
				return true;
			}
		}
		return false;
	}

	private function shelfReplaceable(TreeDecoratorContext $ctx, int $x, int $y, int $z) : bool{
		$blocks = $ctx->blocks;
		$level = $ctx->c->level;
		if(!$blocks->isTagged($level->getState($x, $y, $z), "replaceable")){
			return false;
		}
		foreach([[0, 0], [1, 0], [-1, 0], [0, -1], [0, 1]] as [$dx, $dz]){
			if($blocks->blockOf[$level->getState($x + $dx, $y, $z + $dz)] === $blocks->waterBlock){
				return false;
			}
		}
		return true;
	}

	private function placePaleMoss(TreeDecoratorContext $ctx, FeatureRandom $random) : void{
		$c = $ctx->c;
		$logs = $this->shuffledCopy($ctx->logs, $random);
		if(count($logs) === 0){
			return;
		}
		$origin = $logs[0];
		foreach($logs as $pos){
			if($pos[1] < $origin[1]){
				$origin = $pos;
			}
		}
		if($random->nextFloat() < $this->groundProbability){
			$this->registry->configuredRef("minecraft:pale_moss_patch")->place($c, $origin[0], $origin[1] + 1, $origin[2]);
		}
		foreach($ctx->logs as [$x, $y, $z]){
			if($random->nextFloat() < $this->trunkProbability && $ctx->isAir($x, $y - 1, $z)){
				$this->addMossHanger($ctx, $random, $x, $y - 1, $z);
			}
		}
		foreach($ctx->leaves as [$x, $y, $z]){
			if($random->nextFloat() < $this->probability && $ctx->isAir($x, $y - 1, $z)){
				$this->addMossHanger($ctx, $random, $x, $y - 1, $z);
			}
		}
	}

	private function addMossHanger(TreeDecoratorContext $ctx, FeatureRandom $random, int $x, int $y, int $z) : void{
		$blocks = $ctx->blocks;
		$moss = $blocks->defaultOf("pale_hanging_moss");
		while($ctx->isAir($x, $y - 1, $z) && $random->nextFloat() >= 0.5){
			$ctx->setBlock($x, $y, $z, $blocks->withBool($moss, "tip", false));
			--$y;
		}
		$ctx->setBlock($x, $y, $z, $blocks->withBool($moss, "tip", true));
	}
}
