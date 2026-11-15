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

namespace pocketmine\world\mobs;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\AiBudget;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Cow;
use pocketmine\world\dimension\DimensionWorldTestCase;
use pocketmine\world\mobs\spawn\SpawnBlockInfo;
use pocketmine\world\mobs\spawn\SpawnRules;
use function mt_srand;

/**
 * Bedrock minecraft:entity_spawned baby chance of naturally spawned mobs.
 */
final class NaturalBabySpawnTest extends DimensionWorldTestCase{
	private const FLOOR_Y = 63;

	protected function setUp() : void{
		parent::setUp();
		AiBudget::configure(true, 100000, 100000, 400, requireNearbyPlayer: false);
		SpawnBlockInfo::clearCache();
		MobDespawner::clearCache();
		$this->fill(-16, self::FLOOR_Y, -16, 31, self::FLOOR_Y, 31, VanillaBlocks::GRASS());
	}

	public function testNaturalCowsAreSometimesCalves() : void{
		$spawner = new NaturalMobSpawner($this->world);
		$rules = SpawnRules::builtin();
		$spawner->setRules($rules);
		$cowRule = null;
		foreach($rules->getConditions() as $condition){
			if($condition->identifier === "minecraft:cow"){
				$cowRule = $condition;
			}
		}
		self::assertNotNull($cowRule);

		mt_srand(4242);
		$babies = 0;
		$total = 0;
		for($i = 0; $i < 800; ++$i){
			foreach($spawner->spawnRuleAt($cowRule, 5, self::FLOOR_Y + 1, 5) as $mob){
				self::assertInstanceOf(Cow::class, $mob);
				++$total;
				if($mob->isBaby()){
					++$babies;
					self::assertSame(Animal::BABY_AGE, $mob->getAge());
				}
				$mob->close();
			}
		}
		self::assertSame(800, $total);
		self::assertEqualsWithDelta(40, $babies, 18, "5% of natural cows are calves");
	}
}
