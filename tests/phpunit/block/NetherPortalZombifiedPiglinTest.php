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

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\math\Axis;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function array_filter;
use function array_values;
use function count;
use function mt_srand;

final class NetherPortalZombifiedPiglinTest extends MobTestCase{

	private const TICKS = 40000;

	/**
	 * Builds a 2x3 portal on an obsidian frame (bottom of the frame at y=0) and returns its top-left portal block.
	 */
	private function buildPortal() : NetherPortal{
		$obsidian = VanillaBlocks::OBSIDIAN();
		for($x = -1; $x <= 2; ++$x){
			$this->world->setBlockAt($x, 0, 0, $obsidian, false);
			$this->world->setBlockAt($x, 4, 0, $obsidian, false);
		}
		for($y = 1; $y <= 3; ++$y){
			$this->world->setBlockAt(-1, $y, 0, $obsidian, false);
			$this->world->setBlockAt(2, $y, 0, $obsidian, false);
			for($x = 0; $x <= 1; ++$x){
				$this->world->setBlockAt($x, $y, 0, VanillaBlocks::NETHER_PORTAL()->setAxis(Axis::X), false);
			}
		}
		$portal = $this->world->getBlockAt(0, 3, 0);
		self::assertInstanceOf(NetherPortal::class, $portal);
		return $portal;
	}

	/**
	 * @return ZombifiedPiglin[]
	 * @phpstan-return list<ZombifiedPiglin>
	 */
	private function piglins() : array{
		return array_values(array_filter($this->world->getEntities(), fn($e) => $e instanceof ZombifiedPiglin));
	}

	private function randomTicks(NetherPortal $portal, int $count) : int{
		for($i = 0; $i < $count; ++$i){
			$portal->onRandomTick();
		}
		$spawned = count($this->piglins());
		foreach($this->piglins() as $piglin){
			$piglin->close();
		}
		return $spawned;
	}

	public function testPortalsSpawnZombifiedPiglins() : void{
		mt_srand(20261021);
		$portal = $this->buildPortal();
		self::assertTrue($portal->ticksRandomly());
		$this->world->setGameRule(GameRules::DO_MOB_SPAWNING, true);

		$this->difficulty = World::DIFFICULTY_HARD;
		$portal->onRandomTick();
		for($i = 0; count($this->piglins()) === 0 && $i < 10000; ++$i){
			$portal->onRandomTick();
		}
		$piglins = $this->piglins();
		self::assertCount(1, $piglins);
		$pos = $piglins[0]->getPosition();
		self::assertSame([0, 1, 0], [$pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()], "it stands on the frame under the portal column");
		$piglins[0]->close();

		//difficulty id / 2000 per random tick: about 60 in 40000 ticks on Hard, 20 on Easy
		$hard = $this->randomTicks($portal, self::TICKS);
		self::assertGreaterThan(35, $hard);
		self::assertLessThan(90, $hard);
		$this->difficulty = World::DIFFICULTY_EASY;
		$easy = $this->randomTicks($portal, self::TICKS);
		self::assertGreaterThan(8, $easy);
		self::assertLessThan(35, $easy);

		$this->difficulty = World::DIFFICULTY_PEACEFUL;
		self::assertSame(0, $this->randomTicks($portal, 4000), "never in Peaceful");
		$this->difficulty = World::DIFFICULTY_HARD;
		$this->world->setGameRule(GameRules::DO_MOB_SPAWNING, false);
		self::assertSame(0, $this->randomTicks($portal, 4000), "never without mob spawning");
	}
}
