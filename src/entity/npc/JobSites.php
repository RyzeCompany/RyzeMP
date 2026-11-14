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

namespace pocketmine\entity\npc;

use pocketmine\block\VanillaBlocks;

/**
 * Job site blocks (village workstations) and the villager profession each one gives (Bedrock "minecraft:dweller"
 * preferred_profession / poi job sites). Every cauldron, whatever its content, is a leatherworker's job site.
 */
final class JobSites{
	/**
	 * @var int[]|null block type ID => profession
	 * @phpstan-var array<int, int>|null
	 */
	private static ?array $professions = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * @return int[] block type ID => {@link VillagerV2} profession
	 * @phpstan-return array<int, int>
	 */
	public static function getAll() : array{
		if(self::$professions === null){
			$map = [];
			foreach([
				[VanillaBlocks::COMPOSTER(), VillagerV2::PROFESSION_FARMER],
				[VanillaBlocks::BARREL(), VillagerV2::PROFESSION_FISHERMAN],
				[VanillaBlocks::LOOM(), VillagerV2::PROFESSION_SHEPHERD],
				[VanillaBlocks::FLETCHING_TABLE(), VillagerV2::PROFESSION_FLETCHER],
				[VanillaBlocks::LECTERN(), VillagerV2::PROFESSION_LIBRARIAN],
				[VanillaBlocks::CARTOGRAPHY_TABLE(), VillagerV2::PROFESSION_CARTOGRAPHER],
				[VanillaBlocks::BREWING_STAND(), VillagerV2::PROFESSION_CLERIC],
				[VanillaBlocks::BLAST_FURNACE(), VillagerV2::PROFESSION_ARMORER],
				[VanillaBlocks::GRINDSTONE(), VillagerV2::PROFESSION_WEAPONSMITH],
				[VanillaBlocks::SMITHING_TABLE(), VillagerV2::PROFESSION_TOOLSMITH],
				[VanillaBlocks::SMOKER(), VillagerV2::PROFESSION_BUTCHER],
				[VanillaBlocks::CAULDRON(), VillagerV2::PROFESSION_LEATHERWORKER],
				[VanillaBlocks::WATER_CAULDRON(), VillagerV2::PROFESSION_LEATHERWORKER],
				[VanillaBlocks::LAVA_CAULDRON(), VillagerV2::PROFESSION_LEATHERWORKER],
				[VanillaBlocks::POTION_CAULDRON(), VillagerV2::PROFESSION_LEATHERWORKER],
				[VanillaBlocks::POWDER_SNOW_CAULDRON(), VillagerV2::PROFESSION_LEATHERWORKER],
				[VanillaBlocks::STONECUTTER(), VillagerV2::PROFESSION_MASON],
			] as [$block, $profession]){
				$map[$block->getTypeId()] = $profession;
			}
			self::$professions = $map;
		}
		return self::$professions;
	}

	/**
	 * Profession given by the block type, or null if it isn't a job site.
	 */
	public static function getProfession(int $blockTypeId) : ?int{
		return self::getAll()[$blockTypeId] ?? null;
	}
}
