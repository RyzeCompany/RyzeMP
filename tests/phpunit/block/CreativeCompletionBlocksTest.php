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

use pocketmine\block\tile\Conduit as TileConduit;
use pocketmine\block\utils\CreakingHeartState;
use pocketmine\block\utils\VaultState;
use pocketmine\item\VanillaItems;
use pocketmine\math\Axis;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\World;

/**
 * Trial spawner, vault, creaking heart, dried ghast and conduit.
 */
class CreativeCompletionBlocksTest extends RedstoneTestCase{

	public function testCreakingHeartNeedsPaleOakLogsOnItsAxis() : void{
		$this->floor(-1, -1, 1, 1);
		$this->world->setTime(World::TIME_NOON);
		$this->set(0, 2, 0, VanillaBlocks::CREAKING_HEART());
		$this->runTicks(1);
		$heart = $this->get(0, 2, 0);
		self::assertInstanceOf(CreakingHeart::class, $heart);
		self::assertSame(CreakingHeartState::UPROOTED, $heart->getHeartState());

		$this->set(0, 1, 0, VanillaBlocks::PALE_OAK_LOG()->setAxis(Axis::Y));
		$this->set(0, 3, 0, VanillaBlocks::PALE_OAK_LOG()->setAxis(Axis::Y));
		$this->runTicks(1);
		$heart = $this->get(0, 2, 0);
		self::assertInstanceOf(CreakingHeart::class, $heart);
		self::assertSame(CreakingHeartState::DORMANT, $heart->getHeartState(), "rooted by day");

		$this->world->setTime(World::TIME_MIDNIGHT);
		$heart->onRandomTick();
		$heart = $this->get(0, 2, 0);
		self::assertInstanceOf(CreakingHeart::class, $heart);
		self::assertSame(CreakingHeartState::AWAKE, $heart->getHeartState(), "rooted at night");

		//a log on another axis doesn't count
		$this->set(0, 3, 0, VanillaBlocks::PALE_OAK_LOG()->setAxis(Axis::X));
		$this->runTicks(1);
		$heart = $this->get(0, 2, 0);
		self::assertInstanceOf(CreakingHeart::class, $heart);
		self::assertSame(CreakingHeartState::UPROOTED, $heart->getHeartState());
	}

	public function testCreakingHeartDropsResin() : void{
		$drops = VanillaBlocks::CREAKING_HEART()->getDrops(VanillaItems::WOODEN_AXE());
		self::assertCount(1, $drops);
		self::assertTrue($drops[0]->equals(VanillaBlocks::RESIN_CLUMP()->asItem(), false, false));
		self::assertGreaterThanOrEqual(1, $drops[0]->getCount());
		self::assertLessThanOrEqual(3, $drops[0]->getCount());
	}

	public function testSpawnerBlocksNeverDrop() : void{
		self::assertCount(0, VanillaBlocks::TRIAL_SPAWNER()->getDrops(VanillaItems::NETHERITE_PICKAXE()));
		self::assertCount(0, VanillaBlocks::VAULT()->getDrops(VanillaItems::NETHERITE_PICKAXE()));
		self::assertSame(6, VanillaBlocks::VAULT()->getLightLevel());
		self::assertSame(12, VanillaBlocks::VAULT()->setVaultState(VaultState::ACTIVE)->getLightLevel());
		self::assertCount(1, VanillaBlocks::DRIED_GHAST()->getDrops(VanillaItems::AIR()));
	}

	public function testConduitHasBlockEntity() : void{
		$this->floor(-1, -1, 1, 1);
		$this->set(0, 1, 0, VanillaBlocks::CONDUIT());
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(TileConduit::class, $tile);
		self::assertFalse($tile->isActive());
		$spawn = $tile->getSpawnCompound();
		self::assertSame("Conduit", $spawn->getString("id"));
		self::assertSame(-1, $spawn->getLong("Target"));
		self::assertSame(15, VanillaBlocks::CONDUIT()->getLightLevel());
	}
}
