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

use pocketmine\utils\Utils;
use function count;
use function implode;
use function max;

/**
 * An item stack produced by a loot table, in Java Edition terms (the loot table data is Java's): a Java item id, a
 * count and the components the loot functions set. {@link LootItemConverter} turns it into a PocketMine item.
 */
final class LootStack{
	/**
	 * Java enchantment id => level. For enchanted books these are the stored enchantments.
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	public array $enchantments = [];
	/** Damage value (Java "damage" component), only meaningful for damageable items. */
	public int $damage = 0;
	/** Java potion id ("minecraft:healing") of potions, splash/lingering potions and tipped arrows. */
	public ?string $potion = null;
	/** Custom name (plain text). */
	public ?string $customName = null;
	/**
	 * Suspicious stew effects: list of [Java effect id, duration in ticks].
	 * @var mixed[][]
	 * @phpstan-var list<array{string, int}>
	 */
	public array $stewEffects = [];
	/** Ominous bottle amplifier (0-4). */
	public ?int $ominousAmplifier = null;
	/** Java instrument id of goat horns ("minecraft:ponder_goat_horn"). */
	public ?string $instrument = null;
	/**
	 * Armor trim: [Java trim material id, Java trim pattern id].
	 * @var string[]|null
	 * @phpstan-var array{string, string}|null
	 */
	public ?array $trim = null;

	public function __construct(
		/** Java item id, e.g. "minecraft:iron_ingot"; "minecraft:air" is the empty stack */
		public string $id,
		public int $count = 1
	){}

	public static function empty() : self{
		return new self("minecraft:air", 0);
	}

	public function isEmpty() : bool{
		return $this->count <= 0 || $this->id === "minecraft:air";
	}

	/**
	 * ItemStack.copyWithCount()
	 */
	public function copyWithCount(int $count) : self{
		$copy = clone $this;
		$copy->count = $count;
		return $copy;
	}

	/**
	 * ItemStack.transmuteCopy(): the same components on another item.
	 */
	public function transmute(string $id) : self{
		$copy = clone $this;
		$copy->id = $id;
		return $copy;
	}

	/**
	 * ItemStack.split()
	 */
	public function split(int $amount) : self{
		$amount = $amount < $this->count ? $amount : $this->count;
		$copy = $this->copyWithCount($amount);
		$this->count -= $amount;
		return $copy;
	}

	/**
	 * Adds an enchantment, keeping the higher level if it is already there (ItemEnchantments.Mutable.upgrade()).
	 */
	public function enchant(string $enchantment, int $level) : void{
		$this->enchantments[$enchantment] = max($this->enchantments[$enchantment] ?? 0, $level);
	}

	public function getEnchantmentLevel(string $enchantment) : int{
		return $this->enchantments[$enchantment] ?? 0;
	}

	/**
	 * A compact description in Java terms, e.g. "minecraft:enchanted_book x1 {minecraft:sharpness=3}", for logs and
	 * comparisons with Java Edition.
	 */
	public function describe() : string{
		$extra = [];
		foreach(Utils::stringifyKeys($this->enchantments) as $id => $level){
			$extra[] = "$id=$level";
		}
		if($this->damage !== 0){
			$extra[] = "damage=$this->damage";
		}
		if($this->potion !== null){
			$extra[] = "potion=$this->potion";
		}
		if($this->ominousAmplifier !== null){
			$extra[] = "amplifier=$this->ominousAmplifier";
		}
		if($this->instrument !== null){
			$extra[] = "instrument=$this->instrument";
		}
		if($this->trim !== null){
			$extra[] = "trim=" . $this->trim[0] . "/" . $this->trim[1];
		}
		foreach($this->stewEffects as [$effect, $duration]){
			$extra[] = "$effect:$duration";
		}
		return $this->id . " x" . $this->count . (count($extra) > 0 ? " {" . implode(",", $extra) . "}" : "");
	}
}
