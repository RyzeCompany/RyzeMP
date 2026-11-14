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
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\npc\trade\ExplorerMaps;
use pocketmine\entity\npc\trade\TradeContext;
use pocketmine\entity\npc\trade\TradeTables;
use pocketmine\item\Armor;
use pocketmine\item\FilledMap;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\MapDecorationType;
use pocketmine\player\Player;
use pocketmine\utils\Random;
use pocketmine\world\generator\structure\StructureLocator;
use pocketmine\world\generator\structure\StructureLocators;
use pocketmine\world\map\MapData;
use pocketmine\world\map\MapDataStore;
use pocketmine\world\map\MapManager;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Filesystem;
use function count;
use function sys_get_temp_dir;
use function uniqid;

final class TradeExtrasTest extends MobTestCase{

	private function villager(float $x, float $z, int $profession) : VillagerV2{
		$villager = new VillagerV2(new Location($x, 1, $z, $this->world, 0, 0));
		$villager->setAge(0);
		$villager->setProfession($profession);
		$villager->spawnToAll();
		return $villager;
	}

	private function mockPlayer() : Player{
		$player = $this->createMock(Player::class);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	/**
	 * @return FilledMap[]
	 * @phpstan-return list<FilledMap>
	 */
	private static function maps(TradeContext $context) : array{
		$maps = [];
		foreach(TradeTables::createOffers("cartographer", 1, $context) as $offer){
			$sell = $offer->getSell();
			if($sell instanceof FilledMap){
				$maps[] = $sell;
			}
		}
		return $maps;
	}

	public function testExplorerMapsNeedAMapMaker() : void{
		for($seed = 0; $seed < 20; ++$seed){
			self::assertCount(0, self::maps(new TradeContext(new Random($seed))), "no explorer maps without a locator");
		}
	}

	public function testCartographerExplorerMapsFollowTheBiome() : void{
		$destinations = [];
		$maker = function(Item $map, string $destination) use (&$destinations) : Item{
			$destinations[$destination] = true;
			$info = ExplorerMaps::getDestination($destination);
			self::assertNotNull($info);
			self::assertInstanceOf(FilledMap::class, $map);
			return (clone $map)->setMapType($info[1])->setMapId(42);
		};
		$found = 0;
		for($seed = 0; $seed < 40; ++$seed){
			foreach(self::maps(new TradeContext(new Random($seed), VillagerV2::TYPE_PLAINS, true, $maker)) as $map){
				self::assertSame(42, $map->getMapId());
				++$found;
			}
		}
		self::assertGreaterThan(0, $found, "level 1 cartographers sell explorer maps");
		//plains villagers (mark variant 0) sell taiga and savanna village maps, never maps to their own kind of village
		self::assertArrayNotHasKey("village_plains", $destinations);
		self::assertArrayNotHasKey("jungle_temple", $destinations);

		$destinations = [];
		for($seed = 0; $seed < 20; ++$seed){
			self::maps(new TradeContext(new Random($seed), VillagerV2::TYPE_PLAINS, false, $maker));
		}
		self::assertCount(0, $destinations, "explorer maps are only sold in the overworld");
	}

	public function testExplorerMapPointsAtTheNearestStructure() : void{
		$path = sys_get_temp_dir() . "/pm-explorer-maps-" . uniqid();
		$manager = new MapManager(new MapDataStore($path));
		MapManager::setInstance($manager);
		$locator = new class implements StructureLocator{
			/**
			 * @var string[]
			 * @phpstan-var list<string>
			 */
			public array $searched = [];

			public function getStructureIds() : array{
				return ["village_desert", "village_plains", "village_savanna", "village_snowy", "village_taiga", "swamp_hut", "jungle_temple"];
			}

			public function findNearest(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly) : ?Vector3{
				$this->searched[] = $structureId;
				return $structureId !== "jungle_temple" ? new Vector3(600, 70, -300) : null;
			}
		};
		StructureLocators::registerForWorld($this->world, $locator);
		try{
			$map = ExplorerMaps::create(VanillaItems::FILLED_MAP(), "village_desert", $this->world, new Vector3(10, 1, 10));
			self::assertInstanceOf(FilledMap::class, $map);
			self::assertSame(["village_desert"], $locator->searched);
			self::assertSame(FilledMap::TYPE_VILLAGE_DESERT, $map->getMapType());
			self::assertSame(ExplorerMaps::SCALE, $map->getScale());
			$data = $manager->getMap($map->getMapId());
			self::assertNotNull($data);
			self::assertSame(MapData::alignCenter(600, -300, ExplorerMaps::SCALE), [$data->getCenterX(), $data->getCenterZ()]);
			self::assertSame([[MapDecorationType::VILLAGE_DESERT, 600, -300]], $data->getDecorations());
			self::assertNotNull(MapManager::createFixedMarker($data, MapDecorationType::VILLAGE_DESERT, 600, -300));

			$reloaded = MapData::fromNbt($data->toNbt());
			self::assertSame($data->getDecorations(), $reloaded->getDecorations(), "markers are saved");

			self::assertNull(ExplorerMaps::create(VanillaItems::FILLED_MAP(), "monument", $this->world, new Vector3(0, 1, 0)), "the locator can't find monuments");

			//nothing found: a plain map of the trader's area
			$plain = ExplorerMaps::create(VanillaItems::FILLED_MAP(), "jungle_temple", $this->world, new Vector3(10, 1, 10));
			self::assertInstanceOf(FilledMap::class, $plain);
			$plainData = $manager->getMap($plain->getMapId());
			self::assertNotNull($plainData);
			self::assertSame([], $plainData->getDecorations());
			self::assertSame(MapData::alignCenter(10, 10, ExplorerMaps::SCALE), [$plainData->getCenterX(), $plainData->getCenterZ()]);

			//a cartographer of this world sells a map with data behind it
			$villager = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_CARTOGRAPHER);
			$villager->addTradeExperience(10);
			$sold = [];
			foreach($villager->getTradeOffers() as $offer){
				$sell = $offer->getSell();
				if($sell instanceof FilledMap){
					$sold[] = $sell;
				}
			}
			self::assertGreaterThan(0, count($sold), "a cartographer of the plains sells taiga or savanna village maps");
			foreach($sold as $item){
				self::assertNotNull($manager->getMap($item->getMapId()));
			}
			$villager->close();
		}finally{
			StructureLocators::unregisterForWorld($this->world);
			MapManager::reset();
			(new Filesystem())->remove($path);
		}
	}

	public function testDyedLeatherHasAColour() : void{
		$coloured = 0;
		for($seed = 0; $seed < 10; ++$seed){
			foreach([0, 1, 2, 3, 4] as $tier){
				foreach(TradeTables::createOffers("leather_worker", $tier, new TradeContext(new Random($seed))) as $offer){
					$sell = $offer->getSell();
					if($sell instanceof Armor && $sell->getTypeId() === VanillaItems::LEATHER_TUNIC()->getTypeId()){
						self::assertNotNull($sell->getCustomColor(), "leather tunics are dyed");
						++$coloured;
					}
				}
			}
		}
		self::assertGreaterThan(0, $coloured);
	}

	public function testDemandRaisesPricesAfterHeavyTrading() : void{
		$offer = new TradeOffer(VanillaItems::WHEAT()->setCount(20), null, VanillaItems::EMERALD(), 16, 0, 2, true, 0, 0.05);
		self::assertSame(20, TradePrices::getBuyACount($offer, null));

		//bought out: demand + 16 - 0
		$offer->setUses(16);
		$offer->restock();
		self::assertSame(0, $offer->getUses());
		self::assertSame(16, $offer->getDemand());
		self::assertSame(20 + (int) (20 * 0.05 * 16), TradePrices::getBuyACount($offer, null));

		//bought out again: it keeps rising
		$offer->setUses(16);
		$offer->restock();
		self::assertSame(32, $offer->getDemand());
		self::assertSame(52, TradePrices::getBuyACount($offer, null));

		//unused restocks bring it back down, not below 0
		$offer->restock();
		self::assertSame(16, $offer->getDemand());
		$offer->restock();
		$offer->restock();
		self::assertSame(0, $offer->getDemand());
		self::assertSame(20, TradePrices::getBuyACount($offer, null));

		$offer->setUses(10);
		$offer->restock();
		self::assertSame(4, $offer->getDemand(), "10 uses of 16: + 10 - 6");

		$reloaded = TradeOffer::fromNbt($offer->toNbt());
		self::assertSame(4, $reloaded->getDemand(), "demand is saved");
		self::assertSame(0, $offer->toNbt(24)->getInt("demand"), "the price sent to clients already includes it");

		//reputation and hero discounts apply to the base price: floor(p x (m x d + 1)) - floor(m x r) - hero
		self::assertSame(20 + 16 - 6 - 6, TradePrices::getPrice(20, 0.05, 125, 1, 64, 16));
		self::assertSame(1, TradePrices::getPrice(1, 0.05, 0, 0, 64, 10), "floor(1 x 1.5)");
		self::assertSame(2, TradePrices::getPrice(1, 0.05, 0, 0, 64, 20));
	}

	public function testVillagerRestockUpdatesDemand() : void{
		$villager = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_FARMER);
		$offer = $villager->getTradeOffers()[0];
		$offer->setUses($offer->getMaxUses());
		$villager->restock();
		self::assertSame($offer->getMaxUses(), $offer->getDemand());
		$villager->close();
	}

	public function testSleepingVillagersTradeSwimmingOnesDont() : void{
		$player = $this->mockPlayer();
		$villager = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_FARMER);
		$this->runTicks(5);
		self::assertTrue($villager->canTradeWith($player), "standing on the ground");

		$bed = VanillaBlocks::BED()->setFacing(Facing::SOUTH);
		$this->set(2, 1, 0, (clone $bed)->setHead(false));
		$this->set(2, 1, 1, (clone $bed)->setHead(true));
		self::assertTrue($villager->claimBed(new Vector3(2, 1, 1)));
		self::assertTrue($villager->startSleeping());
		$villager->onGround = false;
		self::assertTrue($villager->canTradeWith($player), "sleeping villagers can trade");
		$villager->wakeUp();
		$villager->close();

		$swimmer = $this->villager(5.5, 5.5, VillagerV2::PROFESSION_FARMER);
		$this->runTicks(5);
		$this->set($swimmer->getPosition()->getFloorX(), 1, $swimmer->getPosition()->getFloorZ(), VanillaBlocks::WATER());
		self::assertFalse($swimmer->canTradeWith($player), "not in water");
		$swimmer->close();

		$trader = new WanderingTrader(new Location(-5.5, 1, -5.5, $this->world, 0, 0));
		$trader->spawnToAll();
		$this->runTicks(5);
		self::assertTrue($trader->canTradeWith($player));
		//where it stands now: it may have taken a step
		$this->set($trader->getPosition()->getFloorX(), 1, $trader->getPosition()->getFloorZ(), VanillaBlocks::WATER());
		self::assertFalse($trader->canTradeWith($player), "not in water");
		$trader->close();
	}

	public function testTradeInterestShowsWhatTheTraderGives() : void{
		$wheat = new TradeOffer(VanillaItems::WHEAT()->setCount(20), null, VanillaItems::EMERALD(), 16, 0, 2);
		$bread = new TradeOffer(VanillaItems::EMERALD(), null, VanillaItems::BREAD()->setCount(6), 16, 0, 1);
		$pie = new TradeOffer(VanillaItems::EMERALD(), null, VanillaItems::PUMPKIN_PIE()->setCount(4), 12, 1, 5);
		$offers = [$wheat, $bread, $pie];

		$shown = TradeInterestGoal::getShownItems($offers, VanillaItems::WHEAT());
		self::assertCount(1, $shown);
		self::assertTrue($shown[0]->equals(VanillaItems::EMERALD()));
		$shown = TradeInterestGoal::getShownItems($offers, VanillaItems::EMERALD()->setCount(3));
		self::assertCount(2, $shown, "emeralds: everything it sells");
		self::assertTrue($shown[0]->equals(VanillaItems::BREAD()));
		self::assertCount(0, TradeInterestGoal::getShownItems($offers, VanillaItems::DIAMOND()));
		self::assertCount(0, TradeInterestGoal::getShownItems($offers, VanillaItems::AIR()));
		$pie->setUses(12);
		self::assertCount(1, TradeInterestGoal::getShownItems($offers, VanillaItems::EMERALD()), "used up trades aren't shown");
		$pie->setUses(0);

		$villager = $this->villager(0.5, 0.5, VillagerV2::PROFESSION_FARMER);
		$goal = new TradeInterestGoal($villager, fn(Player $player) : bool => true, function(?Item $item) use ($villager) : void{
			(new \ReflectionMethod(VillagerV2::class, "setShownTradeItem"))->invoke($villager, $item);
		});
		$player = $this->createMock(Player::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$player->method("getLocation")->willReturn(new Location(2.5, 1, 2.5, $this->world, 0, 0));
		(new \ReflectionProperty(TradeInterestGoal::class, "player"))->setValue($goal, $player);
		(new \ReflectionProperty(TradeInterestGoal::class, "shown"))->setValue($goal, TradeInterestGoal::getShownItems($offers, VanillaItems::EMERALD()));
		self::assertNull($villager->getShownTradeItem());
		$goal->start();
		self::assertTrue($villager->getShownTradeItem()?->equals(VanillaItems::BREAD()) ?? false, "shows the first matching trade");
		self::assertSame(1, $villager->getShownTradeItem()?->getCount());
		for($i = 0; $i < TradeInterestGoal::SWITCH_TICKS; ++$i){
			$goal->tick();
		}
		self::assertTrue($villager->getShownTradeItem()?->equals(VanillaItems::PUMPKIN_PIE()) ?? false, "switches every 2 seconds");
		$goal->stop();
		self::assertNull($villager->getShownTradeItem(), "the hand is emptied when it loses interest");
		$villager->close();
	}
}
