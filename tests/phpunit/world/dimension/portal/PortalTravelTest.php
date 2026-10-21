<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use LogicException;
use pocketmine\event\entity\EntityPortalEnterEvent;
use pocketmine\event\entity\EntityPortalTeleportEvent;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\EventPriority;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\promise\Promise;
use pocketmine\promise\PromiseResolver;
use pocketmine\Server;
use pocketmine\world\dimension\DimensionSettings;
use pocketmine\world\dimension\Dimensions;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\WorldManager;

final class PortalTravelTest extends TestCase{

	private array $listeners = [];
	private World $source;
	private World $target;
	private World $currentWorld;
	private Entity $entity;
	private PortalTravel $travel;
	private PortalLinkManager $links;
	private Server $server;
	private string $targetName = "world_the_end";
	private int $targetFloorY = 48;
	private bool $targetObstructed = false;
	private PortalType $sourcePortalType = PortalType::END;
	private bool $destinationPortal = false;
	private Vector3 $entityPosition;
	private bool $touching = true;
	private bool $alive = true;
	private bool $targetLoaded = true;
	private int $arrivalReads = 0;
	private int $entityVersion = 0;
	/** @var list<PromiseResolver> */
	private array $loads = [];
	/** @var list<array{int, int}> */
	private array $loadCoordinates = [];

	protected function setUp() : void{
		try{
			PortalContent::endPortal();
		}catch(LogicException){
			PortalContent::registerBlock(BlockTypeIds::newId());
		}
		$this->source = $this->createMock(World::class);
		$this->source->method("isLoaded")->willReturn(true);
		$this->source->method("getFolderName")->willReturn("world");
		$this->source->method("getBlockAt")->willReturnCallback(fn() : Block => $this->touching ? ($this->sourcePortalType === PortalType::END ? PortalContent::endPortal() : VanillaBlocks::NETHER_PORTAL()) : VanillaBlocks::AIR());
		$this->currentWorld = $this->source;
		$this->entityPosition = new Vector3(0.5, 64, 0.5);
		$this->source->method("getMinY")->willReturn(-64);
		$this->source->method("getMaxY")->willReturn(320);
		$this->target = $this->createMock(World::class);
		$this->target->method("isLoaded")->willReturnCallback(fn() : bool => $this->targetLoaded);
		$this->target->method("getFolderName")->willReturnCallback(fn() : string => $this->targetName);
		$this->target->method("getMinY")->willReturn(-64);
		$this->target->method("getMaxY")->willReturn(320);
		$this->target->method("getBlockAt")->willReturnCallback(function(int $x, int $y, int $z) : Block{
			++$this->arrivalReads;
			if($y === $this->targetFloorY || ($this->targetObstructed && $y === $this->targetFloorY + 2)){
				return VanillaBlocks::END_STONE();
			}
			return $this->destinationPortal ? PortalContent::endPortal() : VanillaBlocks::AIR();
		});
		$this->target->method("orderChunkPopulation")->willReturnCallback(function(int $x, int $z) : Promise{
			$resolver = new PromiseResolver();
			$this->loads[] = $resolver;
			$this->loadCoordinates[] = [$x, $z];
			return $resolver->getPromise();
		});
		$manager = $this->createMock(WorldManager::class);
		$manager->method("getWorldByName")->willReturnCallback(fn(string $name) : ?World => $name === $this->targetName ? $this->target : ($name === "world" ? $this->source : null));
		$server = $this->server = $this->createMock(Server::class);
		$server->method("getWorldManager")->willReturn($manager);
		$server->method("getLogger")->willReturn(new class extends \pocketmine\thread\log\AttachableThreadSafeLogger{
			public function emergency($message) : void{}
			public function alert($message) : void{}
			public function critical($message) : void{}
			public function error($message) : void{}
			public function warning($message) : void{}
			public function notice($message) : void{}
			public function info($message) : void{}
			public function debug($message) : void{}
			public function log($level, $message) : void{}
			public function logException(\Throwable $e, $trace = null) : void{ throw $e; }
		});
		$this->source->method("getServer")->willReturn($server);
		$this->target->method("getServer")->willReturn($server);
		$this->entity = $this->createMock(Entity::class);
		// Entity::close() is final; keep the unconstructed mock safe during destruction.
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($this->entity, true);
		$this->entity->method("getId")->willReturn(123);
		$this->entity->method("getPortalTravelVersion")->willReturnCallback(fn() : int => $this->entityVersion);
		$this->entity->method("isAlive")->willReturnCallback(fn() : bool => $this->alive);
		$this->entity->method("getWorld")->willReturnCallback(fn() : World => $this->currentWorld);
		$this->entity->method("getPosition")->willReturnCallback(fn() : Position => Position::fromObject($this->entityPosition, $this->currentWorld));
		$this->entity->method("getBoundingBox")->willReturnCallback(fn() : AxisAlignedBB => new AxisAlignedBB($this->entityPosition->x - 0.3, $this->entityPosition->y, $this->entityPosition->z - 0.3, $this->entityPosition->x + 0.3, $this->entityPosition->y + 1.8, $this->entityPosition->z + 0.3));
		$this->links = new PortalLinkManager();
		$this->travel = new PortalTravel(new Dimensions($server, [DimensionIds::NETHER => "world_nether", DimensionIds::THE_END => "world_the_end"]), DimensionSettings::fromArray(["portal" => ["load-timeout-ticks" => 20]]), $this->links);
	}

	private function enter() : void{
		$this->travel->touchEndPortal($this->entity);
		$this->travel->tick();
	}

	private function resolveLoads(int $offset = 0) : void{
		foreach(array_slice($this->loads, $offset) as $resolver){
			$resolver->resolve(null);
		}
	}

	public function testWaitsForEveryChunkInArrivalFootprint() : void{
		$this->entity->expects(self::once())->method("teleport")->willReturnCallback(function(Position $destination) : bool{
			self::assertSame($this->target, $destination->getWorld());
			self::assertEquals([100.5, 49, 0.5], [$destination->x, $destination->y, $destination->z]);
			return true;
		});
		$this->enter();
		self::assertSame([[5, -1], [5, 0], [6, -1], [6, 0]], $this->loadCoordinates);
		$last = array_pop($this->loads);
		$this->resolveLoads();
		// No destination reads or teleport may happen before the final chunk is ready.
		self::assertSame(0, $this->arrivalReads);
		$last->resolve(null);
		self::assertGreaterThan(0, $this->arrivalReads);
	}

	public function testLeavingPortalCancelsEvenWithoutAnotherTick() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->touching = false;
		$this->resolveLoads();
	}

	public function testChangingWorldCancelsPendingTravel() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->currentWorld = $this->target;
		$this->resolveLoads();
	}

	public function testExternalTeleportInvalidatesPendingTravel() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		++$this->entityVersion;
		$this->resolveLoads();
	}

	public function testDeathCancelsPendingTravel() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->alive = false;
		$this->resolveLoads();
	}

	public function testDeathAndRespawnBeforeCompletionStillInvalidateRequest() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		++$this->entityVersion;
		$this->alive = true;
		$this->resolveLoads();
	}

	public function testUnloadedDestinationCancelsPendingTravel() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->targetLoaded = false;
		$this->resolveLoads();
	}

	public function testLoadingDeadlineCancelsLateCompletion() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		for($tick = 0; $tick < 20; ++$tick){
			$this->enter();
		}
		$this->resolveLoads();
		self::assertCount(4, $this->loads);
	}

	public function testOldCompletionCannotConsumeNewRequest() : void{
		$this->entity->expects(self::once())->method("teleport")->willReturn(true);
		$this->enter();
		$oldLoads = $this->loads;
		$this->travel->forget($this->entity->getId());
		$this->enter();
		foreach($oldLoads as $resolver){
			$resolver->resolve(null);
		}
		$this->resolveLoads(4);
	}

	public function testOldFailureCannotCancelNewRequest() : void{
		$this->entity->expects(self::once())->method("teleport")->willReturn(true);
		$this->enter();
		$oldLoads = $this->loads;
		$this->travel->forget($this->entity->getId());
		$this->enter();
		foreach($oldLoads as $resolver){
			$resolver->reject();
		}
		$this->resolveLoads(4);
	}

	private function link(PortalType $type = PortalType::END, string $world = "world_the_end", float $y = 49.0) : PortalLink{
		$link = new PortalLink("hcf:spawn", new PortalArea("world", $type, 0, 64, 0, 1, 66, 0), new PortalDestination($world, 0, $y, 0, 90, 20));
		$this->links->register($link);
		return $link;
	}

	private function enterNether() : void{
		for($tick = 0; $tick < PortalTravel::NETHER_PORTAL_DELAY; ++$tick){
			$this->travel->touchNetherPortal($this->entity);
			$this->travel->tick();
		}
	}

	public function testLinkedNetherUsesExactHcfDestinationAndOrientation() : void{
		$this->sourcePortalType = PortalType::NETHER;
		$this->targetName = "world_nether";
		$this->targetFloorY = 45;
		$this->link(PortalType::NETHER, "world_nether", 46);
		$this->entity->expects(self::once())->method("teleport")->willReturnCallback(function(Position $to, ?float $yaw, ?float $pitch) : bool{
			self::assertEquals([0, 46, 0], [$to->x, $to->y, $to->z]);
			self::assertSame($this->target, $to->getWorld());
			self::assertSame(90.0, $yaw);
			self::assertSame(20.0, $pitch);
			return true;
		});
		$this->target->expects(self::never())->method("setBlockAt");
		$this->enterNether();
		self::assertSame([[-1, -1], [-1, 0], [0, -1], [0, 0]], $this->loadCoordinates);
		$this->resolveLoads();
	}

	public function testUnlinkedNetherStillUsesScaledCoordinates() : void{
		$this->sourcePortalType = PortalType::NETHER;
		$this->targetName = "world_nether";
		$this->entityPosition = new Vector3(80.5, 64, -96.5);
		$this->enterNether();
		self::assertSame([[0, -1]], $this->loadCoordinates);
	}

	public function testUnrelatedLinkDoesNotOverrideNormalEndArrival() : void{
		$this->links->register(new PortalLink("other", new PortalArea("other-world", PortalType::END, 0, 64, 0, 1, 66, 0), new PortalDestination("world_the_end", 0, 70, 0)));
		$this->entity->expects(self::once())->method("teleport")->willReturnCallback(static function(Position $to) : bool{
			self::assertEquals([100.5, 49, 0.5], [$to->x, $to->y, $to->z]);
			return true;
		});
		$this->enter();
		$this->resolveLoads();
	}

	public function testMissingLinkedWorldDoesNotFallBackToAutomaticTravel() : void{
		$this->link(PortalType::END, "missing-world");
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		self::assertSame([], $this->loadCoordinates);
	}

	public function testObstructedLinkedArrivalDoesNotFallBackOrChangeTerrain() : void{
		$this->link();
		$this->targetObstructed = true;
		$this->entity->expects(self::never())->method("teleport");
		$this->target->expects(self::never())->method("setBlockAt");
		$this->enter();
		$this->resolveLoads();
		self::assertSame([[-1, -1], [-1, 0], [0, -1], [0, 0]], $this->loadCoordinates);
	}

	public function testRemovedLinkInvalidatesItsPendingTrip() : void{
		$link = $this->link();
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->links->unregister($link->id);
		$this->resolveLoads();
	}

	public function testReplacedLinkWithSameIdCannotCompleteOldTrip() : void{
		$link = $this->link();
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->links->unregister($link->id);
		$this->links->register(new PortalLink($link->id, $link->source, $link->destination));
		$this->resolveLoads();
	}

	public function testAddingLinkInvalidatesPendingAutomaticTrip() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->link();
		$this->resolveLoads();
	}

	public function testLeavingLinkedSourceAreaCancelsEvenIfStillInPortalBlocks() : void{
		$this->link();
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->entityPosition = new Vector3(10.5, 64, 0.5);
		$this->resolveLoads();
	}

	public function testLinkedEndDestinationBypassesVanillaEntryCoordinates() : void{
		$this->link(PortalType::END, "world_the_end", 49);
		$this->entity->expects(self::once())->method("teleport")->willReturnCallback(static function(Position $to) : bool{
			self::assertEquals([0, 49, 0], [$to->x, $to->y, $to->z]);
			return true;
		});
		$this->enter();
		$this->resolveLoads();
	}

	public function testLinkedTripStillHonoursDeadline() : void{
		$this->link();
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		for($i = 0; $i < 20; ++$i){
			$this->enter();
		}
		$this->resolveLoads();
	}

	public function testCrossTypeLinkCannotBounceWhileInsideArrivalPortal() : void{
		$this->sourcePortalType = PortalType::NETHER;
		$this->destinationPortal = true;
		$this->link(PortalType::NETHER);
		$this->links->register(new PortalLink("reverse", new PortalArea("world_the_end", PortalType::END, -1, 49, -1, 1, 51, 1), new PortalDestination("world", 10, 78, -90)));
		$this->source->expects(self::never())->method("orderChunkPopulation");
		$this->entity->expects(self::once())->method("teleport")->willReturnCallback(function(Position $to) : bool{
			$this->currentWorld = $this->target;
			$this->entityPosition = $to->asVector3();
			++$this->entityVersion;
			return true;
		});
		$this->enterNether();
		$this->resolveLoads();
		for($i = 0; $i < 40; ++$i){
			$this->enter();
		}
		self::assertCount(4, $this->loads);
	}
	private function listen(string $eventClass, \Closure $handler) : void{
		$listener = new RegisteredListener($handler, EventPriority::NORMAL, $this->createMock(Plugin::class), true, new TimingsHandler("portal test"));
		$this->listeners[] = $listener;
		HandlerListManager::global()->getListFor($eventClass)->register($listener);
	}

	protected function tearDown() : void{
		foreach($this->listeners as $listener){ HandlerListManager::global()->unregisterAll($listener); }
	}

	public function testEntryCancellationIsOncePerContactAndStopsLoading() : void{
		$calls = 0;
		$this->listen(EntityPortalEnterEvent::class, function($event) use(&$calls) : void{ ++$calls; $event->cancel(); });
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->enter();
		self::assertSame(1, $calls);
		self::assertSame([], $this->loads);
		$this->touching = false;
		$this->travel->tick();
		$this->touching = true;
		$this->enter();
		self::assertSame(2, $calls);
	}

	public function testEntryEventCanDelayEndWithoutChangingDefaultNetherRules() : void{
		$this->listen(EntityPortalEnterEvent::class, function($event) : void{ $event->setTeleportDelay(3); });
		$this->enter();
		$this->enter();
		self::assertSame([], $this->loads);
		$this->enter();
		self::assertNotEmpty($this->loads);
	}

	public function testTeleportEventCanCancelAfterLoading() : void{
		$this->listen(EntityPortalTeleportEvent::class, function($event) : void{ $event->cancel(); });
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->resolveLoads();
	}

	public function testRedirectedDestinationIsLoadedAndCheckedBeforeTeleportOnce() : void{
		$calls = 0;
		$this->listen(EntityPortalTeleportEvent::class, function($event) use(&$calls) : void{
			++$calls;
			$event->setTo(new Position(32.5, 49, 32.5, $this->target), 45.0, 0.0);
		});
		$teleports = 0;
		$this->entity->expects(self::once())->method("teleport")->willReturnCallback(function($to, $yaw) use(&$teleports) : bool{
			++$teleports;
			self::assertSame([32.5, 49, 32.5, 45.0], [$to->x, $to->y, $to->z, $yaw]);
			return true;
		});
		$this->enter();
		$firstLoads = count($this->loads);
		$this->resolveLoads();
		self::assertGreaterThan($firstLoads, count($this->loads));
		self::assertSame(0, $teleports);
		$this->resolveLoads($firstLoads);
		self::assertSame(1, $teleports);
		self::assertSame(1, $calls);
	}

	public function testUnsafeRedirectCannotTeleport() : void{
		$this->listen(EntityPortalTeleportEvent::class, function($event) : void{ $event->setTo(new Position(32.5, 70, 32.5, $this->target)); });
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$firstLoads = count($this->loads);
		$this->resolveLoads();
		$this->resolveLoads($firstLoads);
	}

	public function testTeleportHandlerCannotCompleteTripAfterEntityMoved() : void{
		$this->listen(EntityPortalTeleportEvent::class, function($event) : void{ ++$this->entityVersion; });
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->resolveLoads();
	}

}

