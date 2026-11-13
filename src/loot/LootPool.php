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

namespace pocketmine\loot;

use pocketmine\world\generator\vanilla\math\FloatMath;
use function count;
use function floor;

/**
 * A loot pool: rolls (plus bonus rolls scaled by luck) weighted picks among the entries that pass their conditions.
 */
final class LootPool{

	/**
	 * @param LootEntry[] $entries
	 * @phpstan-param list<LootEntry> $entries
	 * @phpstan-param (\Closure(LootContext) : bool)|null $condition
	 * @phpstan-param (\Closure(LootStack, LootContext) : LootStack)|null $function
	 */
	public function __construct(
		private array $entries,
		private NumberProvider $rolls,
		private NumberProvider $bonusRolls,
		private ?\Closure $condition,
		private ?\Closure $function
	){}

	/**
	 * LootPool.addRandomItems()
	 * @phpstan-param \Closure(LootStack) : void $output
	 */
	public function addRandomItems(\Closure $output, LootContext $context) : void{
		if($this->condition !== null && !($this->condition)($context)){
			return;
		}
		$function = $this->function;
		if($function !== null){
			$inner = $output;
			$output = function(LootStack $stack) use ($inner, $function, $context) : void{
				$inner($function($stack, $context));
			};
		}
		$count = $this->rolls->getInt($context) + (int) floor(FloatMath::fround($this->bonusRolls->getFloat($context) * $context->luck));
		for($i = 0; $i < $count; ++$i){
			$this->addRandomItem($output, $context);
		}
	}

	/**
	 * @phpstan-param \Closure(LootStack) : void $output
	 */
	private function addRandomItem(\Closure $output, LootContext $context) : void{
		$luck = $context->luck;
		/** @phpstan-var list<array{LootEntry, ?string}> $choices */
		$choices = [];
		$totalWeight = 0;
		foreach($this->entries as $entry){
			$entry->expand($context, function(LootEntry $choice, ?string $tagItem) use (&$choices, &$totalWeight, $luck) : void{
				$weight = $choice->getWeight($luck);
				if($weight > 0){
					$choices[] = [$choice, $tagItem];
					$totalWeight += $weight;
				}
			});
		}
		$size = count($choices);
		if($totalWeight === 0 || $size === 0){
			return;
		}
		if($size === 1){
			$choices[0][0]->createItemStack($choices[0][1], $output, $context);
			return;
		}
		$index = $context->random->nextBoundedInt($totalWeight);
		foreach($choices as [$choice, $tagItem]){
			$index -= $choice->getWeight($luck);
			if($index < 0){
				$choice->createItemStack($tagItem, $output, $context);
				return;
			}
		}
	}
}
