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

namespace pocketmine\world\map;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\Random;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use function sys_get_temp_dir;
use function uniqid;

/**
 * MapManager finds the players holding a map without cloning the held items of every player every tick: it checks
 * the slots' item type and fetches the item only when it is a map (or when the inventory is a subclass, which may
 * override getItem()). The holders found are the same as when every held item was fetched.
 */
final class MapHoldersEquivalenceTest extends TestCase{
	private const WORLD = "map_test";

	private string $path;
	private ?Server $serverInstance = null;

	protected function setUp() : void{
		$this->path = Path::join(sys_get_temp_dir(), uniqid("pm_map_holders_", true));
		$instance = (new \ReflectionProperty(Server::class, "instance"))->getValue();
		$this->serverInstance = $instance instanceof Server ? $instance : null;
	}

	protected function tearDown() : void{
		(new \ReflectionProperty(Server::class, "instance"))->setValue(null, $this->serverInstance);
		(new Filesystem())->remove($this->path);
	}

	/**
	 * The holder search of MapManager::drawForHolders() before the change, verbatim apart from the drawing (all the
	 * maps of this test which are in the players' world are locked: nothing is drawn).
	 *
	 * @param Player[] $players
	 * @phpstan-param list<Player> $players
	 * @phpstan-return array<int, list<array{Player, bool}>>
	 */
	private static function oldHolders(MapManager $manager, array $players) : array{
		$holders = [];
		foreach($players as $player){
			if(!$player->isAlive() || $player->isSpectator()){
				continue;
			}
			foreach([$player->getInventory()->getItemInHand(), $player->getOffHandInventory()->getItem(0)] as $item){
				if(!$item instanceof FilledMap || $item->getMapId() === 0){
					continue;
				}
				$map = $manager->getMap($item->getMapId());
				if($map === null || $map->getWorldName() !== $player->getWorld()->getFolderName()){
					continue;
				}
				$holders[$map->getId()][] = [$player, $item->displaysPlayers()];
				if(!$map->isLocked()){
					self::fail("the test maps of the players' world are locked");
				}
				break;
			}
		}
		return $holders;
	}

	private static function randomItem(Random $random) : Item{
		return match($random->nextBoundedInt(6)){
			0 => VanillaItems::AIR(),
			1 => VanillaItems::STICK(),
			2 => VanillaItems::EMPTY_MAP(),
			//IDs 1-2: locked maps of the players' world, 3-4: maps of another world, 5: allocated but never saved,
			//6: never allocated, 0: no map
			default => VanillaItems::FILLED_MAP()->setMapId($random->nextBoundedInt(7))->setDisplayPlayers($random->nextBoolean())
		};
	}

	public function testSameHoldersAsFetchingEveryHeldItem() : void{
		$store = new MapDataStore($this->path);
		for($id = 1; $id <= 5; ++$id){
			self::assertSame($id, $store->allocateId());
			if($id <= 4){
				$store->save(new MapData($id, $id <= 2 ? self::WORLD : "elsewhere", 0, 0, 0, $id <= 2 || $id === 4));
			}
		}
		$manager = new MapManager($store);
		$world = $this->createMock(World::class);
		$world->method("getFolderName")->willReturn(self::WORLD);

		$random = new Random(1018);
		$sawMap = 0;
		for($round = 0; $round < 300; ++$round){
			$players = [];
			for($i = 0, $count = 1 + $random->nextBoundedInt(8); $i < $count; ++$i){
				$player = $this->createMock(Player::class);
				(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
				(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
				$player->method("isAlive")->willReturn($random->nextBoundedInt(8) !== 0);
				$player->method("isSpectator")->willReturn($random->nextBoundedInt(8) === 0);
				$player->method("getWorld")->willReturn($world);

				//a plugin's inventory class may override getItem(): it must still be asked for the held item
				$inventory = $random->nextBoundedInt(5) === 0 ? new MapHoldersTestInventory($player) : new PlayerInventory($player);
				for($slot = 0; $slot < 9; ++$slot){
					$inventory->setItem($slot, self::randomItem($random));
				}
				$inventory->setHeldItemIndex($random->nextBoundedInt(9));
				$offHand = new PlayerOffHandInventory($player);
				$offHand->setItem(0, self::randomItem($random));
				$player->method("getInventory")->willReturn($inventory);
				$player->method("getOffHandInventory")->willReturn($offHand);
				$players[] = $player;

				if(!$inventory instanceof MapHoldersTestInventory){
					for($slot = 0; $slot < $inventory->getSize(); ++$slot){
						self::assertSame($inventory->getItem($slot) instanceof FilledMap, $inventory->isSlotInstanceOf($slot, FilledMap::class));
					}
				}
				self::assertSame($offHand->getItem(0) instanceof FilledMap, $offHand->isSlotInstanceOf(0, FilledMap::class));
			}

			$server = $this->createMock(Server::class);
			$server->method("getOnlinePlayers")->willReturn($players);
			(new \ReflectionProperty(Server::class, "instance"))->setValue(null, $server);

			$expected = self::oldHolders($manager, $players);
			$sawMap += $expected !== [] ? 1 : 0;
			self::assertSame($expected, (fn() => $this->drawForHolders())->call($manager), "round $round");
		}
		self::assertGreaterThan(50, $sawMap);
	}
}

/**
 * A plugin's player inventory: every hotbar slot reads as a map of the players' world.
 */
final class MapHoldersTestInventory extends PlayerInventory{
	public function getItem(int $index) : Item{
		return $index < 9 ? VanillaItems::FILLED_MAP()->setMapId(1) : parent::getItem($index);
	}
}
