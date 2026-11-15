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

use pocketmine\block\tile\TileFactory;
use pocketmine\block\tile\Vault as TileVault;
use pocketmine\block\utils\VaultState;
use pocketmine\block\vault\VaultConfig;
use pocketmine\block\vault\VaultData;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;

/**
 * Vaults in an in-memory world: block entity, Bedrock data, client data and scheduled updates.
 */
final class VaultTest extends MobTestCase{

	protected function getPocketmineYml() : array{
		return ["chunk-ticking" => ["tick-radius" => 0]] + parent::getPocketmineYml();
	}

	private function vault() : TileVault{
		$this->set(0, 1, 0, VanillaBlocks::VAULT());
		$tile = $this->world->getTile(new Vector3(0, 1, 0));
		self::assertInstanceOf(TileVault::class, $tile);
		return $tile;
	}

	public function testPlacedVaultHasBlockEntity() : void{
		$tile = $this->vault();
		self::assertSame(VaultConfig::DEFAULT_LOOT_TABLE, $tile->getConfig()->getLootTable());
		self::assertTrue($tile->getConfig()->isKey(VanillaItems::TRIAL_KEY()));

		$nbt = $tile->saveNBT();
		self::assertSame("Vault", $nbt->getString("id"));
		$config = $nbt->getCompoundTag(TileVault::TAG_CONFIG);
		self::assertNotNull($config);
		self::assertSame("minecraft:trial_key", $config->getCompoundTag(VaultConfig::TAG_KEY_ITEM)?->getString("Name"));
		self::assertNotNull($nbt->getCompoundTag(TileVault::TAG_DATA));
	}

	public function testBedrockBlockEntityRoundTrip() : void{
		//an ominous vault saved like BDS 1.26.52 saves them (rewarded players: their Bedrock actor IDs)
		$nbt = CompoundTag::create()
			->setString("id", "Vault")
			->setInt("x", 0)->setInt("y", 1)->setInt("z", 0)
			->setTag(TileVault::TAG_CONFIG, CompoundTag::create()
				->setFloat(VaultConfig::TAG_ACTIVATION_RANGE, 4.0)
				->setFloat(VaultConfig::TAG_DEACTIVATION_RANGE, 4.5)
				->setTag(VaultConfig::TAG_KEY_ITEM, CompoundTag::create()
					->setByte("Count", 1)
					->setShort("Damage", 0)
					->setString("Name", "minecraft:ominous_trial_key")
					->setByte("WasPickedUp", 0))
				->setString(VaultConfig::TAG_LOOT_TABLE, VaultConfig::OMINOUS_LOOT_TABLE)
				->setString(VaultConfig::TAG_OVERRIDE_LOOT_TABLE_TO_DISPLAY, ""))
			->setTag(TileVault::TAG_DATA, CompoundTag::create()
				->setTag(VaultData::TAG_ITEMS_TO_EJECT, new ListTag([], NBT::TAG_Compound))
				->setTag(VaultData::TAG_REWARDED_PLAYERS, new ListTag([new LongTag(1), new LongTag(-12884901887)], NBT::TAG_Long))
				->setLong(VaultData::TAG_STATE_UPDATING_RESUMES_AT, 739));
		$this->set(0, 1, 0, VanillaBlocks::VAULT()->setOminous(true));
		$old = $this->world->getTile(new Vector3(0, 1, 0));
		self::assertNotNull($old);
		$this->world->removeTile($old);
		$tile = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileVault::class, $tile);
		self::assertTrue($tile->getConfig()->isKey(VanillaItems::OMINOUS_TRIAL_KEY()));
		self::assertFalse($tile->getConfig()->isKey(VanillaItems::TRIAL_KEY()));
		self::assertSame(VaultConfig::OMINOUS_LOOT_TABLE, $tile->getConfig()->getLootTable());

		$saved = $tile->saveNBT();
		self::assertSame([1, -12884901887], $saved->getCompoundTag(TileVault::TAG_DATA)?->getListTag(VaultData::TAG_REWARDED_PLAYERS)?->getAllValues());
		self::assertSame(VaultConfig::OMINOUS_LOOT_TABLE, $saved->getCompoundTag(TileVault::TAG_CONFIG)?->getString(VaultConfig::TAG_LOOT_TABLE));
	}

	public function testRewardedPlayersAreSaved() : void{
		$tile = $this->vault();
		$tile->getData()->addRewardedPlayer("6ba7b810-9dad-11d1-80b4-00c04fd430c8");
		$nbt = $tile->saveNBT();
		$this->world->removeTile($tile);
		$loaded = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileVault::class, $loaded);
		self::assertTrue($loaded->getData()->hasRewardedPlayer("6ba7b810-9dad-11d1-80b4-00c04fd430c8"));
		self::assertFalse($loaded->getData()->hasRewardedPlayer("6ba7b811-9dad-11d1-80b4-00c04fd430c8"));
	}

	public function testClientDataLikeBedrock() : void{
		$tile = $this->vault();
		$nbt = $tile->getSpawnCompound();
		self::assertSame(4.5, $nbt->getFloat(TileVault::TAG_CONNECTED_PARTICLE_RANGE));
		self::assertSame([], $nbt->getListTag(TileVault::TAG_CONNECTED_PLAYERS)?->getAllValues());
		$display = $nbt->getCompoundTag(TileVault::TAG_DISPLAY_ITEM);
		self::assertNotNull($display);
		self::assertSame("", $display->getString("Name"), "no item shown: an empty item, like Bedrock");
		self::assertSame(0, $display->getByte("Count"));

		$tile->getData()->setConnectedPlayers(["a" => 7]);
		$tile->getData()->setDisplayItem(VanillaItems::EMERALD()->setCount(3));
		$tile->clearSpawnCompoundCache();
		$nbt = $tile->getSpawnCompound();
		self::assertSame([7], $nbt->getListTag(TileVault::TAG_CONNECTED_PLAYERS)?->getAllValues());
		self::assertSame("minecraft:emerald", $nbt->getCompoundTag(TileVault::TAG_DISPLAY_ITEM)?->getString("Name"));
		self::assertSame(3, $nbt->getCompoundTag(TileVault::TAG_DISPLAY_ITEM)?->getByte("Count"));
		self::assertNull($nbt->getCompoundTag(TileVault::TAG_CONFIG), "the config isn't sent");
	}

	public function testVaultWithoutPlayersStaysInactiveAndKeepsOneUpdate() : void{
		$this->vault();
		$this->runTicks(65);
		$block = $this->get(0, 1, 0);
		self::assertInstanceOf(Vault::class, $block);
		self::assertSame(VaultState::INACTIVE, $block->getVaultState());
		self::assertTrue($this->world->isBlockUpdateScheduled(0, 1, 0), "the vault looks for players every 20 ticks");
	}

	public function testVaultWithoutBlockEntityGetsOne() : void{
		//vaults generated before vaults had a block entity
		$this->set(0, 1, 0, VanillaBlocks::VAULT()->setOminous(true));
		$old = $this->world->getTile(new Vector3(0, 1, 0));
		self::assertNotNull($old);
		$this->world->removeTile($old);
		$old->close();
		self::assertNull($this->world->getTile(new Vector3(0, 1, 0)));

		$this->get(0, 1, 0)->onInteract(VanillaItems::OMINOUS_TRIAL_KEY(), 1, new Vector3(0.5, 0.5, 0.5));
		$tile = $this->world->getTile(new Vector3(0, 1, 0));
		self::assertInstanceOf(TileVault::class, $tile);
		self::assertTrue($tile->getConfig()->isKey(VanillaItems::OMINOUS_TRIAL_KEY()), "an ominous vault gets the ominous config");
		self::assertTrue($this->world->isBlockUpdateScheduled(0, 1, 0));
	}

	public function testInactiveVaultIgnoresTheKey() : void{
		$this->vault();
		$block = $this->get(0, 1, 0);
		$item = VanillaItems::TRIAL_KEY();
		self::assertFalse($block->onInteract($item, 1, new Vector3(0.5, 0.5, 0.5)));
		self::assertSame(1, $item->getCount());
	}
}
