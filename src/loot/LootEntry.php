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

use function floor;
use function max;

/**
 * A loot pool entry (vanilla's LootPoolEntryContainer). Singleton entries (item, tag, loot_table, dynamic, empty)
 * expand to themselves (an expanded tag expands to one choice per item); composite entries (alternatives, group,
 * sequence) expand their children.
 */
final class LootEntry{
	public const ITEM = 0;
	public const TAG = 1;
	public const TABLE = 2;
	public const DYNAMIC = 3;
	public const EMPTY = 4;
	public const ALTERNATIVES = 5;
	public const GROUP = 6;
	public const SEQUENCE = 7;

	/**
	 * @var LootEntry[]
	 * @phpstan-var list<LootEntry>
	 */
	public array $children = [];
	public bool $expand = false;
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $tagItems = [];
	/** @phpstan-var (\Closure() : ?LootTable)|null */
	public ?\Closure $tableProvider = null;

	/**
	 * @phpstan-param (\Closure(LootContext) : bool)|null $condition
	 * @phpstan-param (\Closure(LootStack, LootContext) : LootStack)|null $function
	 */
	public function __construct(
		public readonly int $type,
		public readonly int $weight,
		public readonly int $quality,
		private ?\Closure $condition,
		private ?\Closure $function,
		public string $name
	){}

	private function canRun(LootContext $context) : bool{
		return $this->condition === null || ($this->condition)($context);
	}

	/**
	 * LootPoolEntryContainer.expand(): passes the choices of this entry to $output ([entry, item of an expanded tag]).
	 * @phpstan-param \Closure(LootEntry, ?string) : void $output
	 */
	public function expand(LootContext $context, \Closure $output) : bool{
		switch($this->type){
			case self::ALTERNATIVES:
				if(!$this->canRun($context)){
					return false;
				}
				foreach($this->children as $child){
					if($child->expand($context, $output)){
						return true;
					}
				}
				return false;
			case self::SEQUENCE:
				if(!$this->canRun($context)){
					return false;
				}
				foreach($this->children as $child){
					if(!$child->expand($context, $output)){
						return false;
					}
				}
				return true;
			case self::GROUP:
				if(!$this->canRun($context)){
					return false;
				}
				foreach($this->children as $child){
					$child->expand($context, $output);
				}
				return true;
			case self::TAG:
				if($this->expand){
					if(!$this->canRun($context)){
						return false;
					}
					foreach($this->tagItems as $item){
						$output($this, $item);
					}
					return true;
				}
				//fallthrough: a non-expanded tag is a single choice
			default:
				if($this->canRun($context)){
					$output($this, null);
					return true;
				}
				return false;
		}
	}

	/**
	 * LootPoolEntry.getWeight()
	 */
	public function getWeight(float $luck) : int{
		return max((int) floor($this->weight + $this->quality * $luck), 0);
	}

	/**
	 * LootPoolEntry.createItemStack(), for a choice returned by {@link expand()}.
	 * @phpstan-param \Closure(LootStack) : void $output
	 */
	public function createItemStack(?string $tagItem, \Closure $output, LootContext $context) : void{
		$function = $this->function;
		if($function !== null){
			$inner = $output;
			$output = function(LootStack $stack) use ($inner, $function, $context) : void{
				$inner($function($stack, $context));
			};
		}
		switch($this->type){
			case self::ITEM:
				$output(new LootStack($this->name));
				break;
			case self::TAG:
				if($tagItem !== null){
					$output(new LootStack($tagItem));
				}else{
					foreach($this->tagItems as $item){
						$output(new LootStack($item));
					}
				}
				break;
			case self::TABLE:
				$table = $this->tableProvider !== null ? ($this->tableProvider)() : null;
				$table?->getRandomItemsRaw($context, $output);
				break;
			case self::DYNAMIC:
				if($context->dynamicDrops !== null){
					($context->dynamicDrops)($this->name, $output);
				}
				break;
		}
	}
}
