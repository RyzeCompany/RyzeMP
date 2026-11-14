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

namespace pocketmine\entity\effect;

use pocketmine\block\Cobweb;
use pocketmine\block\VanillaBlocks;
use pocketmine\crafting\CraftingManagerFromDataHelper;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\data\bedrock\EffectIds;
use pocketmine\data\bedrock\PotionTypeIdMap;
use pocketmine\data\bedrock\PotionTypeIds;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\world\redstone\RedstoneTestCase;
use function mt_srand;

/**
 * The potions added in 1.21 (Wind Charging, Weaving, Oozing, Infestation) and their effects.
 */
class TrialPotionsTest extends RedstoneTestCase{

	/**
	 * @return \Generator<string, array{PotionType, int, Effect, int, \Closure() : Item}>
	 */
	public static function potionProvider() : \Generator{
		yield "wind charging" => [PotionType::WIND_CHARGED, PotionTypeIds::WIND_CHARGED, VanillaEffects::WIND_CHARGED(), EffectIds::WIND_CHARGED, fn() => VanillaItems::BREEZE_ROD()];
		yield "weaving" => [PotionType::WEAVING, PotionTypeIds::WEAVING, VanillaEffects::WEAVING(), EffectIds::WEAVING, fn() => VanillaBlocks::COBWEB()->asItem()];
		yield "oozing" => [PotionType::OOZING, PotionTypeIds::OOZING, VanillaEffects::OOZING(), EffectIds::OOZING, fn() => VanillaBlocks::SLIME()->asItem()];
		yield "infestation" => [PotionType::INFESTED, PotionTypeIds::INFESTED, VanillaEffects::INFESTED(), EffectIds::INFESTED, fn() => VanillaBlocks::STONE()->asItem()];
	}

	/**
	 * @dataProvider potionProvider
	 * @phpstan-param \Closure() : Item $ingredient
	 */
	public function testPotion(PotionType $type, int $potionId, Effect $effect, int $effectId, \Closure $ingredient) : void{
		self::assertSame($potionId, PotionTypeIdMap::getInstance()->toId($type));
		self::assertSame($type, PotionTypeIdMap::getInstance()->fromId($potionId));
		self::assertSame($effectId, EffectIdMap::getInstance()->toId($effect));

		$effects = $type->getEffects();
		self::assertCount(1, $effects);
		self::assertSame($effect, $effects[0]->getType());
		self::assertSame(3 * 60 * 20, $effects[0]->getDuration());
		self::assertSame(0, $effects[0]->getAmplifier());
		self::assertTrue($effect->isBad());

		//brewed from an awkward potion, in all three bottle types
		$crafting = CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES);
		foreach([VanillaItems::POTION(), VanillaItems::SPLASH_POTION(), VanillaItems::LINGERING_POTION()] as $bottle){
			$input = (clone $bottle)->setType(PotionType::AWKWARD);
			$recipe = $crafting->matchBrewingRecipe($input, $ingredient());
			self::assertNotNull($recipe, $bottle->getName());
			$result = $recipe->getResultFor($input);
			self::assertNotNull($result);
			self::assertTrue($result->equals((clone $bottle)->setType($type)), $bottle->getName());
		}
	}

	private function spawnZombie(float $x, float $y, float $z) : Zombie{
		$zombie = new Zombie(new Location($x, $y, $z, $this->world, 0, 0));
		$zombie->spawnToAll();
		return $zombie;
	}

	public function testWindChargedBurstsOnDeath() : void{
		$this->floor(-5, -5, 5, 5);
		$victim = $this->spawnZombie(0.5, 1, 0.5);
		$bystander = $this->spawnZombie(2.5, 1, 0.5);
		$victim->getEffects()->add(new EffectInstance(VanillaEffects::WIND_CHARGED(), 600));
		self::assertEquals(0.0, $bystander->getMotion()->x);

		$victim->kill();
		self::assertGreaterThan(0.0, $bystander->getMotion()->x, "pushed away from the dead zombie");
	}

	public function testNoBurstWithoutTheEffect() : void{
		$this->floor(-5, -5, 5, 5);
		$victim = $this->spawnZombie(0.5, 1, 0.5);
		$bystander = $this->spawnZombie(2.5, 1, 0.5);
		$victim->kill();
		self::assertEquals(0.0, $bystander->getMotion()->x);
	}

	public function testWeavingLeavesCobwebsOnDeath() : void{
		$this->floor(-5, -5, 5, 5);
		$victim = $this->spawnZombie(0.5, 1, 0.5);
		$victim->getEffects()->add(new EffectInstance(VanillaEffects::WEAVING(), 600));
		mt_srand(1); //the spots are random: keep the test deterministic
		$victim->kill();
		mt_srand();

		$cobwebs = 0;
		for($x = -1; $x <= 1; ++$x){
			for($y = 0; $y <= 2; ++$y){
				for($z = -1; $z <= 1; ++$z){
					if($this->get($x, $y, $z) instanceof Cobweb){
						++$cobwebs;
						self::assertSame(1, $y, "cobwebs stand on the floor");
					}
				}
			}
		}
		//up to 3; 15 random tries in the 3x3x3 cube (9 of which are good spots) find at least one in practice
		self::assertGreaterThanOrEqual(1, $cobwebs);
		self::assertLessThanOrEqual(3, $cobwebs);
	}

	public function testSpreadCobwebsNeedsFloor() : void{
		//no floor: nothing to stand on
		self::assertSame(0, WeavingEffect::spreadCobwebs($this->world, 0, 10, 0, 3));
		$this->floor(-1, -1, 1, 1);
		$placed = WeavingEffect::spreadCobwebs($this->world, 0, 1, 0, 2);
		self::assertLessThanOrEqual(2, $placed);
	}
}
