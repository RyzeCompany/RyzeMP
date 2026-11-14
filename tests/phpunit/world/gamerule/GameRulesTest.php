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

namespace pocketmine\world\gamerule;

use pocketmine\block\Fire;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\world\WorldGameRuleChangeEvent;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\io\data\BedrockWorldData;
use pocketmine\world\GameRules;
use pocketmine\world\WorldCreationOptions;
use function array_filter;
use function count;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Game rules of a world (/gamerule): defaults, validation, saving in level.dat and their effect on the world.
 */
final class GameRulesTest extends MobTestCase{

	private function itemEntityCount() : int{
		return count(array_filter($this->world->getEntities(), fn($e) => $e instanceof ItemEntity && !$e->isClosed()));
	}

	public function testDefaults() : void{
		foreach(GameRules::getNames() as $rule){
			self::assertSame(GameRules::getDefault($rule), $this->world->getGameRule($rule), $rule);
		}
		self::assertSame(1, $this->world->getGameRule(GameRules::RANDOM_TICK_SPEED));
		self::assertFalse($this->world->getGameRule(GameRules::KEEP_INVENTORY));
	}

	public function testNamesAreCaseInsensitive() : void{
		self::assertSame(GameRules::KEEP_INVENTORY, GameRules::normalizeName("keepInventory"));
		self::assertNull(GameRules::normalizeName("nosuchrule"));
		self::assertTrue($this->world->setGameRule("keepInventory", true));
		self::assertTrue($this->world->getGameRule(GameRules::KEEP_INVENTORY));
	}

	public function testValueMustHaveTheRuleType() : void{
		$this->expectException(\InvalidArgumentException::class);
		$this->world->setGameRule(GameRules::KEEP_INVENTORY, 1);
	}

	public function testIntRuleRejectsNegative() : void{
		$this->expectException(\InvalidArgumentException::class);
		$this->world->setGameRule(GameRules::RANDOM_TICK_SPEED, -1);
	}

	public function testDaylightCycleStopsTime() : void{
		$this->world->setGameRule(GameRules::DO_DAYLIGHT_CYCLE, false);
		self::assertTrue($this->world->stopTime);
		$time = $this->world->getTime();
		$this->runTicks(20);
		self::assertSame($time, $this->world->getTime());

		$this->world->setGameRule(GameRules::DO_DAYLIGHT_CYCLE, true);
		self::assertFalse($this->world->stopTime);
		$this->runTicks(20);
		self::assertGreaterThan($time, $this->world->getTime());

		//plugins stopping the time directly are reflected in the rule
		$this->world->stopTime();
		self::assertFalse($this->world->getGameRule(GameRules::DO_DAYLIGHT_CYCLE));
		$this->world->startTime();
	}

	public function testWeatherAndMobSpawningRulesUseTheWorldState() : void{
		$this->world->setGameRule(GameRules::DO_WEATHER_CYCLE, false);
		self::assertFalse($this->world->getWeather()->isWeatherCycleEnabled());
		$this->world->setGameRule(GameRules::DO_MOB_SPAWNING, false);
		self::assertFalse($this->world->isMobSpawningEnabled());
	}

	public function testEventCanCancelOrReplace() : void{
		$plugin = $this->createMock(Plugin::class);
		$plugin->method("isEnabled")->willReturn(true);
		$handlers = HandlerListManager::global()->getListFor(WorldGameRuleChangeEvent::class);
		$listener = new RegisteredListener(function(WorldGameRuleChangeEvent $ev) : void{
			if($ev->getRule() === GameRules::PVP){
				$ev->cancel();
			}elseif($ev->getRule() === GameRules::RANDOM_TICK_SPEED){
				$ev->setNewValue(3);
			}
		}, EventPriority::NORMAL, $plugin, false, new TimingsHandler("test"));
		$handlers->register($listener);
		try{
			self::assertFalse($this->world->setGameRule(GameRules::PVP, false));
			self::assertTrue($this->world->getGameRule(GameRules::PVP));
			self::assertTrue($this->world->setGameRule(GameRules::RANDOM_TICK_SPEED, 10));
			self::assertSame(3, $this->world->getGameRule(GameRules::RANDOM_TICK_SPEED));
		}finally{
			$handlers->unregister($listener);
		}
	}

	public function testFireTickOff() : void{
		$this->set(0, 1, 0, VanillaBlocks::OAK_PLANKS());
		$this->set(1, 1, 0, VanillaBlocks::FIRE());
		$this->world->setGameRule(GameRules::DO_FIRE_TICK, false);
		for($i = 0; $i < 200; ++$i){
			$fire = $this->get(1, 1, 0);
			self::assertInstanceOf(Fire::class, $fire);
			$fire->onRandomTick();
		}
		$fire = $this->get(1, 1, 0);
		self::assertInstanceOf(Fire::class, $fire);
		self::assertSame(0, $fire->getAge(), "the fire neither ages nor burns out");
		self::assertTrue($this->get(0, 1, 0)->isSameState(VanillaBlocks::OAK_PLANKS()), "nothing burns");
	}

	public function testMobLootOff() : void{
		$this->world->setGameRule(GameRules::DO_MOB_LOOT, false);
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->spawnToAll();
		$cow->kill();
		$this->runTicks(30);
		self::assertSame(0, $this->itemEntityCount());
	}

	public function testMobLootOn() : void{
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->spawnToAll();
		$cow->kill();
		$this->runTicks(30);
		self::assertGreaterThan(0, $this->itemEntityCount(), "control: a cow drops leather or beef by default");
	}

	public function testTileDropsOff() : void{
		$this->set(2, 1, 2, VanillaBlocks::OAK_PLANKS());
		$this->world->setGameRule(GameRules::DO_TILE_DROPS, false);
		$this->breakBlock(2, 1, 2);
		self::assertTrue($this->get(2, 1, 2)->isSameState(VanillaBlocks::AIR()));
		self::assertSame(0, $this->itemEntityCount());

		$this->world->setGameRule(GameRules::DO_TILE_DROPS, true);
		$this->set(2, 1, 2, VanillaBlocks::OAK_PLANKS());
		$this->breakBlock(2, 1, 2);
		self::assertSame(1, $this->itemEntityCount());
	}

	public function testSavedInLevelDat() : void{
		$path = sys_get_temp_dir() . "/pm-gamerule-test-" . uniqid();
		mkdir($path);
		try{
			BedrockWorldData::generate($path, "rules", WorldCreationOptions::create());
			$data = new BedrockWorldData($path . "/level.dat");
			self::assertNull($data->getGameRule(GameRules::KEEP_INVENTORY), "not stored until changed: the default applies");
			$data->setGameRule(GameRules::KEEP_INVENTORY, true);
			$data->setGameRule(GameRules::RANDOM_TICK_SPEED, 4);
			$data->save();

			$reloaded = new BedrockWorldData($path . "/level.dat");
			self::assertTrue($reloaded->getGameRule(GameRules::KEEP_INVENTORY));
			self::assertSame(4, $reloaded->getGameRule(GameRules::RANDOM_TICK_SPEED));
			self::assertTrue($reloaded->getGameRule(GameRules::DO_WEATHER_CYCLE), "vanilla tag written by generate()");
		}finally{
			Filesystem::recursiveUnlink($path);
		}
	}
}
