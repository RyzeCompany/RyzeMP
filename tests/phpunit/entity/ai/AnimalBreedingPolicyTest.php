<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Sheep;
use pocketmine\item\ItemTypeIds;

final class AnimalBreedingPolicyTest extends TestCase{
	public function testFoodIsSpeciesSpecific() : void{
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Cow::class, ItemTypeIds::WHEAT));
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Sheep::class, ItemTypeIds::WHEAT));
		self::assertFalse(AnimalBreedingPolicy::isBreedingFood(Cow::class, ItemTypeIds::CARROT));
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Pig::class, ItemTypeIds::CARROT));
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Pig::class, ItemTypeIds::POTATO));
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Pig::class, ItemTypeIds::BEETROOT));
		self::assertFalse(AnimalBreedingPolicy::isBreedingFood(Pig::class, ItemTypeIds::WHEAT));
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Chicken::class, ItemTypeIds::WHEAT_SEEDS));
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Chicken::class, ItemTypeIds::MELON_SEEDS));
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Chicken::class, ItemTypeIds::PUMPKIN_SEEDS));
		self::assertTrue(AnimalBreedingPolicy::isBreedingFood(Chicken::class, ItemTypeIds::BEETROOT_SEEDS));
		self::assertFalse(AnimalBreedingPolicy::isBreedingFood(Chicken::class, ItemTypeIds::WHEAT));
		self::assertFalse(AnimalBreedingPolicy::isBreedingFood("unsupported", ItemTypeIds::WHEAT));
	}
	public function testBabyFeedingAcceleratesGrowth() : void{
		self::assertSame(2400, AnimalBreedingPolicy::growthFromFood(-24000));
		self::assertSame(1, AnimalBreedingPolicy::growthFromFood(-1));
		self::assertSame(0, AnimalBreedingPolicy::growthFromFood(0));
		self::assertSame(0, AnimalBreedingPolicy::growthFromFood(6000));
	}
}
