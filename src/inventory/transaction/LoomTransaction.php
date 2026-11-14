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

namespace pocketmine\inventory\transaction;

use pocketmine\block\utils\BannerPatternLayer;
use pocketmine\block\utils\BannerPatternType;
use pocketmine\item\Banner;
use pocketmine\item\Dye;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\player\Player;
use function count;

/**
 * Adding a pattern to a banner in a loom (vanilla LoomMenu): a banner and a dye give the banner one more layer of
 * the chosen pattern in the dye's colour. Charge, thing, globe, snout, flow, guster, field masoned and bordure indented
 * patterns need their banner pattern item in the third slot, which is kept. A banner has at most 6 layers.
 */
class LoomTransaction extends InventoryTransaction{
	public const MAX_LAYERS = 6;

	public function __construct(
		Player $source,
		private readonly Item $expected,
		array $actions = []
	){
		parent::__construct($source, $actions);
	}

	/**
	 * Banner pattern item type ID => the pattern it unlocks.
	 * @phpstan-return array<int, BannerPatternType>
	 */
	private static function patternItems() : array{
		return [
			ItemTypeIds::CREEPER_BANNER_PATTERN => BannerPatternType::CREEPER,
			ItemTypeIds::SKULL_BANNER_PATTERN => BannerPatternType::SKULL,
			ItemTypeIds::FLOWER_BANNER_PATTERN => BannerPatternType::FLOWER,
			ItemTypeIds::MOJANG_BANNER_PATTERN => BannerPatternType::MOJANG,
			ItemTypeIds::GLOBE_BANNER_PATTERN => BannerPatternType::GLOBE,
			ItemTypeIds::PIGLIN_BANNER_PATTERN => BannerPatternType::PIGLIN,
			ItemTypeIds::FLOW_BANNER_PATTERN => BannerPatternType::FLOW,
			ItemTypeIds::GUSTER_BANNER_PATTERN => BannerPatternType::GUSTER,
			ItemTypeIds::FIELD_MASONED_BANNER_PATTERN => BannerPatternType::BRICKS,
			ItemTypeIds::BORDURE_INDENTED_BANNER_PATTERN => BannerPatternType::CURLY_BORDER,
		];
	}

	/**
	 * Whether the pattern can only be made with a banner pattern item.
	 */
	public static function needsPatternItem(BannerPatternType $type) : bool{
		foreach(self::patternItems() as $pattern){
			if($pattern === $type){
				return true;
			}
		}
		return false;
	}

	/**
	 * The banner with the pattern added, or null if the loom can't make it.
	 */
	public static function calculateResult(Item $banner, Item $dye, ?Item $patternItem, BannerPatternType $pattern) : ?Item{
		if(!$banner instanceof Banner || !$dye instanceof Dye){
			return null;
		}
		if($patternItem !== null && $patternItem->isNull()){
			$patternItem = null;
		}
		if(self::needsPatternItem($pattern) && ($patternItem === null || (self::patternItems()[$patternItem->getTypeId()] ?? null) !== $pattern)){
			return null;
		}
		$layers = $banner->getPatterns();
		if(count($layers) >= self::MAX_LAYERS){
			return null;
		}
		$layers[] = new BannerPatternLayer($pattern, $dye->getColor());
		$result = clone $banner;
		return $result->setPatterns($layers)->setCount(1);
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}
		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);
		if(count($outputs) !== 1 || !$outputs[0]->equalsExact($this->expected)){
			throw new TransactionValidationException("Invalid loom output");
		}
		$consumed = 0;
		foreach($inputs as $input){
			$consumed += $input->getCount();
		}
		if($consumed !== 2){
			throw new TransactionValidationException("Expected the loom to use a banner and a dye, got $consumed items");
		}
	}
}
