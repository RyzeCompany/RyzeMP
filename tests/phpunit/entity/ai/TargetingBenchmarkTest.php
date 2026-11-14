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

namespace pocketmine\entity\ai;

use pocketmine\entity\ai\goal\Goal;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Drowned;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\World;
use function fwrite;
use function getenv;
use function hrtime;
use function mt_rand;
use function mt_srand;
use function sprintf;
use const STDERR;

/**
 * Tick cost of mobs holding player targets (target memory, reselection of closer targets) next to a control mob
 * without player targets. Players are real Player objects (built without their constructor) standing still. Small
 * counts run as normal tests; set MOB_BENCH=1 to run 40 mobs of each kind with 12 players for 400 ticks, printed to
 * stderr.
 *
 * @group benchmark
 */
class TargetingBenchmarkTest extends MobTestCase{
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $players = [];

	private function isFullBenchmark() : bool{
		return getenv("MOB_BENCH") === "1";
	}

	private function randomLocation() : Location{
		return new Location(mt_rand(-20, 20) + 0.5, 1, mt_rand(-20, 20) + 0.5, $this->world, 0, 0);
	}

	/**
	 * A survival Player built without its constructor, standing at the location.
	 */
	private function player(Location $location) : Player{
		$player = (new \ReflectionClass(TargetingBenchPlayer::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, $location);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($location->x - 0.3, $location->y, $location->z - 0.3, $location->x + 0.3, $location->y + 1.8, $location->z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, GameMode::SURVIVAL);
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(true);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		(new \ReflectionProperty(Human::class, "inventory"))->setValue($player, new PlayerInventory($player));
		(new \ReflectionProperty(Human::class, "offHandInventory"))->setValue($player, new PlayerOffHandInventory($player));
		$this->world->addEntity($player);
		$this->players[] = $player;
		return $player;
	}

	protected function tearDown() : void{
		foreach($this->players as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		parent::tearDown();
	}

	/**
	 * @phpstan-param \Closure(Location) : Mob $factory
	 * @return float microseconds per world tick
	 */
	private function measure(\Closure $factory, int $mobCount, int $playerCount, int $ticks, int &$targeting) : float{
		mt_srand(777);
		for($i = 0; $i < $playerCount; ++$i){
			$this->player($this->randomLocation());
		}
		$mobs = [];
		for($i = 0; $i < $mobCount; ++$i){
			$mob = $factory($this->randomLocation());
			//standing still: the same distances on every run, only the AI is measured
			$mob->getGoalSelector()->setFlagDisabled(Goal::FLAG_MOVE | Goal::FLAG_JUMP, true);
			$mob->setMaxHealth(1000000);
			$mob->setHealth(1000000);
			$mobs[] = $mob;
		}
		$this->runTicks(40);
		$start = hrtime(true);
		$this->runTicks($ticks);
		$elapsed = hrtime(true) - $start;
		$targeting = 0;
		foreach($mobs as $mob){
			if($mob->getAttackTarget() instanceof Player){
				++$targeting;
			}
		}
		foreach($this->world->getEntities() as $entity){
			if(!$entity instanceof Player){
				$entity->flagForDespawn();
			}
		}
		$this->runTicks(1);
		foreach($this->players as $player){
			$this->world->removeEntity($player);
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->players = [];
		return $elapsed / $ticks / 1e3;
	}

	public function testMobsTargetingPlayers() : void{
		TimingsHandler::setEnabled(false);
		$this->difficulty = World::DIFFICULTY_HARD;
		$this->world->setTime(World::TIME_MIDNIGHT);
		$this->world->stopTime();
		$full = $this->isFullBenchmark();
		$mobCount = $full ? 40 : 5;
		$playerCount = $full ? 12 : 3;
		$ticks = $full ? 400 : 40;
		$kinds = [
			"cow (control)" => fn(Location $l) => new Cow($l),
			"zombie" => fn(Location $l) => new Zombie($l),
			"skeleton" => fn(Location $l) => new Skeleton($l),
			"drowned" => fn(Location $l) => new Drowned($l)
		];
		$filter = getenv("TARGETING_BENCH_ONLY");
		$measured = 0;
		foreach($kinds as $name => $factory){
			if($filter !== false && $filter !== "" && $filter !== $name){
				continue;
			}
			$targeting = 0;
			$perTick = $this->measure($factory, $mobCount, $playerCount, $ticks, $targeting);
			self::assertGreaterThan(0, $perTick);
			if($full){
				fwrite(STDERR, sprintf("[targeting bench] %-14s %d mobs, %d players: %7.1f us per world tick (%d targeting players)\n", $name, $mobCount, $playerCount, $perTick, $targeting));
			}
			++$measured;
		}
		self::assertGreaterThan(0, $measured);
	}
}

/**
 * A player standing still that takes no damage (the fast location path of the scans: no overridden location methods).
 */
class TargetingBenchPlayer extends Player{
	public function attack(EntityDamageEvent $source) : void{

	}
}
