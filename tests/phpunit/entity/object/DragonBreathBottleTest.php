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

namespace pocketmine\entity\object;

use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\ItemUseResult;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;

final class DragonBreathBottleTest extends MobTestCase{

	private function player(float $x, float $z) : Player{
		$player = $this->createMock(Player::class);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getPosition")->willReturn(new Position($x, 1, $z, $this->world));
		$player->method("getBoundingBox")->willReturn(new AxisAlignedBB($x - 0.3, 1, $z - 0.3, $x + 0.3, 2.8, $z + 0.3));
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	public function testBottlingTheBreathShrinksTheCloud() : void{
		$cloud = new DragonBreathCloud(new Location(0.5, 1, 0.5, $this->world, 0, 0), null, 3.0);
		$this->runTicks(1);

		$bottle = VanillaItems::GLASS_BOTTLE()->setCount(2);
		$returned = [];
		self::assertSame(ItemUseResult::SUCCESS, $bottle->onClickAir($this->player(4.0, 0.5), new Vector3(0, 0, 1), $returned));
		self::assertCount(1, $returned);
		self::assertSame(ItemTypeIds::DRAGON_BREATH, $returned[0]->getTypeId());
		self::assertSame(1, $bottle->getCount());
		self::assertEqualsWithDelta(2.5, $cloud->getRadius(), 0.001, "the cloud shrinks by half a block");

		$returned = [];
		self::assertSame(ItemUseResult::NONE, $bottle->onClickAir($this->player(12.0, 0.5), new Vector3(0, 0, 1), $returned), "too far from the cloud");
		self::assertCount(0, $returned);
	}

	public function testTheLastBottleRemovesTheCloud() : void{
		$cloud = new DragonBreathCloud(new Location(0.5, 1, 0.5, $this->world, 0, 0), null, 0.9);
		$this->runTicks(1);
		$returned = [];
		$block = $this->world->getBlockAt(0, 0, 0);
		self::assertSame(ItemUseResult::SUCCESS, VanillaItems::GLASS_BOTTLE()->onInteractBlock($this->player(0.5, 0.5), $block, $block, 1, new Vector3(0.5, 1, 0.5), $returned));
		self::assertSame(ItemTypeIds::DRAGON_BREATH, $returned[0]->getTypeId());
		self::assertTrue($cloud->isFlaggedForDespawn());
	}
}
