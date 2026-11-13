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
use function intdiv;
use function max;

/**
 * Vanilla's random enchanting (EnchantmentHelper.enchantItem() / selectEnchantment() and EnchantRandomlyFunction),
 * on Java enchantment data.
 */
final class LootEnchanting{

	private function __construct(){
		//NOOP
	}

	/**
	 * EnchantRandomlyFunction.run()
	 * @param string[]|null $options
	 * @phpstan-param list<string>|null $options
	 */
	public static function enchantRandomly(LootData $data, LootStack $stack, LootContext $context, ?array $options, bool $onlyCompatible) : LootStack{
		$isBook = $stack->id === "minecraft:book";
		$checkCompatible = !$isBook && $onlyCompatible;
		$candidates = [];
		foreach($options ?? $data->enchantmentIds() as $id){
			$enchantment = $data->enchantment($id);
			if($enchantment !== null && (!$checkCompatible || $enchantment->canEnchant($stack->id))){
				$candidates[] = $enchantment;
			}
		}
		if(count($candidates) === 0){
			return $stack;
		}
		$enchantment = $candidates[$context->random->nextBoundedInt(count($candidates))];
		$level = NumberProvider::nextInt($context, $enchantment->getMinLevel(), $enchantment->maxLevel);
		if($isBook){
			$stack = $stack->transmute("minecraft:enchanted_book");
		}
		$stack->enchant($enchantment->id, $level);
		return $stack;
	}

	/**
	 * EnchantmentHelper.enchantItem()
	 * @param string[] $source
	 * @phpstan-param list<string> $source
	 */
	public static function enchantItem(LootData $data, LootContext $context, LootStack $stack, int $cost, array $source) : LootStack{
		$selected = self::selectEnchantment($data, $context, $stack, $cost, $source);
		if($stack->id === "minecraft:book"){
			$stack = $stack->transmute("minecraft:enchanted_book");
		}
		foreach($selected as [$enchantment, $level]){
			$stack->enchant($enchantment->id, $level);
		}
		return $stack;
	}

	/**
	 * EnchantmentHelper.selectEnchantment()
	 * @param string[] $source
	 * @phpstan-param list<string> $source
	 * @return mixed[][]
	 * @phpstan-return list<array{JavaEnchantment, int}>
	 */
	public static function selectEnchantment(LootData $data, LootContext $context, LootStack $stack, int $cost, array $source) : array{
		$results = [];
		$enchantability = $data->itemInfo($stack->id)[2];
		if($enchantability <= 0){
			return $results;
		}
		$random = $context->random;
		$cost += 1 + $random->nextBoundedInt(intdiv($enchantability, 4) + 1) + $random->nextBoundedInt(intdiv($enchantability, 4) + 1);
		$deviation = FloatMath::fround(FloatMath::fround(FloatMath::fround($random->nextFloat() + $random->nextFloat()) - 1.0) * FloatMath::fround(0.15));
		$cost = max(1, NumberProvider::round(FloatMath::fround((float) $cost + FloatMath::fround($cost * $deviation))));
		$available = self::getAvailableEnchantmentResults($data, $cost, $stack, $source);
		if(count($available) > 0){
			$picked = self::weightedPick($context, $available);
			if($picked !== null){
				$results[] = $picked;
			}
			while($random->nextBoundedInt(50) <= $cost){
				if(count($results) > 0){
					$last = $results[count($results) - 1][0];
					$filtered = [];
					foreach($available as $candidate){
						if(JavaEnchantment::areCompatible($last, $candidate[0])){
							$filtered[] = $candidate;
						}
					}
					$available = $filtered;
				}
				if(count($available) === 0){
					break;
				}
				$picked = self::weightedPick($context, $available);
				if($picked !== null){
					$results[] = $picked;
				}
				$cost = intdiv($cost, 2);
			}
		}
		return $results;
	}

	/**
	 * EnchantmentHelper.getAvailableEnchantmentResults()
	 * @param string[] $source
	 * @phpstan-param list<string> $source
	 * @return mixed[][]
	 * @phpstan-return list<array{JavaEnchantment, int}>
	 */
	public static function getAvailableEnchantmentResults(LootData $data, int $cost, LootStack $stack, array $source) : array{
		$results = [];
		$isBook = $stack->id === "minecraft:book";
		foreach($source as $id){
			$enchantment = $data->enchantment($id);
			if($enchantment === null || (!$isBook && !$enchantment->isPrimaryItem($stack->id))){
				continue;
			}
			for($level = $enchantment->maxLevel; $level >= $enchantment->getMinLevel(); --$level){
				if($cost >= $enchantment->getMinCost($level) && $cost <= $enchantment->getMaxCost($level)){
					$results[] = [$enchantment, $level];
					break;
				}
			}
		}
		return $results;
	}

	/**
	 * WeightedRandom.getRandomItem() with the enchantment weights.
	 * @param mixed[][] $list
	 * @phpstan-param list<array{JavaEnchantment, int}> $list
	 * @phpstan-return array{JavaEnchantment, int}|null
	 */
	private static function weightedPick(LootContext $context, array $list) : ?array{
		$total = 0;
		foreach($list as [$enchantment]){
			$total += $enchantment->weight;
		}
		if($total === 0){
			return null;
		}
		$selection = $context->random->nextBoundedInt($total);
		foreach($list as $entry){
			$selection -= $entry[0]->weight;
			if($selection < 0){
				return $entry;
			}
		}
		return null;
	}
}
