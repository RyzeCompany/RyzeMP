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

use PHPUnit\Framework\TestCase;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TrialSpawner as TileTrialSpawner;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\structure\template\StructureBlockEntities;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use function getenv;
use function is_file;

final class StructureTrialSpawnerTest extends TestCase{

	public function testTrialChamberSpawnerKeepsItsConfigs() : void{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_file($dir . "/data/minecraft/structure/trial_chambers/spawner/melee/zombie.nbt")){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no structure templates");
		}
		$blocks = JavaBlocks::get($dir);
		$template = StructureTemplates::shared(new WorldgenData($dir), $blocks)->get("minecraft:trial_chambers/spawner/melee/zombie");
		$found = 0;
		foreach($template->blocks(0) as $packed){
			$position = $packed & 0x3fffffff;
			$nbt = $template->blockNbt[$position] ?? null;
			$state = $template->states[0][$packed >> 30] ?? null;
			if($nbt === null || $state === null || $blocks->blockName($state) !== "minecraft:trial_spawner"){
				continue;
			}
			$tile = StructureBlockEntities::create($blocks, $state, $nbt);
			self::assertNotNull($tile);
			self::assertSame("TrialSpawner", $tile->getString(Tile::TAG_ID));
			self::assertSame("minecraft:trial_chamber/melee/zombie/normal", $tile->getString(TileTrialSpawner::TAG_NORMAL_CONFIG));
			self::assertSame("minecraft:trial_chamber/melee/zombie/ominous", $tile->getString(TileTrialSpawner::TAG_OMINOUS_CONFIG));
			++$found;
		}
		self::assertSame(1, $found);
	}
}
