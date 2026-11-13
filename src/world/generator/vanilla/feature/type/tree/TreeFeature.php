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

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\IntProvider;
use pocketmine\world\generator\vanilla\feature\JavaHashSet;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function is_array;
use function max;
use function min;
use function usort;

/**
 * minecraft:tree (TreeFeature): trunk placer, foliage placer, optional root placer and decorators, Java 26.3.
 *
 * Foliage attachments are arrays [x, y, z, radiusOffset, foliageHeightOffset, doubleTrunk].
 */
final class TreeFeature implements Feature{
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	public \Closure $trunkProvider;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	public \Closure $foliageProvider;
	/** @phpstan-var \Closure(FeatureContext, FeatureRandom, int, int, int) : ?int */
	public \Closure $belowTrunkProvider;
	public TrunkPlacer $trunkPlacer;
	public FoliagePlacer $foliagePlacer;
	public ?RootPlacer $rootPlacer = null;
	/** @var TreeDecorator[] */
	public array $decorators = [];
	public bool $ignoreVines;

	private int $sizeType;
	private int $sizeLimit;
	private int $sizeUpperLimit;
	private int $sizeLower;
	private int $sizeMiddle;
	private int $sizeUpper;
	private ?int $minClippedHeight;

	/** @phpstan-var array<int, array{int, int, int}> */
	public array $roots = [];
	/** @phpstan-var array<int, array{int, int, int}> */
	public array $trunks = [];
	/** @phpstan-var array<int, array{int, int, int}> */
	public array $foliage = [];
	/** @phpstan-var array<int, array{int, int, int}> */
	public array $decorations = [];

	public FeatureContext $c;
	public JavaBlocks $blocks;
	/** @phpstan-var array<int, true> */
	private array $replaceableByTrees;
	/** @phpstan-var array<int, true> */
	private array $logs;
	/** @phpstan-var array<int, true> */
	public array $leaves;
	public int $vine;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, array $json){
		$this->blocks = $blocks = $registry->blocks;
		$this->trunkProvider = $registry->stateProvider($json["trunk_provider"] ?? null);
		$this->foliageProvider = $registry->stateProvider($json["foliage_provider"] ?? null);
		$this->belowTrunkProvider = $registry->stateProvider($json["below_trunk_provider"] ?? "minecraft:soil_beneath_tree");
		$this->trunkPlacer = new TrunkPlacer($registry, WorldgenData::array($json, "trunk_placer"));
		$this->foliagePlacer = new FoliagePlacer(WorldgenData::array($json, "foliage_placer"));
		if(is_array($json["root_placer"] ?? null)){
			$this->rootPlacer = new RootPlacer($registry, $json["root_placer"]);
		}
		foreach(is_array($json["decorators"] ?? null) ? $json["decorators"] : [] as $decorator){
			$this->decorators[] = new TreeDecorator($registry, $decorator);
		}
		$this->ignoreVines = ($json["ignore_vines"] ?? false) === true;
		$size = WorldgenData::array($json, "minimum_size");
		$type = WorldgenData::stripNamespace(WorldgenData::string($size, "type"));
		$this->sizeType = $type === "three_layers_feature_size" ? 3 : 2;
		$this->sizeLimit = WorldgenData::int($size, "limit", 1);
		$this->sizeUpperLimit = WorldgenData::int($size, "upper_limit", 1);
		$this->sizeLower = WorldgenData::int($size, "lower_size", 0);
		$this->sizeMiddle = WorldgenData::int($size, "middle_size", 1);
		$this->sizeUpper = WorldgenData::int($size, "upper_size", 1);
		$this->minClippedHeight = isset($size["min_clipped_height"]) ? WorldgenData::int($size, "min_clipped_height") : null;
		$this->replaceableByTrees = $blocks->tag("minecraft:replaceable_by_trees");
		$this->logs = $blocks->tag("minecraft:logs");
		$this->leaves = $blocks->tag("minecraft:leaves");
		$this->vine = $blocks->blockIndex("vine");
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$this->c = $c;
		$this->roots = [];
		$this->trunks = [];
		$this->foliage = [];
		$this->decorations = [];
		if(!$this->doPlace($c, $x, $y, $z)){
			return false;
		}
		if(count($this->trunks) === 0 && count($this->foliage) === 0){
			return false;
		}
		if(count($this->decorators) > 0){
			$context = new TreeDecoratorContext($c, $this->blocks, $this, self::sortedByY($this->trunks), self::sortedByY($this->foliage), self::sortedByY($this->roots));
			foreach($this->decorators as $decorator){
				$decorator->place($context);
			}
		}
		//leaf distances (TreeFeature.updateLeaves()) are not kept: Bedrock leaves have no distance
		return true;
	}

	/**
	 * new ObjectArrayList(hashSet) sorted (stably) by Y, like TreeDecorator.Context.
	 * @phpstan-param array<int, array{int, int, int}> $set
	 * @phpstan-return list<array{int, int, int}>
	 */
	public static function sortedByY(array $set) : array{
		$list = JavaHashSet::iterationOrder($set);
		$indexed = [];
		foreach($list as $i => $pos){
			$indexed[] = [$pos[1], $i, $pos];
		}
		usort($indexed, fn(array $a, array $b) : int => $a[0] !== $b[0] ? $a[0] <=> $b[0] : $a[1] <=> $b[1]);
		$out = [];
		foreach($indexed as [, , $pos]){
			$out[] = $pos;
		}
		return $out;
	}

	private function doPlace(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		$treeHeight = $this->trunkPlacer->getTreeHeight($random);
		$foliageHeight = $this->foliagePlacer->foliageHeight($random, $treeHeight);
		$trunkHeight = $treeHeight - $foliageHeight;
		$leafRadius = $this->foliagePlacer->foliageRadius($random, $trunkHeight);
		$ty = $y;
		if($this->rootPlacer !== null){
			$ty = $y + $this->rootPlacer->trunkOffsetY->sample($random);
		}
		$minY = min($y, $ty);
		$maxY = max($y, $ty) + $treeHeight + 1;
		if($minY < $c->level->minY + 1 || $maxY > $c->level->maxY){
			return false;
		}
		$clipped = $this->getMaxFreeTreeHeight($treeHeight, $x, $ty, $z);
		if($clipped < $treeHeight && ($this->minClippedHeight === null || $clipped < $this->minClippedHeight)){
			return false;
		}
		if($this->rootPlacer !== null && !$this->rootPlacer->placeRoots($this, $x, $y, $z, $x, $ty, $z)){
			return false;
		}
		$attachments = $this->trunkPlacer->placeTrunk($this, $clipped, $x, $ty, $z);
		foreach($attachments as $attachment){
			$this->foliagePlacer->createFoliage($this, $clipped, $attachment, $foliageHeight, $leafRadius);
		}
		return true;
	}

	private function getSizeAtHeight(int $treeHeight, int $yo) : int{
		if($this->sizeType === 2){
			return $yo < $this->sizeLimit ? $this->sizeLower : $this->sizeUpper;
		}
		if($yo < $this->sizeLimit){
			return $this->sizeLower;
		}
		return $yo >= $treeHeight - $this->sizeUpperLimit ? $this->sizeUpper : $this->sizeMiddle;
	}

	private function getMaxFreeTreeHeight(int $maxTreeHeight, int $x, int $y, int $z) : int{
		$level = $this->c->level;
		for($yo = 0; $yo <= $maxTreeHeight + 1; ++$yo){
			$r = $this->getSizeAtHeight($maxTreeHeight, $yo);
			for($dx = -$r; $dx <= $r; ++$dx){
				for($dz = -$r; $dz <= $r; ++$dz){
					if(!$this->trunkPlacer->isFree($this, $x + $dx, $y + $yo, $z + $dz) || (!$this->ignoreVines && $this->blocks->blockOf[$level->getState($x + $dx, $y + $yo, $z + $dz)] === $this->vine)){
						return $yo - 2;
					}
				}
			}
		}
		return $maxTreeHeight;
	}

	/** TreeFeature.validTreePos(): air or replaceable by trees */
	public function validTreePos(int $x, int $y, int $z) : bool{
		$state = $this->c->level->getState($x, $y, $z);
		return $this->blocks->isAir($state) || isset($this->replaceableByTrees[$this->blocks->blockOf[$state]]);
	}

	public function isLog(int $x, int $y, int $z) : bool{
		return isset($this->logs[$this->blocks->blockOf[$this->c->level->getState($x, $y, $z)]]);
	}

	public function isAirOrLeaves(int $x, int $y, int $z) : bool{
		$state = $this->c->level->getState($x, $y, $z);
		return $this->blocks->isAir($state) || isset($this->leaves[$this->blocks->blockOf[$state]]);
	}

	public function setTrunk(int $x, int $y, int $z, int $state) : void{
		$this->trunks[JavaHashSet::key($x, $y, $z)] = [$x, $y, $z];
		$this->c->level->setState($x, $y, $z, $state);
	}

	public function setRoot(int $x, int $y, int $z, int $state) : void{
		$this->roots[JavaHashSet::key($x, $y, $z)] = [$x, $y, $z];
		$this->c->level->setState($x, $y, $z, $state);
	}

	public function setFoliage(int $x, int $y, int $z, int $state) : void{
		$this->foliage[JavaHashSet::key($x, $y, $z)] = [$x, $y, $z];
		$this->c->level->setState($x, $y, $z, $state);
	}

	public function isFoliageSet(int $x, int $y, int $z) : bool{
		return isset($this->foliage[JavaHashSet::key($x, $y, $z)]);
	}

	public function setDecoration(int $x, int $y, int $z, int $state) : void{
		$this->decorations[JavaHashSet::key($x, $y, $z)] = [$x, $y, $z];
		$this->c->level->setState($x, $y, $z, $state);
	}

	/** TrunkPlacer.placeBelowTrunkBlock() */
	public function placeBelowTrunkBlock(int $x, int $y, int $z) : void{
		$state = ($this->belowTrunkProvider)($this->c, $this->c->random, $x, $y, $z);
		if($state !== null){
			$this->setTrunk($x, $y, $z, $state);
		}
	}

	/**
	 * FoliagePlacer.tryPlaceLeaf()
	 */
	public function tryPlaceLeaf(int $x, int $y, int $z) : bool{
		$blocks = $this->blocks;
		$level = $this->c->level;
		$current = $level->getState($x, $y, $z);
		if($blocks->getProperty($current, "persistent") === "true" || !$this->validTreePos($x, $y, $z)){
			return false;
		}
		$state = ($this->foliageProvider)($this->c, $this->c->random, $x, $y, $z) ?? $current;
		if($blocks->hasProperty($state, "waterlogged")){
			$state = $blocks->withBool($state, "waterlogged", $blocks->isWater($current) && $blocks->fluidIsSource($current));
		}
		$this->setFoliage($x, $y, $z, $state);
		return true;
	}

	public function trunkState(int $x, int $y, int $z) : int{
		return ($this->trunkProvider)($this->c, $this->c->random, $x, $y, $z) ?? $this->c->level->getState($x, $y, $z);
	}

	public function foliageState(int $x, int $y, int $z) : int{
		return ($this->foliageProvider)($this->c, $this->c->random, $x, $y, $z) ?? $this->c->level->getState($x, $y, $z);
	}

	public static function intProvider(mixed $json, int $default = 0) : IntProvider{
		return $json === null ? IntProvider::constant($default) : Providers::int($json);
	}
}
