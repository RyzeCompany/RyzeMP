<?php

declare(strict_types=1);

namespace pocketmine\block;

use PHPUnit\Framework\TestCase;
use pocketmine\block\utils\LeverFacing;
use pocketmine\block\utils\RedstonePowerSource;
use pocketmine\block\utils\RedstoneSignal;
use pocketmine\event\block\BlockRedstoneUpdateEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginManager;
use pocketmine\Server;
use pocketmine\world\World;

final class NativeRedstoneTest extends TestCase{
	private World $world;
	/** @var array<string, Block> */
	private array $blocks = [];
	/** @var array<string, Vector3> */
	private array $updates = [];
	/** @var list<int> */
	private array $delays = [];
	/** @var array<string, true> */
	private array $unloaded = [];

	protected function setUp() : void{
		$this->world = $this->createMock(World::class);
		$this->world->method('isLoaded')->willReturn(true);
		$this->world->method('isInWorld')->willReturnCallback(static fn(int $x, int $y, int $z) : bool => $y >= -64 && $y < 320);
		$this->world->method('isChunkLoaded')->willReturnCallback(fn(int $x, int $z) : bool => !isset($this->unloaded["$x:$z"]));
		$this->world->expects(self::never())->method('loadChunk');
		$this->world->method('getBlockAt')->willReturnCallback(fn(int $x, int $y, int $z) : Block => $this->read($x, $y, $z));
		$this->world->method('getBlock')->willReturnCallback(fn(Vector3 $pos) : Block => $this->read($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()));
		$this->world->method('setBlock')->willReturnCallback(function(Vector3 $pos, Block $block, bool $update = true) : void{
			$this->put($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $block);
			if($update){
				RedstoneSignal::notifyNeighbours($this->world, $pos);
			}
		});
		$this->world->method('notifyNeighbourBlockUpdate')->willReturnCallback(function(Vector3 $pos) : void{
			foreach([$pos, ...array_map(fn(int $side) : Vector3 => $pos->getSide($side), Facing::ALL)] as $next){
				$this->updates[$this->key($next->getFloorX(), $next->getFloorY(), $next->getFloorZ())] = $next;
			}
		});
		$this->world->method('scheduleDelayedBlockUpdate')->willReturnCallback(function(Vector3 $pos, int $delay) : void{
			$this->delays[] = $delay;
		});
	}

	private function key(int $x, int $y, int $z) : string{
		return "$x:$y:$z";
	}

	private function put(int $x, int $y, int $z, Block $block) : void{
		$block = clone $block;
		$block->position($this->world, $x, $y, $z);
		$this->blocks[$this->key($x, $y, $z)] = $block;
	}

	private function read(int $x, int $y, int $z) : Block{
		$block = clone ($this->blocks[$this->key($x, $y, $z)] ?? VanillaBlocks::AIR());
		$block->position($this->world, $x, $y, $z);
		return $block;
	}

	private function drainUpdates() : void{
		$remaining = 10000;
		while($this->updates !== []){
			self::assertGreaterThan(0, --$remaining, 'Circuit failed to settle');
			$key = array_key_first($this->updates);
			$pos = $this->updates[$key];
			unset($this->updates[$key]);
			if($this->world->isChunkLoaded($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4)){
				$this->read($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())->onNearbyBlockChange();
			}
		}
	}

	public function testAnalogueSourcesKeepTheirActualSignalStrength() : void{
		foreach([1, 7, 15] as $strength){
			foreach([VanillaBlocks::WEIGHTED_PRESSURE_PLATE_LIGHT(), VanillaBlocks::WEIGHTED_PRESSURE_PLATE_HEAVY(), VanillaBlocks::DAYLIGHT_SENSOR()] as $source){
				$source->setOutputSignalStrength($strength);
				self::assertInstanceOf(RedstonePowerSource::class, $source);
				self::assertSame($strength, $source->getWeakPower(Facing::EAST));
			}
		}
	}

	public function testLeverOnlyStronglyPowersItsSupport() : void{
		$lever = VanillaBlocks::LEVER()->setActivated(true)->setFacing(LeverFacing::EAST);
		foreach(Facing::ALL as $side){
			self::assertSame(15, $lever->getWeakPower($side));
			self::assertSame($side === Facing::WEST ? 15 : 0, $lever->getStrongPower($side));
		}
		self::assertSame(0, $lever->setActivated(false)->getStrongPower(Facing::WEST));
	}

	public function testButtonAndPlateSupportPower() : void{
		$button = VanillaBlocks::STONE_BUTTON()->setPressed(true)->setFacing(Facing::SOUTH);
		self::assertSame(15, $button->getStrongPower(Facing::NORTH));
		self::assertSame(0, $button->getStrongPower(Facing::SOUTH));
		self::assertSame(15, VanillaBlocks::STONE_PRESSURE_PLATE()->setPressed(true)->getStrongPower(Facing::DOWN));
		self::assertSame(4, VanillaBlocks::WEIGHTED_PRESSURE_PLATE_LIGHT()->setOutputSignalStrength(4)->getStrongPower(Facing::DOWN));
	}

	public function testDirectionalOutputsPreserveNativeFacingConvention() : void{
		$repeater = VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::EAST)->setPowered(true);
		$comparator = VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::EAST)->setOutputSignalStrength(6);
		foreach([$repeater, $comparator] as $source){
			self::assertSame(0, $source->getWeakPower(Facing::WEST));
			self::assertSame(0, $source->getWeakPower(Facing::NORTH));
		}
		self::assertSame(15, $repeater->getWeakPower(Facing::EAST));
		self::assertSame(6, $comparator->getStrongPower(Facing::EAST));
		$torch = VanillaBlocks::REDSTONE_TORCH()->setFacing(Facing::UP);
		self::assertSame(0, $torch->getWeakPower(Facing::DOWN));
		self::assertSame(15, $torch->getStrongPower(Facing::UP));
	}

	public function testPowerCrossesOneStronglyPoweredSolidBlock() : void{
		$this->put(0, 64, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->put(1, 64, 0, VanillaBlocks::STONE());
		$this->put(2, 64, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::EAST)->setActivated(true));
		self::assertSame(15, RedstoneSignal::getReceivedPower($this->read(0, 64, 0)));
		$this->put(1, 64, 0, VanillaBlocks::GLASS());
		self::assertSame(0, RedstoneSignal::getReceivedPower($this->read(0, 64, 0)));
	}

	public function testConductorsDoNotChainAndRedstoneBlocksDoNotStronglyPowerStone() : void{
		$this->put(0, 64, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->put(1, 64, 0, VanillaBlocks::STONE());
		$this->put(2, 64, 0, VanillaBlocks::REDSTONE());
		self::assertSame(0, RedstoneSignal::getReceivedPower($this->read(0, 64, 0)));
		$this->put(2, 64, 0, VanillaBlocks::STONE());
		$this->put(3, 64, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::EAST)->setActivated(true));
		self::assertSame(0, RedstoneSignal::getReceivedPower($this->read(0, 64, 0)));
	}

	private function wireLine(int $length) : void{
		for($x = -1; $x < $length; ++$x){
			$this->put($x, 63, 0, VanillaBlocks::STONE());
			if($x >= 0){
				$this->put($x, 64, 0, VanillaBlocks::REDSTONE_WIRE());
			}
		}
		$this->put(-1, 64, 0, VanillaBlocks::LEVER()->setActivated(true));
		RedstoneSignal::notifyNeighbours($this->world, new Vector3(-1, 64, 0));
		$this->drainUpdates();
	}

	public function testWireAttenuatesToZeroAfterFifteenBlocks() : void{
		$this->wireLine(18);
		for($x = 0; $x < 18; ++$x){
			self::assertSame(max(15 - $x, 0), $this->read($x, 64, 0)->getOutputSignalStrength());
		}
	}

	public function testWireDoesNotKeepItselfPoweredAfterSourceRemoval() : void{
		$this->wireLine(18);
		$this->world->setBlock(new Vector3(-1, 64, 0), VanillaBlocks::AIR());
		$this->drainUpdates();
		for($x = 0; $x < 18; ++$x){
			self::assertSame(0, $this->read($x, 64, 0)->getOutputSignalStrength());
		}
	}

	public function testSecondSourceSurvivesRemovingTheFirst() : void{
		$this->wireLine(5);
		$this->put(5, 63, 0, VanillaBlocks::STONE());
		$this->world->setBlock(new Vector3(5, 64, 0), VanillaBlocks::LEVER()->setActivated(true));
		$this->drainUpdates();
		$this->world->setBlock(new Vector3(-1, 64, 0), VanillaBlocks::AIR());
		$this->drainUpdates();
		for($x = 0; $x < 5; ++$x){
			self::assertSame(11 + $x, $this->read($x, 64, 0)->getOutputSignalStrength());
		}
	}

	public function testWireClimbsStepsAndAnOverheadBlockDisconnectsThem() : void{
		$this->wireLine(1);
		$this->put(1, 64, 0, VanillaBlocks::STONE());
		$this->world->setBlock(new Vector3(1, 65, 0), VanillaBlocks::REDSTONE_WIRE());
		$this->drainUpdates();
		self::assertSame(14, $this->read(1, 65, 0)->getOutputSignalStrength());
		$this->world->setBlock(new Vector3(0, 65, 0), VanillaBlocks::STONE());
		$this->drainUpdates();
		self::assertSame(0, $this->read(1, 65, 0)->getOutputSignalStrength());
	}

	public function testWireLineDoesNotPowerItsLateralFaces() : void{
		$this->wireLine(3);
		$wire = $this->read(1, 64, 0);
		self::assertSame(14, $wire->getWeakPower(Facing::EAST));
		self::assertSame(0, $wire->getWeakPower(Facing::NORTH));
		self::assertSame(0, $wire->getWeakPower(Facing::UP));
	}

	public function testChunkBoundaryDoesNotReadOrLoadMissingTerrain() : void{
		$this->unloaded['1:0'] = true;
		$this->put(15, 64, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->put(16, 64, 0, VanillaBlocks::REDSTONE());
		self::assertSame(0, RedstoneSignal::getReceivedPower($this->read(15, 64, 0)));
		unset($this->unloaded['1:0']);
		self::assertSame(15, RedstoneSignal::getReceivedPower($this->read(15, 64, 0)));
	}

	public function testLampTurnsOnAndDelaysTurningOff() : void{
		$this->put(0, 64, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->put(1, 64, 0, VanillaBlocks::REDSTONE());
		$this->read(0, 64, 0)->onNearbyBlockChange();
		self::assertTrue($this->read(0, 64, 0)->isLit());
		$this->put(1, 64, 0, VanillaBlocks::AIR());
		$this->read(0, 64, 0)->onNearbyBlockChange();
		self::assertSame([4], $this->delays);
		self::assertTrue($this->read(0, 64, 0)->isLit());
		$this->read(0, 64, 0)->onScheduledUpdate();
		self::assertFalse($this->read(0, 64, 0)->isLit());
	}

	public function testPendingLampDeactivationDoesNotOverrideRestoredPower() : void{
		$this->put(0, 64, 0, VanillaBlocks::REDSTONE_LAMP()->setLit(true));
		$this->read(0, 64, 0)->onNearbyBlockChange();
		$this->put(1, 64, 0, VanillaBlocks::REDSTONE());
		$this->read(0, 64, 0)->onScheduledUpdate();
		self::assertTrue($this->read(0, 64, 0)->isLit());
	}

	public function testHopperLocksFromIndirectPowerAndUnlocksAfterRemoval() : void{
		$this->put(0, 64, 0, VanillaBlocks::HOPPER());
		$this->put(1, 64, 0, VanillaBlocks::STONE());
		$this->put(2, 64, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::EAST)->setActivated(true));
		$this->read(0, 64, 0)->onScheduledUpdate();
		self::assertTrue($this->read(0, 64, 0)->isPowered());
		$this->put(2, 64, 0, VanillaBlocks::AIR());
		$this->read(0, 64, 0)->onScheduledUpdate();
		self::assertFalse($this->read(0, 64, 0)->isPowered());
	}

	public function testPluginsCanCancelAndAdjustNativeSignalChanges() : void{
		$plugin = $this->createStub(Plugin::class);
		$plugin->method('isEnabled')->willReturn(true);
		$manager = new PluginManager($this->createMock(Server::class), null);
		$cancel = true;
		$manager->registerEvent(BlockRedstoneUpdateEvent::class, static function(BlockRedstoneUpdateEvent $event) use (&$cancel) : void{
			if($cancel){
				$event->cancel();
			}else{
				$event->setNewPower(7);
			}
		}, EventPriority::NORMAL, $plugin);
		try{
			self::assertSame(3, RedstoneSignal::filterChange(VanillaBlocks::REDSTONE_WIRE(), 3, 15));
			$cancel = false;
			self::assertSame(7, RedstoneSignal::filterChange(VanillaBlocks::REDSTONE_WIRE(), 3, 15));
		}finally{
			HandlerListManager::global()->unregisterAll($plugin);
		}
	}

	public function testEventRejectsInvalidSignalStrength() : void{
		$event = new BlockRedstoneUpdateEvent(VanillaBlocks::REDSTONE_WIRE(), 0, 15);
		$this->expectException(\InvalidArgumentException::class);
		$event->setNewPower(16);
	}
}
