<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use LogicException;
use PHPUnit\Framework\TestCase;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\math\AxisAlignedBB;
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

	private World $source;
	private World $target;
	private World $currentWorld;
	private Entity $entity;
	private PortalTravel $travel;
	private bool $touching = true;
	private bool $alive = true;
	private bool $targetLoaded = true;
	private int $arrivalReads = 0;
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
		$this->source->method("getBlockAt")->willReturnCallback(fn() : Block => $this->touching ? PortalContent::endPortal() : VanillaBlocks::AIR());
		$this->currentWorld = $this->source;
		$this->target = $this->createMock(World::class);
		$this->target->method("isLoaded")->willReturnCallback(fn() : bool => $this->targetLoaded);
		$this->target->method("getFolderName")->willReturn("world_the_end");
		$this->target->method("getMinY")->willReturn(-64);
		$this->target->method("getMaxY")->willReturn(320);
		$this->target->method("getBlockAt")->willReturnCallback(function(int $x, int $y, int $z) : Block{
			++$this->arrivalReads;
			return $y === 48 ? VanillaBlocks::END_STONE() : VanillaBlocks::AIR();
		});
		$this->target->method("orderChunkPopulation")->willReturnCallback(function(int $x, int $z) : Promise{
			$resolver = new PromiseResolver();
			$this->loads[] = $resolver;
			$this->loadCoordinates[] = [$x, $z];
			return $resolver->getPromise();
		});
		$manager = $this->createMock(WorldManager::class);
		$manager->method("getWorldByName")->with("world_the_end")->willReturn($this->target);
		$server = $this->createMock(Server::class);
		$server->method("getWorldManager")->willReturn($manager);
		$this->entity = $this->createMock(Entity::class);
		// Entity::close() is final; keep the unconstructed mock safe during destruction.
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($this->entity, true);
		$this->entity->method("getId")->willReturn(123);
		$this->entity->method("isAlive")->willReturnCallback(fn() : bool => $this->alive);
		$this->entity->method("getWorld")->willReturnCallback(fn() : World => $this->currentWorld);
		$this->entity->method("getBoundingBox")->willReturn(new AxisAlignedBB(0.2, 64, 0.2, 0.8, 65.8, 0.8));
		$this->travel = new PortalTravel(new Dimensions($server, [DimensionIds::THE_END => "world_the_end"]), DimensionSettings::fromArray(["portal" => ["load-timeout-ticks" => 20]]));
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
		$this->travel->cancelPending($this->entity->getId());
		$this->resolveLoads();
	}

	public function testDeathCancelsPendingTravel() : void{
		$this->entity->expects(self::never())->method("teleport");
		$this->enter();
		$this->alive = false;
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
}
