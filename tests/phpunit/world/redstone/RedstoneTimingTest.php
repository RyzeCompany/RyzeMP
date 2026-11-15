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

namespace pocketmine\world\redstone;

use pocketmine\block\BigDripleafHead;
use pocketmine\block\Block;
use pocketmine\block\Lectern;
use pocketmine\block\Tripwire;
use pocketmine\block\utils\DripleafState;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use const PHP_INT_MAX;

/**
 * Spectators and pressure plates/tripwire, the lectern page-turn pulse and the powered big dripleaf.
 */
final class RedstoneTimingTest extends RedstoneTestCase{
	/**
	 * @var Player[]
	 * @phpstan-var list<Player>
	 */
	private array $fakePlayers = [];

	protected function tearDown() : void{
		foreach($this->fakePlayers as $player){
			if($this->world->getEntity($player->getId()) === $player){
				$this->world->removeEntity($player);
			}
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}
		$this->fakePlayers = [];
		parent::tearDown();
	}

	/**
	 * A Player built without its constructor, standing at the given position (see CrowdPushTest).
	 */
	private function fakePlayer(float $x, float $y, float $z, GameMode $gameMode) : Player{
		$player = (new \ReflectionClass(Player::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(Entity::class, "id"))->setValue($player, Entity::nextRuntimeId());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location($x, $y, $z, $this->world, 0, 0));
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new NullThreadSafeLogger());
		$player->size = new EntitySizeInfo(1.8, 0.6, 1.62);
		$player->boundingBox = new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3);
		(new \ReflectionProperty(Player::class, "gamemode"))->setValue($player, $gameMode);
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(true);
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Living::class, "armorInventory"))->setValue($player, new ArmorInventory($player));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($player, new EffectManager($player));
		//never ticked: it only stands there
		(new \ReflectionProperty(Entity::class, "lastUpdate"))->setValue($player, PHP_INT_MAX);
		$this->world->addEntity($player);
		$this->fakePlayers[] = $player;
		return $player;
	}

	/**
	 * Lets the player step on the block at the given position (like an entity moving into it) and runs a few ticks.
	 */
	private function stepOn(int $x, int $y, int $z, Player $player) : void{
		$this->get($x, $y, $z)->onEntityInside($player);
		$this->runTicks(3);
	}

	private function platePower(int $x, int $y, int $z) : int{
		return $this->get($x, $y, $z)->getWeakPower(Facing::UP);
	}

	/**
	 * @return Block[]
	 * @phpstan-return array<string, Block>
	 */
	private static function plates() : array{
		return [
			"stone" => VanillaBlocks::STONE_PRESSURE_PLATE(),
			"wooden" => VanillaBlocks::OAK_PRESSURE_PLATE(),
			"weighted" => VanillaBlocks::WEIGHTED_PRESSURE_PLATE_LIGHT(),
		];
	}

	public function testSpectatorsDoNotPressPlates() : void{
		$this->floor(0, 0, 10, 2);
		$x = 0;
		foreach(Utils::stringifyKeys(self::plates()) as $name => $plate){
			$x += 2;
			$this->set($x, 1, 1, $plate);
			$spectator = $this->fakePlayer($x + 0.5, 1, 1.5, GameMode::SPECTATOR);
			$this->stepOn($x, 1, 1, $spectator);
			self::assertSame(0, $this->platePower($x, 1, 1), "$name plate ignores spectators");
			self::assertFalse($this->world->isBlockUpdateScheduled($x, 1, 1), "$name plate isn't even checked");

			$this->world->removeEntity($spectator);
			$survival = $this->fakePlayer($x + 0.5, 1, 1.5, GameMode::SURVIVAL);
			$this->stepOn($x, 1, 1, $survival);
			self::assertGreaterThan(0, $this->platePower($x, 1, 1), "$name plate is pressed by other players");
			$this->world->removeEntity($survival);
		}
	}

	public function testSpectatorsDoNotCountOnPressedPlates() : void{
		$this->floor(0, 0, 4, 2);
		$this->set(2, 1, 1, VanillaBlocks::STONE_PRESSURE_PLATE());
		$survival = $this->fakePlayer(2.5, 1, 1.5, GameMode::SURVIVAL);
		$this->fakePlayer(2.5, 1, 1.5, GameMode::SPECTATOR);
		$this->stepOn(2, 1, 1, $survival);
		self::assertSame(15, $this->platePower(2, 1, 1));
		$this->world->removeEntity($survival);
		$this->runTicks(25);
		self::assertSame(0, $this->platePower(2, 1, 1), "released with only a spectator left on it");
	}

	private function tripwireTriggered(int $x, int $y, int $z) : bool{
		$wire = $this->get($x, $y, $z);
		self::assertInstanceOf(Tripwire::class, $wire);
		return $wire->isTriggered();
	}

	public function testSpectatorsDoNotTriggerTripwire() : void{
		$this->floor(0, 0, 4, 2);
		$this->set(2, 1, 1, VanillaBlocks::TRIPWIRE());
		$spectator = $this->fakePlayer(2.5, 1, 1.5, GameMode::SPECTATOR);
		$this->stepOn(2, 1, 1, $spectator);
		self::assertFalse($this->tripwireTriggered(2, 1, 1));

		$survival = $this->fakePlayer(2.5, 1, 1.5, GameMode::SURVIVAL);
		$this->stepOn(2, 1, 1, $survival);
		self::assertTrue($this->tripwireTriggered(2, 1, 1));
		$this->world->removeEntity($survival);
		$this->runTicks(15);
		self::assertFalse($this->tripwireTriggered(2, 1, 1), "released with only a spectator left on it");
	}

	public function testLecternPageTurnPulseLastsTwoTicks() : void{
		$this->floor(0, 0, 4, 2);
		$book = VanillaItems::WRITABLE_BOOK()->setPageText(0, "a")->setPageText(1, "b")->setPageText(2, "c");
		$this->set(2, 1, 1, VanillaBlocks::LECTERN()->setBook($book));
		$this->runTicks(2); //the update scheduled when the block entity was added
		$lectern = $this->get(2, 1, 1);
		self::assertInstanceOf(Lectern::class, $lectern);
		self::assertSame(0, $lectern->getWeakPower(Facing::UP));
		self::assertTrue($lectern->onPageTurn(1));
		self::assertSame(15, $this->get(2, 1, 1)->getWeakPower(Facing::UP));
		$this->runTicks(1);
		self::assertSame(15, $this->get(2, 1, 1)->getWeakPower(Facing::UP), "still powered after 1 tick");
		$this->runTicks(1);
		self::assertSame(0, $this->get(2, 1, 1)->getWeakPower(Facing::UP), "off after 2 ticks");
	}

	private function leafState() : DripleafState{
		$leaf = $this->get(2, 2, 1);
		self::assertInstanceOf(BigDripleafHead::class, $leaf);
		return $leaf->getLeafState();
	}

	private function dripleaf() : void{
		$this->floor(0, 0, 4, 2);
		$this->set(2, 1, 1, VanillaBlocks::CLAY());
		$this->set(2, 2, 1, VanillaBlocks::BIG_DRIPLEAF_HEAD());
	}

	public function testUnpoweredDripleafTilts() : void{
		$this->dripleaf();
		$player = $this->fakePlayer(2.5, 2.9, 1.5, GameMode::SURVIVAL);
		$this->get(2, 2, 1)->onEntityInside($player);
		self::assertSame(DripleafState::UNSTABLE, $this->leafState());
	}

	public function testPoweredDripleafDoesNotTilt() : void{
		$this->dripleaf();
		$this->set(3, 2, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		$player = $this->fakePlayer(2.5, 2.9, 1.5, GameMode::SURVIVAL);
		$this->get(2, 2, 1)->onEntityInside($player);
		self::assertSame(DripleafState::STABLE, $this->leafState());
	}

	public function testPoweringResetsTilt() : void{
		$this->dripleaf();
		$player = $this->fakePlayer(2.5, 2.9, 1.5, GameMode::SURVIVAL);
		$this->get(2, 2, 1)->onEntityInside($player);
		$this->runTicks(12);
		self::assertSame(DripleafState::PARTIAL_TILT, $this->leafState());
		$this->set(3, 2, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(1);
		self::assertSame(DripleafState::STABLE, $this->leafState(), "powering the leaf puts it back up");
		$this->runTicks(40);
		self::assertSame(DripleafState::STABLE, $this->leafState(), "and the pending tilt tick does nothing");
	}
}
