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

namespace pocketmine\world;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\redstone\RedstoneTestCase;

/**
 * Entities beyond the simulation distance of every player are frozen (chunk-ticking.simulation-distance).
 */
final class SimulationDistanceTest extends RedstoneTestCase{
	private int $simulationDistance = 4;

	protected function getPocketmineYml() : array{
		return ["chunk-ticking" => ["simulation-distance" => $this->simulationDistance]];
	}

	private function dropItem() : ItemEntity{
		//falls through the air of the empty chunk (no floor)
		$item = new ItemEntity(Location::fromObject(new Vector3(16.5, 40, 16.5), $this->world), VanillaItems::STICK());
		$item->spawnToAll();
		return $item;
	}

	public function testDefaultIsVanilla() : void{
		self::assertSame(World::DEFAULT_SIMULATION_DISTANCE, $this->world->getSimulationDistance());
		//the random tick radius never exceeds the simulation distance
		self::assertLessThanOrEqual($this->world->getSimulationDistance(), $this->world->getChunkTickRadius());
	}

	public function testEntityOutsideSimulationIsFrozen() : void{
		$item = $this->dropItem();
		$this->runTicks(2); //first update always runs
		$y = $item->getPosition()->y;
		$age = $item->getDespawnDelay();
		$this->runTicks(40);
		self::assertSame($y, $item->getPosition()->y, "frozen item must not fall");
		self::assertSame($age, $item->getDespawnDelay(), "frozen item must not age");
		self::assertSame($this->tick, $item->lastUpdate, "the clock of a frozen entity does not advance");
		self::assertFalse($this->world->isChunkSimulated(1, 1));

		//a chunk ticker (e.g. a player in range) makes it run again, with a tick difference of 1
		$ticker = new ChunkTicker();
		$this->world->registerSimulatingChunk($ticker, 1, 1);
		self::assertTrue($this->world->isChunkSimulated(1, 1));
		$this->runTicks(5);
		self::assertLessThan($y, $item->getPosition()->y);
		self::assertSame($age - 5, $item->getDespawnDelay());

		$this->world->unregisterSimulatingChunk($ticker, 1, 1);
		self::assertFalse($this->world->isChunkSimulated(1, 1));
		$y = $item->getPosition()->y;
		$this->runTicks(5);
		self::assertSame($y, $item->getPosition()->y);
		$item->close();
	}

	/**
	 * A thrown pearl that flies out of the simulation distance keeps flying (it used to hang in mid-air for good), and
	 * lands: then it is gone, the owner (none here) would be teleported.
	 */
	public function testProjectileInFlightKeepsFlying() : void{
		self::assertFalse($this->world->isChunkSimulated(1, 1));
		$pearl = new EnderPearl(Location::fromObject(new Vector3(16.5, 40, 16.5), $this->world), null);
		$pearl->setMotion(new Vector3(0.5, 0.5, 0));
		$pearl->spawnToAll();
		$this->runTicks(2);
		$x = $pearl->getPosition()->x;
		$this->runTicks(10);
		self::assertFalse($pearl->isClosed());
		self::assertEqualsWithDelta($x + 5.0, $pearl->getPosition()->x, 0.001, "the pearl keeps flying east at 0.5 blocks/tick");
		self::assertLessThan(0.5, $pearl->getMotion()->y, "gravity still applies");

		//an item outside the simulation distance still freezes
		$item = $this->dropItem();
		$this->runTicks(2);
		$y = $item->getPosition()->y;
		$this->runTicks(5);
		self::assertSame($y, $item->getPosition()->y);
		$item->close();
		$pearl->close();
	}

	/**
	 * Projectiles without a movement update (an arrow stuck in a block) are frozen outside the simulation distance like
	 * any other entity: they don't age there.
	 */
	public function testStuckArrowOutsideSimulationIsFrozen() : void{
		$this->world->setBlockAt(16, 39, 16, VanillaBlocks::STONE());
		$arrow = new Arrow(Location::fromObject(new Vector3(16.5, 40.5, 16.5), $this->world), null, false);
		$arrow->setMotion(new Vector3(0, -1, 0));
		$arrow->spawnToAll();
		$this->runTicks(4);
		self::assertFalse($arrow->hasMovementUpdate(), "the arrow is stuck in the stone");
		$lastUpdate = $arrow->lastUpdate;
		$this->runTicks(10);
		self::assertSame($lastUpdate, $arrow->lastUpdate, "a stuck arrow is frozen");
		$arrow->close();
	}

	public function testRandomTickingChunksAreSimulated() : void{
		$item = $this->dropItem();
		$this->world->registerTickingChunk(new ChunkTicker(), 1, 1);
		$this->runTicks(2);
		$y = $item->getPosition()->y;
		$this->runTicks(5);
		self::assertLessThan($y, $item->getPosition()->y);
		$item->close();
	}

	public function testUnlimitedSimulationTicksEverything() : void{
		$this->world->setSimulationDistance(0);
		self::assertTrue($this->world->isChunkSimulated(1, 1));
		$item = $this->dropItem();
		$this->runTicks(2);
		$y = $item->getPosition()->y;
		$this->runTicks(5);
		self::assertLessThan($y, $item->getPosition()->y);
		$item->close();
	}
}
