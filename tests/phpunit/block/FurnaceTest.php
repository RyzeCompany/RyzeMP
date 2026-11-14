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

namespace pocketmine\block;

use pocketmine\block\tile\Furnace as TileFurnace;
use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\FurnaceRecipe;
use pocketmine\crafting\FurnaceType;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Smelting experience and fuel use of furnaces, blast furnaces and smokers, in the in-memory world of the redstone
 * tests.
 */
final class FurnaceTest extends RedstoneTestCase{

	protected function getCraftingManager() : CraftingManager{
		$manager = parent::getCraftingManager();
		foreach([FurnaceType::FURNACE, FurnaceType::BLAST_FURNACE] as $type){
			if($manager->getFurnaceRecipeManager($type)->match(VanillaItems::RAW_IRON()) === null){
				$manager->getFurnaceRecipeManager($type)->register(new FurnaceRecipe(VanillaItems::IRON_INGOT(), new ExactRecipeIngredient(VanillaItems::RAW_IRON()), 0.7));
			}
		}
		return $manager;
	}

	private function placeFurnace(Furnace $block) : TileFurnace{
		$this->set(0, 1, 0, $block);
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(TileFurnace::class, $tile);
		return $tile;
	}

	public function testSmeltingStoresExperience() : void{
		$furnace = $this->placeFurnace(VanillaBlocks::FURNACE());
		$inventory = $furnace->getInventory();
		$inventory->setFuel(VanillaItems::COAL()->setCount(2));
		$inventory->setSmelting(VanillaItems::RAW_IRON()->setCount(10));

		$this->runTicks(10 * 200 + 20);

		self::assertSame(10, $inventory->getResult()->getCount());
		self::assertEqualsWithDelta(7.0, $furnace->getStoredExperience(), 0.0001);
		self::assertSame(7, $furnace->rollStoredExperience(), "10 x 0.7 is exactly 7 points");
		self::assertSame(7, $furnace->takeStoredExperience());
		self::assertSame(0.0, $furnace->getStoredExperience());
	}

	public function testExperienceSurvivesHopperExtraction() : void{
		$furnace = $this->placeFurnace(VanillaBlocks::FURNACE());
		$this->set(0, 0, 0, VanillaBlocks::HOPPER()->setFacing(Facing::EAST));
		$inventory = $furnace->getInventory();
		$inventory->setFuel(VanillaItems::COAL());
		$inventory->setSmelting(VanillaItems::RAW_IRON()->setCount(2));

		$this->runTicks(2 * 200 + 40);

		self::assertTrue($inventory->getResult()->isNull(), "the hopper took the ingots");
		self::assertEqualsWithDelta(1.4, $furnace->getStoredExperience(), 0.0001, "hoppers don't take the experience");

		//breaking the furnace drops it, whatever the tool
		$block = $this->get(0, 1, 0);
		$xp = $block->getXpDropForTool(VanillaItems::AIR());
		self::assertContains($xp, [1, 2]);
	}

	public function testFractionalExperience() : void{
		$furnace = $this->placeFurnace(VanillaBlocks::FURNACE());
		$furnace->setStoredExperience(0.35);
		$counts = [0 => 0, 1 => 0];
		for($i = 0; $i < 2000; ++$i){
			$counts[$furnace->rollStoredExperience()]++;
		}
		self::assertCount(2, $counts, "0.35 gives either 0 or 1 point");
		self::assertGreaterThan(500, $counts[1]);
		self::assertLessThan(900, $counts[1]);
	}

	public function testStoredExperienceIsSaved() : void{
		$furnace = $this->placeFurnace(VanillaBlocks::FURNACE());
		$furnace->setStoredExperience(12.35);
		$nbt = $furnace->saveNBT();
		self::assertSame(12, $nbt->getShort("StoredXPInt"));

		$copy = $this->placeFurnace(VanillaBlocks::FURNACE());
		$copy->readSaveData($nbt);
		self::assertEqualsWithDelta(12.35, $copy->getStoredExperience(), 0.0001);

		//Bedrock worlds only have whole points
		$copy->readSaveData(CompoundTag::create()->setShort("StoredXPInt", 5));
		self::assertSame(5.0, $copy->getStoredExperience());
	}

	public function testBlastFurnaceSmeltsEightItemsPerCoal() : void{
		$furnace = $this->placeFurnace(VanillaBlocks::BLAST_FURNACE());
		$inventory = $furnace->getInventory();
		$inventory->setFuel(VanillaItems::COAL());
		$inventory->setSmelting(VanillaItems::RAW_IRON()->setCount(16));

		$this->runTicks(1600 + 40);

		self::assertSame(8, $inventory->getResult()->getCount(), "one coal burns 800 ticks in a blast furnace");
		self::assertTrue($inventory->getFuel()->isNull());
	}

	public function testFurnaceSmeltsEightItemsPerCoal() : void{
		$furnace = $this->placeFurnace(VanillaBlocks::FURNACE());
		$inventory = $furnace->getInventory();
		$inventory->setFuel(VanillaItems::COAL());
		$inventory->setSmelting(VanillaItems::RAW_IRON()->setCount(16));

		$this->runTicks(3200 + 40);

		self::assertSame(8, $inventory->getResult()->getCount());
	}
}
