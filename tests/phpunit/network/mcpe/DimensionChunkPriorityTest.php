<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\player\Player;
use pocketmine\promise\PromiseResolver;
use pocketmine\world\ChunkLoader;
use pocketmine\world\World;
use pocketmine\world\WorldTimings;

final class DimensionChunkPriorityTest extends TestCase{

	public function testPrioritizesOnlyQueuedArrivalChunksAndPreservesInflightBudget() : void{
		$near = World::chunkHash(0, 0);
		$near2 = World::chunkHash(1, 0);
		$far = World::chunkHash(10, 10);
		$notRequested = World::chunkHash(-1, 0);
		$session = $this->createMock(NetworkSession::class);
		$session->method("getDimensionArrivalChunks")->willReturn([$notRequested => true, $near => true, $near2 => true]);
		$world = $this->createMock(World::class);
		$world->timings = new WorldTimings($world);
		$requests = [];
		$resolvers = [];
		$world->method("requestChunkPopulation")->willReturnCallback(function(int $x, int $z) use(&$requests, &$resolvers){
			$requests[] = [$x, $z];
			$resolvers[] = $resolver = new PromiseResolver();
			return $resolver->getPromise();
		});
		$player = $this->createPartialMock(Player::class, ["getWorld", "getNetworkSession", "isConnected"]);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		$player->method("getWorld")->willReturn($world);
		$player->method("getNetworkSession")->willReturn($session);
		$player->method("isConnected")->willReturn(true);
		foreach([
			"chunksPerTick" => 2,
			"chunkLoader" => new class implements ChunkLoader{},
			"loadQueue" => [$far => 200, $near2 => 1, $near => 0],
			"activeChunkGenerationRequests" => [World::chunkHash(20, 20) => true]
		] as $name => $value){
			(new \ReflectionProperty(Player::class, $name))->setValue($player, $value);
		}
		$method = new \ReflectionMethod(Player::class, "requestChunks");
		$method->invoke($player);
		self::assertSame([[0, 0]], $requests);
		$method->invoke($player);
		self::assertSame([[0, 0]], $requests); // one old + one new request exhausts the configured limit
		self::assertSame([$far => 200, $near2 => 1], (new \ReflectionProperty(Player::class, "loadQueue"))->getValue($player));
	}
}
