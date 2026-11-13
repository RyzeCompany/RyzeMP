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

namespace pocketmine\world\generator\vanilla\feature;

use function count;

/**
 * A placed feature (worldgen/placed_feature): a configured feature and the placement modifiers that turn the origin
 * into the positions where it is placed. Modifiers are applied depth-first, in order, like vanilla's FeaturePlacer.
 */
final class PlacedFeature{
	private readonly int $modifierCount;

	/**
	 * @param \Closure[] $modifiers
	 * @phpstan-param list<\Closure(FeatureContext, int, int, int) : list<array{int, int, int}>> $modifiers
	 */
	public function __construct(
		public readonly string $id,
		public readonly Feature $feature,
		private readonly array $modifiers
	){
		$this->modifierCount = count($modifiers);
	}

	/**
	 * PlacedFeature.place(): without biome check (nested features).
	 */
	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$previous = $c->topFeature;
		$c->topFeature = null;
		try{
			return $this->placeFrom($c, 0, $x, $y, $z);
		}finally{
			$c->topFeature = $previous;
		}
	}

	/**
	 * FeaturePlacer.placeWithBiomeCheck(): the biome filter modifier checks this feature against the biome.
	 */
	public function placeWithBiomeCheck(FeatureContext $c, int $x, int $y, int $z) : bool{
		$previous = $c->topFeature;
		$c->topFeature = $this;
		try{
			return $this->placeFrom($c, 0, $x, $y, $z);
		}finally{
			$c->topFeature = $previous;
		}
	}

	private function placeFrom(FeatureContext $c, int $index, int $x, int $y, int $z) : bool{
		if($index === $this->modifierCount){
			return $this->feature->place($c, $x, $y, $z);
		}
		$placed = false;
		$positions = ($this->modifiers[$index])($c, $x, $y, $z);
		if($index + 1 === $this->modifierCount){
			foreach($positions as [$px, $py, $pz]){
				$placed = $this->feature->place($c, $px, $py, $pz) || $placed;
			}
		}else{
			foreach($positions as [$px, $py, $pz]){
				$placed = $this->placeFrom($c, $index + 1, $px, $py, $pz) || $placed;
			}
		}
		return $placed;
	}
}
