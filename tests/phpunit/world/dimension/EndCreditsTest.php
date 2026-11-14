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

namespace pocketmine\world\dimension;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ShowCreditsPacket;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\redstone\NullThreadSafeLogger;
use pocketmine\world\World;
use pocketmine\world\WorldManager;

/**
 * The End Poem and credits played by the exit portal of the End (see PortalTravel::showCredits()).
 */
final class EndCreditsTest extends DimensionWorldTestCase{
	private World $end;

	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $mockPlayers = [];

	protected function setUp() : void{
		parent::setUp();
		$this->end = $this->createWorld("dimension_test_the_end", Dimension::END);
		$worldManager = $this->createMock(WorldManager::class);
		$worldManager->method("getLinkedWorld")->willReturnCallback(fn(World $from, Dimension $dimension) => $dimension === Dimension::OVERWORLD ? $this->world : $this->end);
		$this->server->method("getWorldManager")->willReturn($worldManager);
	}

	protected function tearDown() : void{
		//mocked players never ran their constructor: they must not dispose of themselves when destroyed
		foreach($this->mockPlayers as $player){
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->mockPlayers = [];
		parent::tearDown();
	}

	/**
	 * A connected player standing in the End, built as a mock, whose sent packets are collected in $sent.
	 *
	 * @param ClientboundPacket[] $sent
	 * @phpstan-param list<ClientboundPacket> $sent
	 */
	private function player(bool $seenCredits, array &$sent) : Player&MockObject{
		$session = $this->createMock(NetworkSession::class);
		$session->method("isChangingDimension")->willReturn(false);
		$session->method("sendDataPacket")->willReturnCallback(function(ClientboundPacket $packet) use (&$sent) : bool{
			$sent[] = $packet;
			return true;
		});
		$player = $this->createMock(Player::class);
		$player->method("getId")->willReturn(1);
		$player->method("isConnected")->willReturn(true);
		$player->method("isAlive")->willReturn(true);
		$player->method("getWorld")->willReturn($this->end);
		$player->method("getPosition")->willReturn(new Position(0.5, 60, 0.5, $this->end));
		$player->method("getLocation")->willReturn(new Location(0.5, 60, 0.5, $this->end, 0, 0));
		$player->method("getNetworkSession")->willReturn($session);
		$seen = $seenCredits;
		$player->method("hasSeenCredits")->willReturnCallback(function() use (&$seen) : bool{
			return $seen;
		});
		$player->method("setSeenCredits")->willReturnCallback(function(bool $value) use (&$seen) : void{
			$seen = $value;
		});
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$this->mockPlayers[] = $player;
		return $player;
	}

	public function testFirstExitPlaysTheCreditsThenLeavesWhenTheyAreOver() : void{
		$sent = [];
		$player = $this->player(false, $sent);
		$player->expects(self::once())->method("teleport")->willReturnCallback(function(Vector3 $to) : bool{
			self::assertInstanceOf(Position::class, $to);
			self::assertSame($this->world, $to->getWorld(), "back to the Overworld");
			return true;
		});
		$travel = PortalTravel::getInstance();

		$travel->onEntityInsidePortal($player, Dimension::END, new Vector3(0, 60, 0));
		self::assertCount(1, $sent);
		self::assertInstanceOf(ShowCreditsPacket::class, $sent[0]);
		self::assertSame(ShowCreditsPacket::STATUS_START_CREDITS, $sent[0]->status);
		self::assertTrue($player->hasSeenCredits(), "marked at once (saved in the player data)");

		//still standing in the portal while the credits play: nothing more happens
		for($i = 0; $i < 3; ++$i){
			++$this->tick;
			$travel->onEntityInsidePortal($player, Dimension::END, new Vector3(0, 60, 0));
		}
		self::assertCount(1, $sent);

		$travel->onCreditsClosed($player); //teleports (expected once above)
		$travel->onCreditsClosed($player); //a second END_CREDITS does nothing
	}

	public function testLaterExitsSkipTheCredits() : void{
		$sent = [];
		$player = $this->player(true, $sent);
		$player->expects(self::once())->method("teleport")->willReturn(true);
		PortalTravel::getInstance()->onEntityInsidePortal($player, Dimension::END, new Vector3(0, 60, 0));
		self::assertCount(0, $sent, "no credits the second time");
	}

	public function testEndCreditsWithoutCreditsPlayingDoesNothing() : void{
		$sent = [];
		$player = $this->player(false, $sent);
		$player->expects(self::never())->method("teleport");
		//a client may send END_CREDITS at any time
		PortalTravel::getInstance()->onCreditsClosed($player);
		self::assertCount(0, $sent);
		self::assertFalse($player->hasSeenCredits());
	}
}
