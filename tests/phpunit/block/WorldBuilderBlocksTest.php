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

namespace pocketmine\block;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\utils\BuildPermissionBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissionManager;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\redstone\RedstoneTestCase;
use function max;

/**
 * Allow, deny and border blocks (vanilla audit B0-22).
 */
final class WorldBuilderBlocksTest extends RedstoneTestCase{

	protected function setUp() : void{
		parent::setUp();
		if(PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_OPERATOR) === null){
			DefaultPermissions::registerCorePermissions();
		}
	}

	/**
	 * @phpstan-return Player&MockObject
	 */
	private function player(bool $op = false, bool $creative = false, bool $adventure = false) : Player{
		$player = $this->createMock(Player::class);
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getLocation")->willReturn(new Location(4, 10, 4, $this->world, 0, 0));
		$player->method("isCreative")->willReturn($creative);
		$player->method("isSurvival")->willReturn(!$creative && !$adventure);
		$player->method("isAdventure")->willReturn($adventure);
		$player->method("hasFiniteResources")->willReturn(!$creative);
		$player->method("hasPermission")->willReturnCallback(fn($perm) => $op && $perm === DefaultPermissions::ROOT_OPERATOR);
		return $player;
	}

	private function placeStone(Player $player, int $x, int $y, int $z) : bool{
		$item = VanillaBlocks::STONE()->asItem();
		return $this->world->useItemOn(new Vector3($x, $y - 1, $z), $item, Facing::UP, new Vector3(0.5, 1, 0.5), $player);
	}

	private function breakBlockAs(Player $player, int $x, int $y, int $z) : bool{
		$item = VanillaItems::AIR();
		return $this->world->useBreakOn(new Vector3($x, $y, $z), $item, $player);
	}

	public function testWorldsWithoutAllowOrDenyAreNotChecked() : void{
		self::assertFalse(BuildPermissionBlocks::hasBlocks($this->world));
		self::assertNull(BuildPermissionBlocks::getRule($this->world, 0, 10, 0));
		$this->set(0, 4, 0, VanillaBlocks::STONE());
		self::assertTrue($this->placeStone($this->player(), 0, 5, 0));
	}

	public function testDenyForbidsBuildingAbove() : void{
		$this->set(0, 1, 0, VanillaBlocks::DENY());
		self::assertTrue(BuildPermissionBlocks::hasBlocks($this->world));
		self::assertFalse(BuildPermissionBlocks::getRule($this->world, 0, 2, 0));
		self::assertFalse(BuildPermissionBlocks::getRule($this->world, 0, 100, 0), "any height above");
		self::assertNull(BuildPermissionBlocks::getRule($this->world, 0, 1, 0), "not the block itself");
		self::assertNull(BuildPermissionBlocks::getRule($this->world, 1, 5, 0), "only its column");

		$this->set(0, 4, 0, VanillaBlocks::STONE());
		self::assertFalse($this->placeStone($this->player(), 0, 5, 0));
		self::assertSame(BlockTypeIds::AIR, $this->get(0, 5, 0)->getTypeId());
		self::assertFalse($this->breakBlockAs($this->player(), 0, 4, 0));
		self::assertSame(BlockTypeIds::STONE, $this->get(0, 4, 0)->getTypeId());

		//operators in creative mode build anyway
		self::assertTrue($this->placeStone($this->player(true, true), 0, 5, 0));
	}

	public function testHighestBlockWins() : void{
		$this->set(0, 1, 0, VanillaBlocks::DENY());
		$this->set(0, 3, 0, VanillaBlocks::ALLOW());
		self::assertTrue(BuildPermissionBlocks::getRule($this->world, 0, 10, 0));
		self::assertFalse(BuildPermissionBlocks::getRule($this->world, 0, 3, 0));

		$this->set(0, 30, 0, VanillaBlocks::DENY());
		self::assertFalse(BuildPermissionBlocks::getRule($this->world, 0, 40, 0));
		self::assertTrue(BuildPermissionBlocks::getRule($this->world, 0, 20, 0));
	}

	public function testAllowLetsAdventurePlayersBuild() : void{
		$this->set(0, 4, 0, VanillaBlocks::STONE());
		$this->set(2, 4, 0, VanillaBlocks::STONE());
		$this->set(0, 1, 0, VanillaBlocks::ALLOW());
		$adventure = $this->player(adventure: true);
		self::assertTrue($this->placeStone($adventure, 0, 5, 0));
		//elsewhere adventure mode still forbids it
		self::assertFalse($this->placeStone($adventure, 2, 5, 0));
	}

	public function testChunkScanFindsDenyBlocks() : void{
		$chunk = new Chunk([], true);
		self::assertFalse(BuildPermissionBlocks::hasBlocks($this->world));
		self::assertFalse(BuildPermissionBlocks::chunkHasBlocks($this->world, $chunk));
		self::assertFalse(BuildPermissionBlocks::hasBlocks($this->world));

		//a chunk loaded with a deny block in it (no placement hook ran)
		$loaded = new Chunk([], true);
		$loaded->setBlockStateId(3, 10, 3, VanillaBlocks::DENY()->getStateId());
		self::assertTrue(BuildPermissionBlocks::chunkHasBlocks($this->world, $loaded));
		self::assertTrue(BuildPermissionBlocks::hasBlocks($this->world));
	}

	public function testDenyInALoadedChunkIsFoundOnTheFirstBuildCheck() : void{
		$chunk = $this->world->getChunk(0, 0);
		self::assertNotNull($chunk);
		//written straight into the chunk, like a chunk loaded from disk
		$chunk->setBlockStateId(0, 1, 0, VanillaBlocks::DENY()->getStateId());
		self::assertFalse(BuildPermissionBlocks::hasBlocks($this->world));
		self::assertFalse(BuildPermissionBlocks::getRule($this->world, 0, 5, 0));
		self::assertTrue(BuildPermissionBlocks::hasBlocks($this->world));
	}

	public function testBreakingNeedsOperatorInCreative() : void{
		foreach([VanillaBlocks::ALLOW(), VanillaBlocks::DENY(), VanillaBlocks::BORDER_BLOCK()] as $block){
			$this->set(0, 1, 0, $block);
			self::assertFalse($this->breakBlockAs($this->player(creative: true), 0, 1, 0), $block->getName());
			self::assertSame($block->getTypeId(), $this->get(0, 1, 0)->getTypeId());
			self::assertTrue($this->breakBlockAs($this->player(true, true), 0, 1, 0), $block->getName());
			self::assertSame(BlockTypeIds::AIR, $this->get(0, 1, 0)->getTypeId());
		}
	}

	public function testPlacementNeedsOperatorInCreative() : void{
		$this->set(0, 4, 0, VanillaBlocks::STONE());
		foreach([VanillaBlocks::ALLOW(), VanillaBlocks::DENY(), VanillaBlocks::BORDER_BLOCK()] as $block){
			$item = $block->asItem();
			self::assertFalse($this->world->useItemOn(new Vector3(0, 4, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5), $this->player()), $block->getName());
		}
		$item = VanillaBlocks::DENY()->asItem();
		self::assertTrue($this->world->useItemOn(new Vector3(0, 4, 0), $item, Facing::UP, new Vector3(0.5, 1, 0.5), $this->player(true, true)));
		self::assertInstanceOf(Deny::class, $this->get(0, 5, 0));
	}

	public function testNoDrops() : void{
		$air = VanillaItems::AIR();
		self::assertSame([], VanillaBlocks::ALLOW()->getDrops($air));
		self::assertSame([], VanillaBlocks::DENY()->getDrops($air));
		self::assertSame([], VanillaBlocks::BORDER_BLOCK()->getDrops($air));
	}

	public function testBorderCollidesLikeAWall() : void{
		$this->set(0, 1, 0, VanillaBlocks::BORDER_BLOCK());
		$boxes = $this->get(0, 1, 0)->getCollisionBoxes();
		self::assertNotEmpty($boxes);
		$maxY = 0.0;
		foreach($boxes as $box){
			$maxY = max($maxY, $box->maxY);
		}
		self::assertEqualsWithDelta(2.5, $maxY, 0.0001, "1.5 blocks tall");

		//connects to walls and other borders like walls do
		$this->set(1, 1, 0, VanillaBlocks::COBBLESTONE_WALL());
		$this->runTicks(1);
		$border = $this->get(0, 1, 0);
		self::assertInstanceOf(BorderBlock::class, $border);
		self::assertNotNull($border->getConnection(Facing::EAST));
	}
}
