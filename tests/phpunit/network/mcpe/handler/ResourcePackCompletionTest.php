<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;

final class ResourcePackCompletionTest extends TestCase{

	public function testCompletionRunsOnceWhilePlayerCreationIsPending() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->method('getLogger')->willReturn($this->createStub(\Logger::class));
		$completions = 0;
		$handler = new ResourcePacksPacketHandler($session, [], [], false, static function() use(&$completions) : void{
			// An asynchronous player factory leaves the handler installed until
			// its promise completes. Repeated packets must not start more factories.
			++$completions;
		});
		$packet = ResourcePackClientResponsePacket::create(ResourcePackClientResponsePacket::STATUS_COMPLETED, []);
		self::assertTrue($handler->handleResourcePackClientResponse($packet));
		self::assertTrue($handler->handleResourcePackClientResponse($packet));
		self::assertSame(1, $completions);
	}

	public function testCompletionCannotReenterItself() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->method('getLogger')->willReturn($this->createStub(\Logger::class));
		$completions = 0;
		$handler = null;
		$packet = ResourcePackClientResponsePacket::create(ResourcePackClientResponsePacket::STATUS_COMPLETED, []);
		$handler = new ResourcePacksPacketHandler($session, [], [], false, static function() use(&$handler, &$completions, $packet) : void{
			if(++$completions === 1){
				$handler->handleResourcePackClientResponse($packet);
			}
		});
		$handler->handleResourcePackClientResponse($packet);
		self::assertSame(1, $completions);
	}
}
