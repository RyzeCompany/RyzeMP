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

namespace pocketmine\world\generator\vanilla;

use pocketmine\block\Block;
use pocketmine\block\utils\DirtType;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use function str_ends_with;
use function strtoupper;
use function substr;

/**
 * Java Edition block ids produced by the worldgen data (material rules) to PocketMine blocks.
 */
final class JavaBlockMapping{

	private function __construct(){
		//NOOP
	}

	public static function toBlock(string $javaId) : Block{
		$id = WorldgenData::stripNamespace($javaId);
		$block = match($id){
			"air" => VanillaBlocks::AIR(),
			"stone" => VanillaBlocks::STONE(),
			"water" => VanillaBlocks::WATER()->getStillForm(),
			"lava" => VanillaBlocks::LAVA()->getStillForm(),
			"bedrock" => VanillaBlocks::BEDROCK(),
			"deepslate" => VanillaBlocks::DEEPSLATE(),
			"granite" => VanillaBlocks::GRANITE(),
			"tuff" => VanillaBlocks::TUFF(),
			"copper_ore" => VanillaBlocks::COPPER_ORE(),
			"deepslate_iron_ore" => VanillaBlocks::DEEPSLATE_IRON_ORE(),
			"raw_copper_block" => VanillaBlocks::RAW_COPPER(),
			"raw_iron_block" => VanillaBlocks::RAW_IRON(),
			"grass_block" => VanillaBlocks::GRASS(),
			"dirt" => VanillaBlocks::DIRT(),
			"coarse_dirt" => VanillaBlocks::DIRT()->setDirtType(DirtType::COARSE),
			"podzol" => VanillaBlocks::PODZOL(),
			"mycelium" => VanillaBlocks::MYCELIUM(),
			"mud" => VanillaBlocks::MUD(),
			"sand" => VanillaBlocks::SAND(),
			"red_sand" => VanillaBlocks::RED_SAND(),
			"sandstone" => VanillaBlocks::SANDSTONE(),
			"red_sandstone" => VanillaBlocks::RED_SANDSTONE(),
			"gravel" => VanillaBlocks::GRAVEL(),
			"snow_block" => VanillaBlocks::SNOW(),
			"powder_snow" => VanillaBlocks::SNOW(),
			"ice" => VanillaBlocks::ICE(),
			"packed_ice" => VanillaBlocks::PACKED_ICE(),
			"calcite" => VanillaBlocks::CALCITE(),
			"terracotta" => VanillaBlocks::HARDENED_CLAY(),
			"sulfur" => VanillaBlocks::STONE(),
			"cinnabar" => VanillaBlocks::STONE(),
			//Nether and End material rules
			"netherrack" => VanillaBlocks::NETHERRACK(),
			"soul_sand" => VanillaBlocks::SOUL_SAND(),
			"soul_soil" => VanillaBlocks::SOUL_SOIL(),
			"basalt" => VanillaBlocks::BASALT(),
			"blackstone" => VanillaBlocks::BLACKSTONE(),
			"crimson_nylium" => VanillaBlocks::CRIMSON_NYLIUM(),
			"warped_nylium" => VanillaBlocks::WARPED_NYLIUM(),
			"nether_wart_block" => VanillaBlocks::NETHER_WART_BLOCK(),
			"warped_wart_block" => VanillaBlocks::WARPED_WART_BLOCK(),
			"end_stone" => VanillaBlocks::END_STONE(),
			default => null
		};
		if($block !== null){
			return $block;
		}
		if(str_ends_with($id, "_terracotta")){
			$colorName = strtoupper(substr($id, 0, -11));
			foreach(DyeColor::cases() as $color){
				if($color->name === $colorName){
					return VanillaBlocks::STAINED_CLAY()->setColor($color);
				}
			}
		}
		throw new \RuntimeException("No Bedrock equivalent known for Java block $javaId");
	}
}
