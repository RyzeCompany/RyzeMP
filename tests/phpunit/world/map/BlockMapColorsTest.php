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

namespace pocketmine\world\map;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Axis;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_unique;
use function count;
use function implode;
use function sort;

class BlockMapColorsTest extends TestCase{

	private static function colorOf(Block $block) : int{
		return BlockMapColors::getInstance()->getColor($block->getStateId());
	}

	public function testVanillaColors() : void{
		//vanilla base colours (Java MapColor STONE, GRASS, SAND, WATER, PLANT, SNOW, WOOD)
		self::assertSame(0x707070, self::colorOf(VanillaBlocks::STONE()));
		self::assertSame(0x7fb238, self::colorOf(VanillaBlocks::GRASS()));
		self::assertSame(0xf7e9a3, self::colorOf(VanillaBlocks::SAND()));
		self::assertSame(BlockMapColors::WATER, self::colorOf(VanillaBlocks::WATER()));
		self::assertSame(0x007c00, self::colorOf(VanillaBlocks::OAK_LEAVES()));
		self::assertSame(0xffffff, self::colorOf(VanillaBlocks::SNOW()));
		self::assertSame(0x8f7748, self::colorOf(VanillaBlocks::OAK_PLANKS()));
		self::assertSame(0xff0000, self::colorOf(VanillaBlocks::LAVA()));
	}

	public function testBlocksNotShownOnMaps() : void{
		self::assertSame(BlockMapColors::NONE, self::colorOf(VanillaBlocks::AIR()));
		self::assertSame(BlockMapColors::NONE, self::colorOf(VanillaBlocks::GLASS()));
		self::assertSame(BlockMapColors::NONE, self::colorOf(VanillaBlocks::TORCH()));
	}

	public function testLogEndsUseTheTopColor() : void{
		//seen from above, an upright log shows its cut face (planks colour); a sideways log shows its bark
		self::assertSame(0x8f7748, self::colorOf(VanillaBlocks::OAK_LOG()->setAxis(Axis::Y)));
		self::assertSame(0x815631, self::colorOf(VanillaBlocks::OAK_LOG()->setAxis(Axis::X)));
		self::assertSame(0x815631, self::colorOf(VanillaBlocks::OAK_LOG()->setAxis(Axis::Z)));
	}

	public function testUnknownBlocksGetFallback() : void{
		$colors = BlockMapColors::getInstance();
		self::assertSame(BlockMapColors::FALLBACK, $colors->getColorByName("minecraft:some_future_block"));
		self::assertFalse($colors->isKnown("minecraft:some_future_block"));
	}

	public function testShade() : void{
		self::assertSame(0x7fb238, BlockMapColors::shade(0x7fb238, BlockMapColors::SHADE_HIGH));
		//vanilla multiplies each channel by 220/255 for the normal shade (127 * 220 / 255 = 109)
		self::assertSame((109 << 16) | (153 << 8) | 48, BlockMapColors::shade(0x7fb238, BlockMapColors::SHADE_NORMAL));
		self::assertSame(0, BlockMapColors::shade(0, BlockMapColors::SHADE_LOW));
	}

	/**
	 * Every block PocketMine knows must have a colour in the table, so that the table is regenerated when blocks are
	 * added. The listed blocks are newer than the BDS dump the table was made from and use the fallback colour.
	 */
	public function testAllBlocksAreInTheTable() : void{
		$colors = BlockMapColors::getInstance();
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$missing = [];
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $block){
			$name = $serializer->serialize($stateId)->getName();
			if(!$colors->isKnown($name)){
				$missing[] = $name;
			}
		}
		$missing = array_unique($missing);
		sort($missing);
		self::assertSame([], $missing, "Blocks missing from block_map_colors.json (" . count($missing) . "): " . implode(", ", $missing));
	}
}
