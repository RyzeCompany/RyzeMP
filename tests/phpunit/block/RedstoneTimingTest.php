<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\Tile;
use pocketmine\block\utils\LeverFacing;
use pocketmine\block\utils\RedstoneSignal;
use pocketmine\event\block\BlockRedstoneUpdateEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginManager;
use pocketmine\Server;
use pocketmine\world\redstone\ComparatorInput;
use pocketmine\world\redstone\RedstoneCircuit;
use pocketmine\world\World;

final class RedstoneTimingTest extends TestCase{
	private World $world;
	private RedstoneCircuit $circuit;
	/** @var array<string, Block> */
	private array $blocks = [];
	/** @var array<string, Vector3> */
	private array $updates = [];
	/** @var array<string, Tile> */
	private array $tiles = [];
	private int $tick = 0;
	private bool $loaded = true;

	protected function setUp() : void{
		$this->world = $this->createMock(World::class);
		$this->world->method('isLoaded')->willReturn(true);
		$this->world->method('isInWorld')->willReturnCallback(static fn(int $x, int $y, int $z) : bool => $y >= -64 && $y < 320);
		$this->world->method('isChunkLoaded')->willReturnCallback(fn() : bool => $this->loaded);
		$this->world->method('isInLoadedTerrain')->willReturnCallback(fn() : bool => $this->loaded);
		$this->world->expects(self::never())->method('loadChunk');
		$this->world->method('getBlockAt')->willReturnCallback(fn(int $x, int $y, int $z) : Block => $this->read($x, $y, $z));
		$this->world->method('getBlock')->willReturnCallback(fn(Vector3 $p) : Block => $this->read($p->getFloorX(), $p->getFloorY(), $p->getFloorZ()));
		$this->world->method('getTile')->willReturnCallback(fn(Vector3 $p) : ?Tile => $this->tiles[$this->key($p)] ?? null);
		$this->world->method('getTileAt')->willReturnCallback(fn(int $x, int $y, int $z) : ?Tile => $this->tiles["$x:$y:$z"] ?? null);
		$this->world->method('setBlock')->willReturnCallback(function(Vector3 $p, Block $block, bool $update = true) : void{
			$this->put($p->getFloorX(), $p->getFloorY(), $p->getFloorZ(), $block);
			if($update){ RedstoneSignal::notifyNeighbours($this->world, $p); }
		});
		$this->world->method('notifyNeighbourBlockUpdate')->willReturnCallback(function(Vector3 $p) : void{
			foreach([$p, ...array_map(fn(int $side) : Vector3 => $p->getSide($side), Facing::ALL)] as $next){
				$this->updates[$this->key($next)] = $next;
			}
		});
		$this->world->method('useBreakOn')->willReturnCallback(function(Vector3 $p) : bool{
			$this->world->setBlock($p, VanillaBlocks::AIR());
			return true;
		});
		$this->circuit = new RedstoneCircuit($this->world, 0);
		$this->world->method('getRedstoneCircuit')->willReturn($this->circuit);
	}

	private function key(Vector3 $p) : string{ return $p->getFloorX() . ':' . $p->getFloorY() . ':' . $p->getFloorZ(); }

	protected function tearDown() : void{
		foreach($this->tiles as $tile){
			// PHPUnit has already reset the world's mock methods at this point.
			$tile->closed = true;
		}
	}

	private function read(int $x = 0, int $y = 64, int $z = 0) : Block{
		$b = clone ($this->blocks["$x:$y:$z"] ?? VanillaBlocks::AIR());
		$b->position($this->world, $x, $y, $z);
		return $b;
	}

	private function put(int $x, int $y, int $z, Block $b) : void{
		$this->circuit->onBlockChanged($this->read($x, $y, $z));
		$b = clone $b;
		$b->position($this->world, $x, $y, $z);
		$this->blocks["$x:$y:$z"] = $b;
	}

	private function settle() : void{
		$limit = 2000;
		while($this->updates !== []){
			self::assertGreaterThan(0, --$limit, 'Neighbour updates failed to settle');
			$key = array_key_first($this->updates);
			$p = $this->updates[$key];
			unset($this->updates[$key]);
			if($this->loaded){ $this->read($p->getFloorX(), $p->getFloorY(), $p->getFloorZ())->onNearbyBlockChange(); }
		}
	}

	private function advance(int $ticks) : void{
		for($i = 0; $i < $ticks; ++$i){
			$this->circuit->tick(++$this->tick);
			$this->settle();
		}
	}

	private function component(Block $block) : void{
		$this->put(0, 63, 0, VanillaBlocks::STONE());
		$this->put(0, 64, 0, $block);
	}

	public function testAllRepeaterDelaysAndShortPulses() : void{
		foreach([1, 2, 3, 4] as $delay){
			$this->component(VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST)->setDelay($delay));
			$this->put(-1, 64, 0, VanillaBlocks::REDSTONE());
			$this->read()->onNearbyBlockChange();
			// The falling edge cannot overwrite the already queued rising edge.
			$this->put(-1, 64, 0, VanillaBlocks::AIR());
			$this->read()->onNearbyBlockChange();
			$this->advance($delay * 2 - 1);
			self::assertFalse($this->read()->isPowered());
			$this->advance(1);
			self::assertTrue($this->read()->isPowered());
			$this->advance($delay * 2 - 1);
			self::assertTrue($this->read()->isPowered());
			$this->advance(1);
			self::assertFalse($this->read()->isPowered());
		}
	}

	public function testRepeaterUsesOnlyItsRearInputForEveryFacing() : void{
		foreach(Facing::HORIZONTAL as $facing){
			$this->blocks = [];
			$this->component(VanillaBlocks::REDSTONE_REPEATER()->setFacing($facing));
			$p = (new Vector3(0, 64, 0))->getSide($facing);
			$this->put($p->getFloorX(), 64, $p->getFloorZ(), VanillaBlocks::REDSTONE());
			$this->read()->onNearbyBlockChange();
			$this->advance(2);
			self::assertFalse($this->read()->isPowered());
			$p = (new Vector3(0, 64, 0))->getSide(Facing::opposite($facing));
			$this->put($p->getFloorX(), 64, $p->getFloorZ(), VanillaBlocks::REDSTONE());
			$this->read()->onNearbyBlockChange();
			$this->advance(2);
			self::assertTrue($this->read()->isPowered());
		}
	}

	public function testRestoredInputCancelsPendingRepeaterFallingEdge() : void{
		$this->component(VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST)->setPowered(true));
		$this->read()->onNearbyBlockChange();
		$this->advance(1);
		$this->put(-1, 64, 0, VanillaBlocks::REDSTONE());
		$this->read()->onNearbyBlockChange();
		$this->advance(1);
		self::assertTrue($this->read()->isPowered());
	}

	public function testSideDiodesLockRepeatersAndUnlockReevaluatesInput() : void{
		foreach([VanillaBlocks::REDSTONE_REPEATER()->setPowered(true), VanillaBlocks::REDSTONE_COMPARATOR()->setOutputSignalStrength(5)] as $side){
			$this->component(VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST));
			$this->put(-1, 64, 0, VanillaBlocks::REDSTONE());
			$this->put(0, 63, -1, VanillaBlocks::STONE());
			$this->put(0, 64, -1, $side->setFacing(Facing::SOUTH));
			self::assertTrue($this->read()->isLocked());
			$this->read()->onNearbyBlockChange();
			$this->advance(4);
			self::assertFalse($this->read()->isPowered());
			$this->put(0, 64, -1, VanillaBlocks::AIR());
			$this->read()->onNearbyBlockChange();
			$this->advance(2);
			self::assertTrue($this->read()->isPowered());
			$this->put(-1, 64, 0, VanillaBlocks::AIR());
			$this->read()->onNearbyBlockChange();
			$this->put(0, 64, -1, $side->setFacing(Facing::SOUTH));
			$this->advance(2);
			self::assertTrue($this->read()->isPowered(), 'Lock acquired before the pending falling edge');
			$this->put(0, 64, -1, VanillaBlocks::AIR());
			$this->read()->onNearbyBlockChange();
			$this->advance(2);
			self::assertFalse($this->read()->isPowered());
		}
	}

	public function testWireAndLeversDoNotLockRepeaters() : void{
		$this->component(VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST));
		foreach([VanillaBlocks::REDSTONE_WIRE()->setOutputSignalStrength(15), VanillaBlocks::LEVER()->setActivated(true)] as $side){
			$this->put(0, 64, -1, $side);
			self::assertFalse($this->read()->isLocked());
		}
		$this->put(0, 64, -1, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::NORTH)->setPowered(true));
		self::assertFalse($this->read()->isLocked(), 'A diode pointing away cannot lock');
	}

	public function testReplacingBlockInvalidatesPendingPulseEvenWithSilentWrites() : void{
		$this->component(VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST));
		$this->put(-1, 64, 0, VanillaBlocks::REDSTONE());
		$this->read()->onNearbyBlockChange();
		$this->world->setBlock(new Vector3(0, 64, 0), VanillaBlocks::AIR(), false);
		$this->world->setBlock(new Vector3(0, 64, 0), VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST), false);
		$this->advance(2);
		self::assertFalse($this->read()->isPowered());
	}

	public function testElectricalTicksDoNotLoadUnloadedChunks() : void{
		$this->component(VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST));
		$this->put(-1, 64, 0, VanillaBlocks::REDSTONE());
		$this->read()->onNearbyBlockChange();
		$this->loaded = false;
		$this->advance(2);
		$this->loaded = true;
		self::assertFalse($this->read()->isPowered());
		$this->read()->onNearbyBlockChange();
		$this->advance(2);
		self::assertTrue($this->read()->isPowered());
	}

	public function testTorchInvertsSupportPowerAfterTwoGameTicks() : void{
		$this->component(VanillaBlocks::REDSTONE_TORCH());
		$this->put(-1, 63, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::WEST)->setActivated(true));
		$this->read()->onNearbyBlockChange();
		$this->advance(1);
		self::assertTrue($this->read()->isLit());
		$this->advance(1);
		self::assertFalse($this->read()->isLit());
		$this->put(-1, 63, 0, VanillaBlocks::AIR());
		$this->read()->onNearbyBlockChange();
		$this->advance(2);
		self::assertTrue($this->read()->isLit());
	}

	public function testTorchIgnoresPowerBesideItAndNonconductingSupport() : void{
		$this->component(VanillaBlocks::REDSTONE_TORCH());
		$this->put(1, 64, 0, VanillaBlocks::REDSTONE());
		$this->read()->onNearbyBlockChange();
		$this->advance(2);
		self::assertTrue($this->read()->isLit());
		$this->put(-1, 63, 0, VanillaBlocks::REDSTONE());
		$this->read()->onNearbyBlockChange();
		$this->advance(2);
		self::assertTrue($this->read()->isLit(), 'Weak sources beside stone do not energize it');
		$this->put(0, 63, 0, VanillaBlocks::GLASS());
		$this->put(-1, 63, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::WEST)->setActivated(true));
		$this->read()->onNearbyBlockChange();
		$this->advance(2);
		self::assertTrue($this->read()->isLit());
	}

	public function testRemovedSupportBreaksDiodesBeforeTheirElectricalTick() : void{
		foreach([VanillaBlocks::REDSTONE_REPEATER(), VanillaBlocks::REDSTONE_COMPARATOR()] as $b){
			$this->component($b->setFacing(Facing::EAST));
			$this->put(-1, 64, 0, VanillaBlocks::REDSTONE());
			$this->read()->onNearbyBlockChange();
			$this->put(0, 63, 0, VanillaBlocks::AIR());
			$this->advance(2);
			self::assertInstanceOf(Air::class, $this->read());
		}
	}

	public function testComparatorCompareSubtractAndEqualInputs() : void{
		$this->component(VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::EAST));
		$this->put(-1, 64, 0, VanillaBlocks::DAYLIGHT_SENSOR()->setOutputSignalStrength(10));
		$this->put(0, 63, -1, VanillaBlocks::STONE());
		$this->put(0, 64, -1, VanillaBlocks::REDSTONE_WIRE()->setOutputSignalStrength(6));
		$this->read()->onNearbyBlockChange();
		$this->advance(1);
		self::assertSame(0, $this->read()->getOutputSignalStrength());
		// Direct queue ticks avoid recomputing the deliberately injected lateral wire strength.
		$this->circuit->tick(++$this->tick);
		self::assertSame(10, $this->read()->getOutputSignalStrength());
		$this->updates = [];
		$this->put(0, 64, 0, $this->read()->setSubtractMode(true));
		$this->read()->onNearbyBlockChange();
		$this->circuit->tick($this->tick += 2);
		self::assertSame(4, $this->read()->getOutputSignalStrength());
		$this->updates = [];
		$this->put(0, 64, -1, VanillaBlocks::REDSTONE_WIRE()->setOutputSignalStrength(10));
		$this->put(0, 64, 0, $this->read()->setSubtractMode(false));
		$this->read()->onNearbyBlockChange();
		$this->circuit->tick($this->tick += 2);
		self::assertSame(10, $this->read()->getOutputSignalStrength());
		$this->updates = [];
		$this->put(0, 64, -1, VanillaBlocks::REDSTONE_WIRE()->setOutputSignalStrength(11));
		$this->read()->onNearbyBlockChange();
		$this->circuit->tick($this->tick += 2);
		self::assertSame(0, $this->read()->getOutputSignalStrength());
	}

	public function testComparatorRecalculatesInsteadOfLatchingTransientInput() : void{
		$this->component(VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::EAST));
		$this->put(-1, 64, 0, VanillaBlocks::REDSTONE());
		$this->read()->onNearbyBlockChange();
		$this->put(-1, 64, 0, VanillaBlocks::AIR());
		$this->advance(2);
		self::assertSame(0, $this->read()->getOutputSignalStrength());
	}

	public function testPluginsCanCancelAllTimedComponentsAndAdjustComparatorPower() : void{
		$plugin = $this->createStub(Plugin::class);
		$plugin->method('isEnabled')->willReturn(true);
		$manager = new PluginManager($this->createMock(Server::class), null);
		$cancel = true;
		$manager->registerEvent(BlockRedstoneUpdateEvent::class, static function(BlockRedstoneUpdateEvent $event) use (&$cancel) : void{
			if($cancel){ $event->cancel(); }else{ $event->setNewPower(7); }
		}, EventPriority::NORMAL, $plugin);
		try{
			foreach([VanillaBlocks::REDSTONE_REPEATER(), VanillaBlocks::REDSTONE_COMPARATOR()] as $b){
				$this->component($b->setFacing(Facing::EAST));
				$this->put(-1, 64, 0, VanillaBlocks::REDSTONE());
				$this->read()->onNearbyBlockChange();
				$this->advance(2);
				self::assertFalse($this->read()->isPowered());
			}
			$this->component(VanillaBlocks::REDSTONE_TORCH());
			$this->put(-1, 63, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::WEST)->setActivated(true));
			$this->read()->onNearbyBlockChange();
			$this->advance(2);
			self::assertTrue($this->read()->isLit());
			$cancel = false;
			$this->component(VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::EAST));
			$this->read()->onNearbyBlockChange();
			$this->advance(2);
			self::assertSame(7, $this->read()->getOutputSignalStrength());
		}finally{
			HandlerListManager::global()->unregisterAll($plugin);
		}
	}

	public function testInventoryFullnessAccountsForStackLimitsAndOverstacks() : void{
		$inv = new SimpleInventory(2);
		self::assertSame(0, ComparatorInput::getInventoryPower($inv));
		$inv->setItem(0, VanillaItems::DIAMOND()->setCount(1));
		self::assertSame(1, ComparatorInput::getInventoryPower($inv));
		$inv->setItem(0, VanillaItems::DIAMOND_SWORD());
		self::assertSame(8, ComparatorInput::getInventoryPower($inv));
		$inv->setItem(1, VanillaItems::DIAMOND()->setCount(64));
		self::assertSame(15, ComparatorInput::getInventoryPower($inv));
		$inv->setItem(0, VanillaItems::DIAMOND()->setCount(127));
		self::assertSame(15, ComparatorInput::getInventoryPower($inv));
		$inv->clearAll();
		$inv->setMaxStackSize(16);
		$inv->setItem(0, VanillaItems::DIAMOND()->setCount(16));
		self::assertSame(8, ComparatorInput::getInventoryPower($inv));
	}

	private function chest(int $x, int $z = 0) : Chest{
		$this->put($x, 64, $z, VanillaBlocks::CHEST());
		$tile = new Chest($this->world, new Vector3($x, 64, $z));
		$this->tiles["$x:64:$z"] = $tile;
		return $tile;
	}

	public function testContainerChangesWakeComparatorThroughOneSolidBlock() : void{
		$this->component(VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::EAST));
		$this->put(-1, 64, 0, VanillaBlocks::STONE());
		$inv = $this->chest(-2)->getInventory();
		$this->read()->onNearbyBlockChange();
		$this->advance(2);
		self::assertSame(0, $this->read()->getOutputSignalStrength());
		$inv->setItem(0, VanillaItems::DIAMOND()->setCount(64));
		$this->settle();
		$this->advance(2);
		self::assertSame(1, $this->read()->getOutputSignalStrength());
		$inv->setContents(array_fill(0, 27, VanillaItems::DIAMOND()->setCount(64)));
		$this->settle();
		$this->advance(2);
		self::assertSame(15, $this->read()->getOutputSignalStrength());
		$inv->clearAll();
		$this->settle();
		$this->advance(2);
		self::assertSame(0, $this->read()->getOutputSignalStrength());
	}

	public function testContainerIsNotReadThroughGlassOrTwoSolids() : void{
		$this->component(VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::EAST));
		$this->put(-1, 64, 0, VanillaBlocks::GLASS());
		$this->chest(-2)->getInventory()->setContents(array_fill(0, 27, VanillaItems::DIAMOND()->setCount(64)));
		$this->read()->onNearbyBlockChange();
		$this->advance(2);
		self::assertSame(0, $this->read()->getOutputSignalStrength());
		$this->put(-1, 64, 0, VanillaBlocks::STONE());
		$this->put(-2, 64, 0, VanillaBlocks::STONE());
		unset($this->tiles['-2:64:0']);
		$this->chest(-3)->getInventory()->setContents(array_fill(0, 27, VanillaItems::DIAMOND()->setCount(64)));
		$this->read()->onNearbyBlockChange();
		$this->advance(2);
		self::assertSame(0, $this->read()->getOutputSignalStrength());
	}

	public function testChangingOtherHalfOfDoubleChestNotifiesComparator() : void{
		$this->component(VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::EAST));
		$a = $this->chest(-1);
		$b = $this->chest(-1, 1);
		$a->pairWith($b);
		$b->getRealInventory()->setContents(array_fill(0, 27, VanillaItems::DIAMOND()->setCount(64)));
		$this->settle();
		$this->advance(2);
		self::assertSame(8, $this->read()->getOutputSignalStrength());
		$b->getRealInventory()->clearAll();
		$this->settle();
		$this->advance(2);
		self::assertSame(0, $this->read()->getOutputSignalStrength());
	}
}
