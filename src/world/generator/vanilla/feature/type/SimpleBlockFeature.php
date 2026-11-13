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
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;

/**
 * minecraft:simple_block: one block from a state provider, if it can survive there (double plants get both halves).
 */
final class SimpleBlockFeature implements Feature{
	/** @phpstan-var \Closure(FeatureContext, \pocketmine\world\generator\vanilla\feature\FeatureRandom, int, int, int) : ?int */
	private \Closure $provider;
	/** @phpstan-var array<int, true> */
	private array $doublePlants = [];
	private int $mossyCarpet;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$this->provider = $registry->stateProvider($json["to_place"] ?? null);
		$blocks = $registry->blocks;
		foreach(["tall_grass", "large_fern", "sunflower", "lilac", "rose_bush", "peony", "pitcher_plant", "small_dripleaf", "tall_seagrass"] as $name){
			$this->doublePlants[$blocks->blockIndex($name)] = true;
		}
		$this->mossyCarpet = $blocks->blockIndex("pale_moss_carpet");
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$state = ($this->provider)($c, $c->random, $x, $y, $z);
		if($state === null){
			return false;
		}
		$level = $c->level;
		$blocks = $c->blocks;
		if(!$c->survival->canSurvive($level, $state, $x, $y, $z)){
			return false;
		}
		$block = $blocks->blockOf[$state];
		if(isset($this->doublePlants[$block])){
			$above = $level->getState($x, $y + 1, $z);
			if(!$blocks->isAir($above) && !($blocks->fluid($state) === $blocks->fluid($above) && $blocks->isTagged($above, "replaceable"))){
				return false;
			}
			self::placeDoublePlant($c, $state, $x, $y, $z);
		}elseif($block === $this->mossyCarpet){
			//MossyCarpetBlock.placeAt(): the topper uses the region random, which is not reproduced
			$level->setState($x, $y, $z, $blocks->withBool($state, "bottom", true));
		}else{
			$level->setState($x, $y, $z, $state);
		}
		return true;
	}

	/**
	 * DoublePlantBlock.placeAt()
	 */
	public static function placeDoublePlant(FeatureContext $c, int $state, int $x, int $y, int $z) : void{
		$blocks = $c->blocks;
		$level = $c->level;
		$lower = $blocks->with($state, "half", "lower");
		if($blocks->hasProperty($lower, "waterlogged")){
			$lower = $blocks->withBool($lower, "waterlogged", $level->isWaterAt($x, $y, $z));
		}
		$level->setState($x, $y, $z, $lower);
		$upper = $blocks->with($state, "half", "upper");
		if($blocks->hasProperty($upper, "waterlogged")){
			$upper = $blocks->withBool($upper, "waterlogged", $level->isWaterAt($x, $y + 1, $z));
		}
		$level->setState($x, $y + 1, $z, $upper);
	}
}
