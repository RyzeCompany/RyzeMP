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

use PHPUnit\Framework\TestCase;
use pocketmine\world\mobs\spawn\BundledSpawnRules;
use pocketmine\world\mobs\spawn\SpawnCondition;
use pocketmine\world\mobs\spawn\SpawnRules;
use pocketmine\world\mobs\spawn\VanillaSpawnRules;
use Symfony\Component\Filesystem\Path;
use function count;
use function str_replace;

/**
 * The spawn rule table shipped with the server (resources/vanilla_spawn_rules.json), used without network.
 */
final class BundledSpawnRulesTest extends TestCase{

	private function load() : SpawnRules{
		$rules = BundledSpawnRules::loadFile(Path::join(\pocketmine\RESOURCE_PATH, BundledSpawnRules::FILE));
		self::assertNotNull($rules, "the bundled table matches VanillaSpawnRules::TAG");
		return $rules;
	}

	public function testContainsEveryPinnedFile() : void{
		$rules = $this->load();
		self::assertStringContainsString(VanillaSpawnRules::TAG, $rules->getSource());
		self::assertNotSame("built-in", $rules->getSource());
		foreach(VanillaSpawnRules::FILES as $file => $_){
			$identifier = "minecraft:" . str_replace(".json", "", $file);
			self::assertNotNull($rules->getPopulation($identifier), "$file is in the bundled rules");
		}
		self::assertSame(SpawnCondition::POPULATION_PILLAGER, $rules->getPopulation("minecraft:pillager"));
		self::assertSame(SpawnCondition::POPULATION_MONSTER, $rules->getPopulation("minecraft:witch"));
		self::assertGreaterThan(100, count($rules->getConditions()));
	}

	public function testHasMoreThanTheBuiltinMobs() : void{
		$rules = $this->load();
		$identifiers = [];
		foreach($rules->getConditions() as $condition){
			$identifiers[$condition->identifier] = true;
		}
		foreach(["minecraft:enderman", "minecraft:witch", "minecraft:slime", "minecraft:ghast", "minecraft:piglin", "minecraft:strider", "minecraft:cod", "minecraft:squid", "minecraft:bat", "minecraft:wolf", "minecraft:pillager_patrol"] as $identifier){
			self::assertArrayHasKey($identifier, $identifiers);
		}
		//Nether and End biomes get rules too (the built-in ones are Overworld only)
		self::assertNotSame([], $rules->forBiomeTags(["nether" => true, "nether_wastes" => true, "monster" => true], false));
		self::assertNotSame([], $rules->forBiomeTags(["the_end" => true, "monster" => true], false));
	}

	public function testRoundTrip() : void{
		$rules = $this->load();
		$again = BundledSpawnRules::decode(BundledSpawnRules::encode($rules));
		self::assertNotNull($again);
		self::assertEquals($rules->getConditions(), $again->getConditions());
		self::assertSame($rules->getPopulations(), $again->getPopulations());
	}

	public function testOtherVersionIsIgnored() : void{
		$data = BundledSpawnRules::encode($this->load());
		$data["tag"] = "v0.0.0";
		self::assertNull(BundledSpawnRules::decode($data));
	}
}
