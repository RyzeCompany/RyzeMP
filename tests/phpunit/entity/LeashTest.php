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

namespace pocketmine\entity;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\LeashKnot;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function count;

final class LeashTest extends MobTestCase{
	private Vector3 $playerPos;

	private function player(Item $held) : Player{
		$this->playerPos = new Vector3(0.5, 1, 3.5);
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$uuid = Uuid::uuid4();
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn($uuid);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("isAlive")->willReturn(true);
		$player->method("getId")->willReturn(999999);
		$player->method("getPosition")->willReturnCallback(fn() => Position::fromObject($this->playerPos, $this->world));
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private function droppedLeads() : int{
		return count(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof ItemEntity && !$e->isFlaggedForDespawn() && $e->getItem()->getTypeId() === ItemTypeIds::LEAD));
	}

	public function testLeadAndBreak() : void{
		$player = $this->player(VanillaItems::LEAD());
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		self::assertTrue($cow->onInteract($player, new Vector3(0, 0, 0)));
		self::assertSame($player, $cow->getLeashHolder());
		self::assertTrue($player->getInventory()->getItemInHand()->isNull(), "lead used");
		self::assertFalse($cow->canDespawn());

		$this->playerPos = new Vector3(0.5, 1, 30.5);
		$this->runTicks(2);
		self::assertFalse($cow->isLeashed(), "too far: the lead breaks");
		self::assertSame(1, $this->droppedLeads());
		$cow->close();
	}

	public function testLetGoByInteracting() : void{
		$player = $this->player(VanillaItems::LEAD());
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->onInteract($player, new Vector3(0, 0, 0));
		self::assertTrue($cow->onInteract($player, new Vector3(0, 0, 0)));
		self::assertFalse($cow->isLeashed());
		self::assertSame(1, $this->droppedLeads());
		$cow->close();
	}

	public function testVillagersCantBeLed() : void{
		$player = $this->player(VanillaItems::LEAD());
		$villager = new VillagerV2(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$villager->onInteract($player, new Vector3(0, 0, 0));
		self::assertFalse($villager->isLeashed());
		$villager->close();
	}

	public function testTieToFenceAndBreakIt() : void{
		$player = $this->player(VanillaItems::LEAD());
		$cow = new Cow(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$cow->onInteract($player, new Vector3(0, 0, 0));
		$this->set(2, 1, 0, VanillaBlocks::OAK_FENCE());
		self::assertTrue($this->get(2, 1, 0)->onInteract(VanillaItems::AIR(), 1, new Vector3(0.5, 0.5, 0.5), $player));
		$knot = $cow->getLeashHolder();
		self::assertInstanceOf(LeashKnot::class, $knot);
		self::assertTrue($knot->getFencePosition()->equals(new Vector3(2, 1, 0)));
		$nbt = $cow->saveNBT();
		self::assertNotNull($nbt->getListTag(Mob::TAG_LEASH_FENCE));

		$this->set(2, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(21);
		self::assertFalse($cow->isLeashed(), "the fence is gone");
		self::assertSame(1, $this->droppedLeads());
		$cow->close();
	}
}
