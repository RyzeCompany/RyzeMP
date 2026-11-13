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
use pocketmine\world\generator\vanilla\feature\FeatureContext;

/**
 * TreeDecorator.Context: the placed tree's logs, leaves and roots (sorted by Y, ties in vanilla's HashSet order).
 */
final class TreeDecoratorContext{

	/**
	 * @param int[][] $logs
	 * @param int[][] $leaves
	 * @param int[][] $roots
	 * @phpstan-param list<array{int, int, int}> $logs
	 * @phpstan-param list<array{int, int, int}> $leaves
	 * @phpstan-param list<array{int, int, int}> $roots
	 */
	public function __construct(
		public readonly FeatureContext $c,
		public readonly JavaBlocks $blocks,
		private readonly ?TreeFeature $tree,
		public readonly array $logs,
		public readonly array $leaves,
		public readonly array $roots
	){}

	public function isAir(int $x, int $y, int $z) : bool{
		return $this->blocks->isAir($this->c->level->getState($x, $y, $z));
	}

	public function setBlock(int $x, int $y, int $z, int $state) : void{
		if($this->tree !== null){
			$this->tree->setDecoration($x, $y, $z, $state);
		}else{
			$this->c->level->setState($x, $y, $z, $state);
		}
	}

	/**
	 * TreeFeature.getLowestTrunkOrRootOfTree()
	 * @phpstan-return list<array{int, int, int}>
	 */
	public function lowestTrunkOrRoot() : array{
		if($this->roots === []){
			return $this->logs;
		}
		if($this->logs !== [] && $this->roots[0][1] === $this->logs[0][1]){
			return [...$this->logs, ...$this->roots];
		}
		return $this->roots;
	}
}
