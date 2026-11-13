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

use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use function array_pop;
use function array_splice;
use function array_values;
use function count;
use function intdiv;
use function min;

/**
 * A vanilla loot table (Java Edition data), rolled exactly like vanilla: the same random calls in the same order, so
 * a table rolled with the same seed gives the same items as Java Edition (in Java item terms; see
 * {@link LootItemConverter} for what PocketMine can represent).
 *
 * Typical use:
 *   $table = (new LootTables($cachePath))->get("minecraft:chests/simple_dungeon");
 *   $items = $table->generate(LootContext::create());          //PocketMine items
 *   $table->fillInventory($inventory, LootContext::seeded($seed)); //a container, like an unpacked vanilla chest
 */
final class LootTable{

	/**
	 * @param LootPool[] $pools
	 * @phpstan-param list<LootPool> $pools
	 * @phpstan-param (\Closure(LootStack, LootContext) : LootStack)|null $function
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		private array $pools,
		private ?\Closure $function,
		private LootData $data,
		public readonly ?string $randomSequence
	){}

	/**
	 * LootTable.getRandomItemsRaw(): the rolled stacks, before splitting oversized stacks.
	 * @phpstan-param \Closure(LootStack) : void $output
	 */
	public function getRandomItemsRaw(LootContext $context, \Closure $output) : void{
		$key = "table:" . $this->id;
		if(isset($context->visited[$key])){
			return;
		}
		$context->visited[$key] = true;
		try{
			$function = $this->function;
			if($function !== null){
				$inner = $output;
				$output = function(LootStack $stack) use ($inner, $function, $context) : void{
					$inner($function($stack, $context));
				};
			}
			foreach($this->pools as $pool){
				$pool->addRandomItems($output, $context);
			}
		}finally{
			unset($context->visited[$key]);
		}
	}

	/**
	 * LootTable.getRandomItems(): the rolled stacks, oversized stacks split to the item's max stack size. May contain
	 * empty stacks.
	 * @return LootStack[]
	 * @phpstan-return list<LootStack>
	 */
	public function getRandomItems(LootContext $context) : array{
		$items = [];
		$data = $this->data;
		$this->getRandomItemsRaw($context, function(LootStack $stack) use (&$items, $data) : void{
			$max = $data->itemInfo($stack->id)[0];
			if($stack->count < $max){
				$items[] = $stack;
			}else{
				for($count = $stack->count; $count > 0; $count -= $part){
					$part = min($max, $count);
					$items[] = $stack->copyWithCount($part);
				}
			}
		});
		return $items;
	}

	/**
	 * LootTable.fill(): rolls the table and spreads the items over the empty slots of a container of $size slots like
	 * vanilla (shuffled empty slots, stacks split at random to fill more slots).
	 *
	 * @param true[] $occupiedSlots slots that already hold an item
	 * @phpstan-param array<int, true> $occupiedSlots
	 * @return LootStack[] slot => stack
	 * @phpstan-return array<int, LootStack>
	 */
	public function fill(int $size, array $occupiedSlots, LootContext $context) : array{
		$items = $this->getRandomItems($context);
		$random = $context->random;
		$slots = [];
		for($i = 0; $i < $size; ++$i){
			if(!isset($occupiedSlots[$i])){
				$slots[] = $i;
			}
		}
		self::shuffle($slots, $context);
		$items = $this->shuffleAndSplitItems($items, count($slots), $context);
		$result = [];
		foreach($items as $item){
			if(count($slots) === 0){
				\GlobalLogger::get()->debug("[Loot] Tried to overfill a container with loot table $this->id");
				break;
			}
			$slot = array_pop($slots);
			if(!$item->isEmpty()){
				$result[$slot] = $item;
			}
		}
		return $result;
	}

	/**
	 * Util.shuffle()
	 * @param mixed[] $list
	 * @phpstan-template T
	 * @phpstan-param array<int, T> $list
	 * @phpstan-param-out array<int, T> $list
	 */
	public static function shuffle(array &$list, LootContext $context) : void{
		for($i = count($list); $i > 1; --$i){
			$swapTo = $context->random->nextBoundedInt($i);
			$tmp = $list[$i - 1];
			$list[$i - 1] = $list[$swapTo];
			$list[$swapTo] = $tmp;
		}
	}

	/**
	 * LootTable.shuffleAndSplitItems()
	 * @param LootStack[] $items
	 * @phpstan-param list<LootStack> $items
	 * @return LootStack[]
	 * @phpstan-return list<LootStack>
	 */
	private function shuffleAndSplitItems(array $items, int $availableSlots, LootContext $context) : array{
		$random = $context->random;
		$result = [];
		$splittable = [];
		foreach($items as $item){
			if($item->isEmpty()){
				continue;
			}
			if($item->count > 1){
				$splittable[] = $item;
			}else{
				$result[] = $item;
			}
		}
		while($availableSlots - count($result) - count($splittable) > 0 && count($splittable) > 0){
			$stack = array_splice($splittable, NumberProvider::nextInt($context, 0, count($splittable) - 1), 1)[0];
			$split = $stack->split(NumberProvider::nextInt($context, 1, intdiv($stack->count, 2)));
			if($stack->count > 1 && $random->nextBoolean()){
				$splittable[] = $stack;
			}else{
				$result[] = $stack;
			}
			if($split->count > 1 && $random->nextBoolean()){
				$splittable[] = $split;
			}else{
				$result[] = $split;
			}
		}
		foreach($splittable as $stack){
			$result[] = $stack;
		}
		self::shuffle($result, $context);
		return array_values($result);
	}

	/**
	 * Rolls the table and converts the stacks to PocketMine items (stacks PocketMine cannot represent are dropped).
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public function generate(LootContext $context) : array{
		$items = [];
		foreach($this->getRandomItems($context) as $stack){
			if(!$stack->isEmpty() && ($item = LootItemConverter::toItem($stack, $this->data->getRoot())) !== null){
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Fills an inventory like a vanilla container with this loot table ({@link fill()}). Items PocketMine cannot
	 * represent leave their slot empty.
	 */
	public function fillInventory(Inventory $inventory, LootContext $context) : void{
		$inventory->setContents($this->rollInventory($inventory, $context));
	}

	/** @return array<int, Item> */
	public function rollInventory(Inventory $inventory, LootContext $context) : array{
		$items = $inventory->getContents();
		$occupied = [];
		foreach($items as $slot => $_){
			$occupied[$slot] = true;
		}
		foreach($this->fill($inventory->getSize(), $occupied, $context) as $slot => $stack){
			$item = LootItemConverter::toItem($stack, $this->data->getRoot());
			if($item !== null){
				$items[$slot] = $item;
			}
		}
		return $items;
	}
}
