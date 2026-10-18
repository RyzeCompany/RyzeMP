<?php

declare(strict_types=1);

namespace pocketmine\world;

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\GameRulesChangedPacket;
use pocketmine\player\Player;
use Symfony\Component\Filesystem\Filesystem;

final class GameRuleSyncTest extends TestCase{

	public function testSessionSendsCurrentWorldCoordinatesIncludingFalse() : void{
		$directory = sys_get_temp_dir() . "/ryzemp-sync-" . bin2hex(random_bytes(12));
		mkdir($directory);
		try{
			$rules = new GameRules($directory . "/gamerules.yml");
			$world = $this->createMock(World::class);
			$world->method("getGameRules")->willReturn($rules);
			$session = $this->createPartialMock(NetworkSession::class, ["sendDataPacket"]);
			$packets = [];
			$session->expects(self::exactly(2))->method("sendDataPacket")->willReturnCallback(function($packet) use (&$packets) : bool{
				self::assertInstanceOf(GameRulesChangedPacket::class, $packet);
				$packets[] = $packet->gameRules["showcoordinates"]->getValue();
				return true;
			});
			$rules->setBool("showcoordinates", true);
			$session->syncGameRules($world);
			$rules->setBool("showcoordinates", false);
			$session->syncGameRules($world);
			self::assertSame([true, false], $packets);
		}finally{ (new Filesystem())->remove($directory); }
	}

	public function testWorldChangeSavesAndNotifiesOnlinePlayer() : void{
		$directory = sys_get_temp_dir() . "/ryzemp-sync-" . bin2hex(random_bytes(12));
		mkdir($directory);
		try{
			$world = $this->createPartialMock(World::class, ["getPlayers"]);
			$rules = new GameRules($directory . "/gamerules.yml");
			(new \ReflectionProperty(World::class, "gameRules"))->setValue($world, $rules);
			$player = $this->createMock(Player::class);
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
			(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
			$session = $this->createMock(NetworkSession::class);
			$session->expects(self::once())->method("syncGameRules")->with($world);
			$player->method("getNetworkSession")->willReturn($session);
			$world->method("getPlayers")->willReturn([$player]);
			$world->setGameRule("showcoordinates", true);
			self::assertTrue((new GameRules($directory . "/gamerules.yml"))->getBool("showcoordinates"));
		}finally{ (new Filesystem())->remove($directory); }
	}
}
