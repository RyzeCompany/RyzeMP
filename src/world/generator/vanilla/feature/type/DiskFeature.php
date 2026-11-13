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

use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\Predicates;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\WorldgenData;
use function is_array;
use function is_string;

/**
 * minecraft:disk (sand, clay, gravel, grass disks) and minecraft:spring_feature.
 */
final class DiskFeature implements Feature{
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	private \Closure $provider;
	/** @phpstan-var \Closure(FeatureContext, int, int, int) : bool */
	private \Closure $target;
	private IntProvider $radius;
	private int $halfHeight;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$this->provider = $registry->stateProvider($json["state_provider"] ?? null);
		$this->target = Predicates::block($registry->blocks, $json["target"] ?? null);
		$this->radius = Providers::int($json["radius"] ?? null);
		$this->halfHeight = WorldgenData::int($json, "half_height");
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$placed = false;
		$top = $y + $this->halfHeight;
		$bottom = $y - $this->halfHeight - 1;
		$r = $this->radius->sample($c->random);
		//BlockPos.betweenClosed(): x varies fastest
		for($pz = $z - $r; $pz <= $z + $r; ++$pz){
			for($px = $x - $r; $px <= $x + $r; ++$px){
				$xd = $px - $x;
				$zd = $pz - $z;
				if($xd * $xd + $zd * $zd > $r * $r){
					continue;
				}
				for($py = $top; $py > $bottom; --$py){
					if(($this->target)($c, $px, $py, $pz)){
						$state = ($this->provider)($c, $c->random, $px, $py, $pz);
						if($state !== null){
							$c->level->setState($px, $py, $pz, $state);
							$placed = true;
						}
					}
				}
			}
		}
		return $placed;
	}

	/**
	 * minecraft:spring_feature
	 * @param mixed[] $json
	 */
	public static function spring(FeatureRegistry $registry, array $json) : Feature{
		$blocks = $registry->blocks;
		$fluid = $json["state"] ?? null;
		$name = is_array($fluid) && is_string($fluid["id"] ?? null) ? $fluid["id"] : (is_string($fluid) ? $fluid : "minecraft:water");
		//FluidState.createLegacyBlock() of a source fluid: level 0
		$state = $blocks->defaultOf(WorldgenData::stripNamespace($name) === "lava" || WorldgenData::stripNamespace($name) === "flowing_lava" ? "lava" : "water");
		$valid = $blocks->blockSet($json["valid_blocks"] ?? null);
		$requiresBelow = ($json["requires_block_below"] ?? true) === true;
		$rockCount = WorldgenData::int($json, "rock_count", 4);
		$holeCount = WorldgenData::int($json, "hole_count", 1);
		return new class($state, $valid, $requiresBelow, $rockCount, $holeCount) implements Feature{
			/**
			 * @phpstan-param array<int, true> $valid
			 */
			public function __construct(
				private int $state,
				private array $valid,
				private bool $requiresBelow,
				private int $rockCount,
				private int $holeCount
			){}

			private function isValid(FeatureContext $c, int $x, int $y, int $z) : bool{
				return isset($this->valid[$c->blocks->blockOf[$c->level->getState($x, $y, $z)]]);
			}

			public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
				if(!$this->isValid($c, $x, $y + 1, $z)){
					return false;
				}
				if($this->requiresBelow && !$this->isValid($c, $x, $y - 1, $z)){
					return false;
				}
				$current = $c->level->getState($x, $y, $z);
				if(!$c->blocks->isAir($current) && !isset($this->valid[$c->blocks->blockOf[$current]])){
					return false;
				}
				$rocks = 0;
				$holes = 0;
				foreach([[-1, 0, 0], [1, 0, 0], [0, 0, -1], [0, 0, 1], [0, -1, 0]] as [$dx, $dy, $dz]){
					if($this->isValid($c, $x + $dx, $y + $dy, $z + $dz)){
						++$rocks;
					}
				}
				foreach([[-1, 0, 0], [1, 0, 0], [0, 0, -1], [0, 0, 1], [0, -1, 0]] as [$dx, $dy, $dz]){
					if($c->level->isEmptyBlock($x + $dx, $y + $dy, $z + $dz)){
						++$holes;
					}
				}
				if($rocks === $this->rockCount && $holes === $this->holeCount){
					$c->level->setState($x, $y, $z, $this->state);
					return true;
				}
				return false;
			}
		};
	}
}
