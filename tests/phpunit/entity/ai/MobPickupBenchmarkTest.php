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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\goal\PickupItemsGoal;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\MobEquipment;
use pocketmine\entity\monster\Skeleton;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Zombie;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\World;
use function count;
use function fwrite;
use function getenv;
use function hrtime;
use function intdiv;
use function min;
use function mt_rand;
use function mt_srand;
use function sprintf;
use const PHP_INT_MAX;
use const STDERR;

/**
 * Tick cost of item pickup (behavior.pickup_items): the same zombies and skeletons, once unable and once able to pick
 * up loot, in three setups:
 * - among item entities nobody may take (scanned by every search, never picked up);
 * - among stacks of bones they may take, one at a time, their hands emptied every second: they keep looking for
 *   items, walking to them and picking them up;
 * - each next to a diamond sword walled in and roofed with stone: wanted but never reachable.
 * Small counts run as a normal test; set MOB_BENCH=1 for full sizes, alternating both kinds of runs, and the best of
 * each printed to stderr.
 *
 * @group benchmark
 */
final class MobPickupBenchmarkTest extends MobTestCase{

	private const SETUP_DISPLAY = 0;
	private const SETUP_TAKEABLE = 1;
	private const SETUP_WALLED = 2;

	/** Ticks between two samples of the walled setup (and between two hand clearings of the takeable one). */
	private const SAMPLE_TICKS = 20;

	private function isFullBenchmark() : bool{
		return getenv("MOB_BENCH") === "1";
	}

	private function randomLocation() : Location{
		return new Location(mt_rand(-20, 20) + 0.5, 1, mt_rand(-20, 20) + 0.5, $this->world, 0, 0);
	}

	/**
	 * Centres of the stone rings of the walled setup.
	 *
	 * @return int[]
	 */
	private function ringGrid() : array{
		return $this->isFullBenchmark() ? [-20, -12, -4, 4, 12, 20] : [-4, 4];
	}

	private function buildRings() : void{
		$stone = VanillaBlocks::STONE();
		foreach($this->ringGrid() as $cx){
			foreach($this->ringGrid() as $cz){
				for($dx = -1; $dx <= 1; ++$dx){
					for($dz = -1; $dz <= 1; ++$dz){
						for($y = 1; $y <= 3; ++$y){
							if($dx !== 0 || $dz !== 0 || $y === 3){
								$this->world->setBlockAt($cx + $dx, $y, $cz + $dz, $stone, false);
							}
						}
					}
				}
			}
		}
	}

	private function makeMob(bool $looter, int $i, Location $location) : Zombie|Skeleton{
		$nbt = CompoundTag::create()->setByte(MobEquipment::TAG_CAN_PICK_UP_LOOT, $looter ? 1 : 0);
		$mob = $i % 2 === 0 ? new Zombie($location, $nbt) : new Skeleton($location, $nbt);
		$mob->setPersistent(true);
		return $mob;
	}

	/**
	 * @param int $stat set to: the number of items taken (takeable setup), the average number of mobs walking to an
	 *                  item (walled setup), 0 otherwise
	 *
	 * @return int nanoseconds per mob tick
	 */
	private function measure(bool $looters, int $setup, int $count, int $items, int $ticks, int &$stat) : int{
		mt_srand(777);
		$entities = [];
		$mobs = [];
		if($setup === self::SETUP_WALLED){
			foreach($this->ringGrid() as $cx){
				foreach($this->ringGrid() as $cz){
					$item = new ItemEntity(new Location($cx + 0.5, 1, $cz + 0.5, $this->world, 0, 0), VanillaItems::DIAMOND_SWORD());
					$item->setPickupDelay(0);
					$item->setDespawnDelay(ItemEntity::NEVER_DESPAWN);
					$item->spawnToAll();
					$entities[] = $item;
					foreach([2, -2] as $i => $offset){
						$entities[] = $mobs[] = $this->makeMob($looters, $i, new Location($cx + $offset + 0.5, 1, $cz + 0.5, $this->world, 0, 0));
					}
				}
			}
		}else{
			for($i = 0; $i < $count; ++$i){
				$entities[] = $mobs[] = $this->makeMob($looters, $i, $this->randomLocation());
			}
			for($i = 0; $i < $items; ++$i){
				if($setup === self::SETUP_DISPLAY){
					$item = new ItemEntity($this->randomLocation(), VanillaItems::DIAMOND_SWORD());
					$item->setPickupDelay(ItemEntity::NEVER_DESPAWN);
				}else{
					$item = new ItemEntity($this->randomLocation(), VanillaItems::BONE()->setCount(64));
					$item->setPickupDelay(0);
				}
				$item->setDespawnDelay(ItemEntity::NEVER_DESPAWN);
				$item->spawnToAll();
				$entities[] = $item;
			}
		}
		$this->runTicks(20);
		$elapsed = 0;
		$walking = 0;
		$samples = 0;
		for($done = 0; $done < $ticks; $done += self::SAMPLE_TICKS){
			$start = hrtime(true);
			$this->runTicks(self::SAMPLE_TICKS);
			$elapsed += hrtime(true) - $start;
			if($setup === self::SETUP_TAKEABLE){
				foreach($mobs as $mob){
					$mob->setMainHandItem(VanillaItems::AIR());
				}
			}elseif($setup === self::SETUP_WALLED){
				++$samples;
				foreach($mobs as $mob){
					foreach($mob->getGoalSelector()->getRunningGoals() as $goal){
						if($goal instanceof PickupItemsGoal){
							++$walking;
						}
					}
				}
			}
		}
		$stat = 0;
		foreach($entities as $entity){
			if($setup === self::SETUP_TAKEABLE && $entity instanceof ItemEntity){
				$stat += 64 - ($entity->isClosed() || $entity->isFlaggedForDespawn() ? 0 : $entity->getItem()->getCount());
			}
			if(!$entity->isClosed()){
				$entity->flagForDespawn();
			}
		}
		if($setup === self::SETUP_WALLED){
			$stat = intdiv($walking, $samples);
		}
		foreach($mobs as $mob){
			self::assertTrue($setup === self::SETUP_TAKEABLE || $mob->getMainHandItem()->getTypeId() !== VanillaItems::DIAMOND_SWORD()->getTypeId(), "these swords can't be taken");
		}
		$this->runTicks(1);
		return (int) ($elapsed / $ticks / count($mobs));
	}

	private function compare(int $setup, string $label, int $count, int $items, int $ticks, string $statLabel = "") : void{
		TimingsHandler::setEnabled(false);
		$this->difficulty = World::DIFFICULTY_HARD;
		$this->world->setTime(World::TIME_MIDNIGHT); //no burning
		$full = $this->isFullBenchmark();
		$rounds = $full ? 5 : 1;
		$plain = PHP_INT_MAX;
		$looting = PHP_INT_MAX;
		$plainStat = $lootingStat = 0;
		for($round = 0; $round < $rounds; ++$round){
			//alternate the order: neither kind always runs on a warmer process
			if($round % 2 === 0){
				$plain = min($plain, $this->measure(false, $setup, $count, $items, $ticks, $plainStat));
				$looting = min($looting, $this->measure(true, $setup, $count, $items, $ticks, $lootingStat));
			}else{
				$looting = min($looting, $this->measure(true, $setup, $count, $items, $ticks, $lootingStat));
				$plain = min($plain, $this->measure(false, $setup, $count, $items, $ticks, $plainStat));
			}
		}
		self::assertGreaterThan(0, $plain);
		self::assertGreaterThan(0, $looting);
		self::assertSame(0, $plainStat, "mobs which can't pick up loot never go for items");
		if($full){
			fwrite(STDERR, sprintf(
				"[pickup bench] %s: %.1f us per mob tick without pickup, %.1f with (%+.1f%%)%s\n",
				$label, $plain / 1e3, $looting / 1e3, ($looting / $plain - 1) * 100, $statLabel !== "" ? sprintf(", %s %d", $statLabel, $lootingStat) : ""
			));
		}
		if($setup === self::SETUP_TAKEABLE){
			self::assertGreaterThan(0, $lootingStat, "looters take items");
		}
	}

	public function testLootersAmongDisplayItems() : void{
		$full = $this->isFullBenchmark();
		$this->compare(self::SETUP_DISPLAY, $full ? "100 zombies+skeletons among 40 display items" : "display items", $full ? 100 : 20, $full ? 40 : 10, $full ? 600 : 40);
	}

	public function testLootersTakingItems() : void{
		$full = $this->isFullBenchmark();
		$this->compare(self::SETUP_TAKEABLE, $full ? "100 zombies+skeletons among 40 bone stacks, hands emptied every second" : "takeable items", $full ? 100 : 20, $full ? 40 : 10, $full ? 600 : 60, "bones taken");
	}

	public function testLootersNextToWalledItems() : void{
		$this->buildRings();
		$full = $this->isFullBenchmark();
		$this->compare(self::SETUP_WALLED, $full ? "72 zombies+skeletons next to 36 walled swords" : "walled items", 0, 0, $full ? 600 : 60, "mobs walking to an item on average");
	}
}
