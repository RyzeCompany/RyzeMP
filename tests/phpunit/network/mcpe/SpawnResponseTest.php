<?php
declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\handler\SpawnResponsePacketHandler;
use pocketmine\player\Player;

final class SpawnResponseTest extends TestCase{
	private function session() : NetworkSession{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(['sendDataPacket', 'setHandler'])->getMock();
		$session->method('sendDataPacket')->willReturn(true);
		(new \ReflectionProperty(NetworkSession::class, 'logger'))->setValue($session, $this->createStub(\PrefixedLogger::class));
		return $session;
	}

	public function testEarlyAcknowledgementCompletesSpawnWhenTerrainBecomesReady() : void{
		$session = $this->session();
		$player = $this->createMock(Player::class);
		(new \ReflectionProperty(\pocketmine\entity\Entity::class, 'closed'))->setValue($player, true);
		(new \ReflectionProperty(Player::class, 'logger'))->setValue($player, $this->createStub(\PrefixedLogger::class));
		$player->expects(self::once())->method('doFirstSpawn');
		$player->expects(self::once())->method('setNoClientPredictions')->with(false);
		(new \ReflectionProperty(NetworkSession::class, 'player'))->setValue($session, $player);
		(new \ReflectionProperty(NetworkSession::class, 'invManager'))->setValue($session, $this->createStub(InventoryManager::class));
		$session->expects(self::once())->method('setHandler')->with(self::isInstanceOf(InGamePacketHandler::class));
		$session->notifyEarlySpawnResponse();
		$session->notifyTerrainReady();
	}

	public function testNormalSpawnStillWaitsForAcknowledgement() : void{
		$session = $this->session();
		$session->expects(self::once())->method('setHandler')->with(self::isInstanceOf(SpawnResponsePacketHandler::class));
		$session->notifyTerrainReady();
	}
}
