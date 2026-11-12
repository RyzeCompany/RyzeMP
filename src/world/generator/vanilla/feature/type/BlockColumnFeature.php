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
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\Predicates;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function is_array;
use function min;

/**
 * minecraft:block_column: layers of blocks stacked in one direction (sugar cane, cactus, kelp, dripleaves...).
 */
final class BlockColumnFeature implements Feature{
	/** @phpstan-var list<array{IntProvider, \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int}> */
	private array $layers = [];
	private int $direction;
	/** @phpstan-var \Closure(FeatureContext, int, int, int) : bool */
	private \Closure $allowedPlacement;
	private bool $prioritizeTip;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		foreach(WorldgenData::array($json, "layers") as $layer){
			if(!is_array($layer)){
				throw new \RuntimeException("Invalid block column layer");
			}
			$this->layers[] = [Providers::int($layer["height"] ?? null), $registry->stateProvider($layer["provider"] ?? null)];
		}
		$this->direction = Direction::fromName(WorldgenData::string($json, "direction"));
		$this->allowedPlacement = Predicates::block($registry->blocks, $json["allowed_placement"] ?? null);
		$this->prioritizeTip = ($json["prioritize_tip"] ?? false) === true;
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		$heights = [];
		$total = 0;
		foreach($this->layers as [$height]){
			$h = $height->sample($random);
			$heights[] = $h;
			$total += $h;
		}
		if($total === 0){
			return false;
		}
		$dx = Direction::STEP_X[$this->direction];
		$dy = Direction::STEP_Y[$this->direction];
		$dz = Direction::STEP_Z[$this->direction];
		$nx = $x + $dx;
		$ny = $y + $dy;
		$nz = $z + $dz;
		for($i = 0; $i < $total; ++$i){
			if(!($this->allowedPlacement)($c, $nx, $ny, $nz)){
				$heights = $this->truncate($heights, $total, $i);
				break;
			}
			$nx += $dx;
			$ny += $dy;
			$nz += $dz;
		}
		$px = $x;
		$py = $y;
		$pz = $z;
		foreach($this->layers as $i => [, $provider]){
			for($n = 0; $n < $heights[$i]; ++$n){
				$state = $provider($c, $random, $px, $py, $pz) ?? $c->level->getState($px, $py, $pz);
				$c->level->setState($px, $py, $pz, $state);
				$px += $dx;
				$py += $dy;
				$pz += $dz;
			}
		}
		return true;
	}

	/**
	 * @param int[] $heights
	 * @phpstan-param list<int> $heights
	 * @phpstan-return array<int, int>
	 */
	private function truncate(array $heights, int $total, int $newHeight) : array{
		$toRemove = $total - $newHeight;
		$step = $this->prioritizeTip ? 1 : -1;
		$start = $this->prioritizeTip ? 0 : count($heights) - 1;
		$end = $this->prioritizeTip ? count($heights) : -1;
		for($i = $start; $i !== $end && $toRemove > 0; $i += $step){
			$remove = min($heights[$i], $toRemove);
			$toRemove -= $remove;
			$heights[$i] -= $remove;
		}
		return $heights;
	}
}
