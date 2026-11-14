<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Sheep;
use pocketmine\item\ItemTypeIds;

/** Deterministic Bedrock-compatible feeding rules for the four supported farm species. */
final class AnimalBreedingPolicy{
	private function __construct(){}

	public static function isBreedingFood(string $entityClass, int $itemId) : bool{
		return match($entityClass){
			Cow::class, Sheep::class => $itemId === ItemTypeIds::WHEAT,
			Pig::class => in_array($itemId, [ItemTypeIds::CARROT, ItemTypeIds::POTATO, ItemTypeIds::BEETROOT], true),
			Chicken::class => in_array($itemId, [
				ItemTypeIds::WHEAT_SEEDS, ItemTypeIds::MELON_SEEDS,
				ItemTypeIds::PUMPKIN_SEEDS, ItemTypeIds::BEETROOT_SEEDS
			], true),
			default => false
		};
	}

	/** 10% of the remaining baby age, at least one tick when applicable. */
	public static function growthFromFood(int $negativeAge) : int{
		return $negativeAge < 0 ? max(1, (int) ceil(-$negativeAge * 0.1)) : 0;
	}
}
