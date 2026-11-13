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

namespace pocketmine\block\vault;

use PHPUnit\Framework\TestCase;
use pocketmine\block\utils\VaultState;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\generator\vanilla\structure\TrialChamberContent;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use function array_map;
use function min;

final class VaultLogicTest extends TestCase{

	public static function setUpBeforeClass() : void{
		parent::setUpBeforeClass();
		//The standalone vault tests must register key serialization without also
		//registering the Trial Chamber BLOCK states into the global runtime registry.
		//Doing so would pollute the independent block round-trip suite.
		foreach([false, true] as $ominous){
			$id = $ominous ? "minecraft:ominous_trial_key" : "minecraft:trial_key";
			$key = TrialChamberContent::trialKey($ominous);
			$deserializer = GlobalItemDataHandlers::getDeserializer();
			if($deserializer->getDeserializerForId($id) === null){
				$deserializer->map($id, static fn() : Item => clone $key);
				GlobalItemDataHandlers::getSerializer()->map($key, static fn() : SavedItemData => new SavedItemData($id));
			}
		}
	}


	private function host(VaultConfig $config) : FakeVaultHost{
		$host = new FakeVaultHost();
		$host->tables[$config->getLootTable()] = FakeVaultHost::rewardItems();
		return $host;
	}

	/**
	 * Runs the vault like the block does (only at the ticks it asks for) until $until.
	 */
	private function runUntil(FakeVaultHost $host, VaultConfig $config, VaultData $data, int $until) : void{
		while($host->time < $until){
			$delay = VaultLogic::getNextUpdateDelay($host->state, $data, $host);
			$host->time += min($delay, $until - $host->time);
			VaultLogic::tick($host->state, $config, $data, $host);
		}
	}

	/**
	 * Makes the vault active with player "a" (entity 1) near it.
	 */
	private function activate(FakeVaultHost $host, VaultConfig $config, VaultData $data) : void{
		$host->players["a"] = [1, 2.0];
		$this->runUntil($host, $config, $data, $host->time + VaultLogic::UPDATE_CONNECTED_PLAYERS_TICK_RATE);
		self::assertSame(VaultState::ACTIVE, $host->state);
	}

	public function testTurnsOnForUnrewardedPlayersNearby() : void{
		$config = new VaultConfig();
		$host = $this->host($config);
		$data = new VaultData();
		$host->players["far"] = [1, 4.0]; //activation range 4: block distance must be below it
		$this->runUntil($host, $config, $data, 40);
		self::assertSame(VaultState::INACTIVE, $host->state);
		self::assertSame([], $host->events);

		$host->players["near"] = [2, 3.0];
		$this->runUntil($host, $config, $data, 60);
		self::assertSame(VaultState::ACTIVE, $host->state);
		self::assertSame(["activate"], $host->events);
		self::assertSame(["near" => 2], $data->getConnectedPlayers());
		self::assertNotNull($data->getDisplayItem(), "an item of the loot table is shown");

		//once on, it stays on while players are within 4.5 blocks
		$host->players["near"] = [2, 4.2];
		$this->runUntil($host, $config, $data, 100);
		self::assertSame(VaultState::ACTIVE, $host->state);
		self::assertSame(["far" => 1, "near" => 2], $data->getConnectedPlayers());
		$host->players["near"] = [2, 4.6];
		$host->players["far"] = [1, 4.5];
		$this->runUntil($host, $config, $data, 140);
		self::assertSame(VaultState::INACTIVE, $host->state);
		self::assertSame(["activate", "deactivate"], $host->events);
		self::assertNull($data->getDisplayItem());
	}

	public function testShownItemCyclesEvery20Ticks() : void{
		$config = new VaultConfig();
		$host = $this->host($config);
		$data = new VaultData();
		$this->activate($host, $config, $data);
		$first = $data->getDisplayItem();
		self::assertNotNull($first);
		$data->clearSharedDirty();
		//the next player scan comes first, the next item at the next multiple of 20 ticks
		$this->runUntil($host, $config, $data, $host->time - $host->time % 20 + 19);
		self::assertFalse($data->isSharedDirty());
		self::assertSame(1, VaultLogic::getNextUpdateDelay($host->state, $data, $host));
		$this->runUntil($host, $config, $data, $host->time + 1);
		self::assertTrue($data->isSharedDirty());
		self::assertFalse($first->equalsExact($data->getDisplayItem() ?? VanillaItems::AIR()));
	}

	public function testKeyOpensTheVaultOncePerPlayer() : void{
		$config = new VaultConfig();
		$host = $this->host($config);
		$data = new VaultData();
		$this->activate($host, $config, $data);
		$host->events = [];

		self::assertSame(0, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, VanillaItems::DIAMOND()), "not the key");
		self::assertSame(["insert fail"], $host->events);
		self::assertSame(VaultState::ACTIVE, $host->state);

		$keyTime = $host->time;
		self::assertSame(1, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, TrialChamberContent::trialKey()->setCount(3)));
		self::assertSame(VaultState::UNLOCKING, $host->state);
		self::assertSame([VaultConfig::DEFAULT_LOOT_TABLE], $host->rolledTables);
		self::assertSame([1], $host->rolledFor);
		self::assertTrue($data->hasRewardedPlayer("a"));
		self::assertSame([], $data->getConnectedPlayers(), "a rewarded player no longer counts");
		self::assertSame(["insert fail", "insert"], $host->events);

		self::assertTrue(VanillaItems::ARROW()->equalsExact($data->getDisplayItem() ?? VanillaItems::AIR()), "it shows the next item to eject");

		$this->runUntil($host, $config, $data, $keyTime + 200);
		//like vanilla, the last item of the roll comes out first
		self::assertSame(["Arrow x1", "Diamond x2", "Emerald x1"], array_map(fn(Item $item) => $item->getVanillaName() . " x" . $item->getCount(), $host->ejected));
		//14 ticks to open, 20 more before the first item, then one every 20 ticks
		self::assertSame([$keyTime + 34, $keyTime + 54, $keyTime + 74], $host->ejectTimes);
		self::assertSame(["insert fail", "insert", "open", "close", "deactivate"], $host->events);
		self::assertSame(VaultState::INACTIVE, $host->state);
		self::assertSame(0, $data->getTotalEjectionsNeeded());

		//the player stays rewarded: the vault doesn't turn on for them any more
		$this->runUntil($host, $config, $data, $host->time + 60);
		self::assertSame(VaultState::INACTIVE, $host->state);
		self::assertSame(0, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, TrialChamberContent::trialKey()), "inactive vaults ignore keys");
	}

	public function testRewardedPlayerKeyIsRefused() : void{
		$config = new VaultConfig();
		$host = $this->host($config);
		$data = new VaultData();
		$data->addRewardedPlayer("a");
		$host->players["a"] = [1, 1.0];
		$host->players["b"] = [2, 1.0]; //keeps the vault on
		$this->runUntil($host, $config, $data, 20);
		self::assertSame(VaultState::ACTIVE, $host->state);
		self::assertSame(["b" => 2], $data->getConnectedPlayers());
		$host->events = [];

		self::assertSame(0, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, TrialChamberContent::trialKey()));
		self::assertSame(["reject rewarded"], $host->events);
		self::assertSame([], $host->rolledTables);
		//the refusal sound isn't repeated within 15 ticks
		$host->time += 5;
		self::assertSame(0, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, TrialChamberContent::trialKey()));
		self::assertSame(["reject rewarded"], $host->events);
		$host->time += 10;
		self::assertSame(0, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, TrialChamberContent::trialKey()));
		self::assertSame(["reject rewarded", "reject rewarded"], $host->events);

		self::assertSame(1, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "b", 2, TrialChamberContent::trialKey()));
	}

	public function testOminousVaultNeedsTheOminousKey() : void{
		$config = VaultConfig::ominous();
		$host = $this->host($config);
		$host->ominous = true;
		$data = new VaultData();
		$this->activate($host, $config, $data);
		self::assertSame(["activate ominous"], $host->events);

		self::assertSame(0, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, TrialChamberContent::trialKey()));
		self::assertSame(VaultState::ACTIVE, $host->state);
		self::assertSame(1, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, TrialChamberContent::trialKey(ominous: true)));
		self::assertSame([VaultConfig::OMINOUS_LOOT_TABLE], $host->rolledTables);
	}

	public function testNothingHappensWithoutRewards() : void{
		$config = new VaultConfig("loot_tables/empty.json");
		$host = new FakeVaultHost();
		$data = new VaultData();
		$this->activate($host, $config, $data);
		self::assertNull($data->getDisplayItem());
		self::assertSame(0, VaultLogic::tryInsertKey($host->state, $config, $data, $host, "a", 1, TrialChamberContent::trialKey()));
		self::assertSame(VaultState::ACTIVE, $host->state);
		self::assertFalse($data->hasRewardedPlayer("a"));
	}

	public function testRemembersAtMost128Players() : void{
		$data = new VaultData();
		for($i = 0; $i < VaultData::MAX_REWARDED_PLAYERS + 2; ++$i){
			$data->addRewardedPlayer("p$i");
		}
		self::assertCount(VaultData::MAX_REWARDED_PLAYERS, $data->getRewardedPlayers());
		self::assertFalse($data->hasRewardedPlayer("p0"));
		self::assertFalse($data->hasRewardedPlayer("p1"));
		self::assertTrue($data->hasRewardedPlayer("p2"));
		self::assertTrue($data->hasRewardedPlayer("p129"));
	}

	public function testDataSaveRoundTrip() : void{
		$data = new VaultData();
		$data->addRewardedPlayer("a");
		$data->addRewardedPlayer("b");
		$data->setItemsToEject(FakeVaultHost::rewardItems());
		$data->setDisplayItem(VanillaItems::EMERALD());
		$nbt = CompoundTag::create();
		$data->writeSaveData($nbt);

		$loaded = new VaultData();
		$loaded->readSaveData($nbt);
		self::assertSame(["a", "b"], $loaded->getRewardedPlayers());
		self::assertCount(3, $loaded->getItemsToEject());
		self::assertSame(3, $loaded->getTotalEjectionsNeeded());
		self::assertTrue(VanillaItems::ARROW()->equalsExact($loaded->getNextItemToEject() ?? VanillaItems::AIR()));
		self::assertTrue(VanillaItems::EMERALD()->equalsExact($loaded->getDisplayItem() ?? VanillaItems::AIR()));
	}

	public function testBedrockDataIsKept() : void{
		//as BDS 1.26.52 saves it (actor IDs of the rewarded players)
		$nbt = CompoundTag::create()
			->setTag(VaultData::TAG_ITEMS_TO_EJECT, new ListTag([], NBT::TAG_Compound))
			->setTag(VaultData::TAG_REWARDED_PLAYERS, new ListTag([new LongTag(-4294967295), new LongTag(1)], NBT::TAG_Long))
			->setLong(VaultData::TAG_STATE_UPDATING_RESUMES_AT, 1761);
		$data = new VaultData();
		$data->readSaveData($nbt);
		self::assertSame(0, $data->stateUpdatingResumesAt, "server ticks of another server mean nothing");
		$saved = CompoundTag::create();
		$data->writeSaveData($saved);
		$ids = $saved->getListTag(VaultData::TAG_REWARDED_PLAYERS);
		self::assertNotNull($ids);
		self::assertSame([-4294967295, 1], $ids->getAllValues());
	}

	public function testConfigNbt() : void{
		$config = VaultConfig::ominous();
		$nbt = $config->toNbt();
		self::assertSame(VaultConfig::OMINOUS_LOOT_TABLE, $nbt->getString(VaultConfig::TAG_LOOT_TABLE));
		self::assertSame(4.0, $nbt->getFloat(VaultConfig::TAG_ACTIVATION_RANGE));
		self::assertSame(4.5, $nbt->getFloat(VaultConfig::TAG_DEACTIVATION_RANGE));
		self::assertSame("", $nbt->getString(VaultConfig::TAG_OVERRIDE_LOOT_TABLE_TO_DISPLAY));
		$key = $nbt->getCompoundTag(VaultConfig::TAG_KEY_ITEM);
		self::assertNotNull($key);
		self::assertSame("minecraft:ominous_trial_key", $key->getString("Name"));
		self::assertSame(1, $key->getByte("Count"));

		$loaded = VaultConfig::fromNbt($nbt);
		self::assertSame(VaultConfig::OMINOUS_LOOT_TABLE, $loaded->getLootTable());
		self::assertTrue($loaded->isKey(TrialChamberContent::trialKey(ominous: true)));
		self::assertFalse($loaded->isKey(TrialChamberContent::trialKey()));
		self::assertNull($loaded->getOverrideLootTableToDisplay());

		//a key item of two keys takes two
		$two = new VaultConfig(keyItem: TrialChamberContent::trialKey()->setCount(2));
		self::assertFalse($two->isKey(TrialChamberContent::trialKey()));
		self::assertTrue($two->isKey(TrialChamberContent::trialKey()->setCount(2)));
		//a vault without a key never opens
		self::assertFalse((new VaultConfig(keyItem: VanillaItems::AIR()))->hasKey());
	}

	public function testBedrockConfigIsRead() : void{
		//as BDS 1.26.52 saves it
		$config = VaultConfig::fromNbt(CompoundTag::create()
			->setFloat(VaultConfig::TAG_ACTIVATION_RANGE, 4.0)
			->setFloat(VaultConfig::TAG_DEACTIVATION_RANGE, 4.5)
			->setTag(VaultConfig::TAG_KEY_ITEM, CompoundTag::create()
				->setByte("Count", 1)
				->setShort("Damage", 0)
				->setString("Name", "minecraft:trial_key")
				->setByte("WasPickedUp", 0))
			->setString(VaultConfig::TAG_LOOT_TABLE, "loot_tables/chests/trial_chambers/reward.json")
			->setString(VaultConfig::TAG_OVERRIDE_LOOT_TABLE_TO_DISPLAY, ""));
		self::assertSame("loot_tables/chests/trial_chambers/reward.json", $config->getLootTable());
		self::assertSame($config->getLootTable(), $config->getLootTableToDisplay());
		self::assertTrue($config->isKey(TrialChamberContent::trialKey()));
		self::assertSame(4.0, $config->getActivationRange());
		self::assertSame(4.5, $config->getDeactivationRange());
	}
}
