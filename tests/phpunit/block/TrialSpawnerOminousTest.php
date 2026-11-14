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

use pocketmine\block\tile\TrialSpawner as TileTrialSpawner;
use pocketmine\block\trialspawner\TrialSpawnerConfigs;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\OminousItemSpawner;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\SplashPotion as SplashPotionEntity;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems;
use pocketmine\loot\LootData;
use pocketmine\loot\LootTables;
use pocketmine\math\Vector3;
use pocketmine\utils\Filesystem;
use function array_filter;
use function array_values;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use const JSON_THROW_ON_ERROR;

/**
 * Ominous trial spawners in an in-memory world: the ominous state of the block, the equipment of ominous mobs and the
 * ominous item spawner entity.
 */
final class TrialSpawnerOminousTest extends MobTestCase{

	private ?string $lootDir = null;

	protected function getPocketmineYml() : array{
		return ["chunk-ticking" => ["tick-radius" => 0]] + parent::getPocketmineYml();
	}

	protected function tearDown() : void{
		if($this->lootDir !== null){
			LootTables::setDataRoot(null);
			Filesystem::recursiveUnlink($this->lootDir);
			$this->lootDir = null;
		}
		parent::tearDown();
	}

	private function spawner(string $config) : TileTrialSpawner{
		$this->set(0, 1, 0, VanillaBlocks::TRIAL_SPAWNER());
		$tile = $this->world->getTile(new Vector3(0, 1, 0));
		self::assertInstanceOf(TileTrialSpawner::class, $tile);
		$tile->setConfigNames("minecraft:trial_chamber/$config/normal", "minecraft:trial_chamber/$config/ominous");
		return $tile;
	}

	/**
	 * @phpstan-template T of Entity
	 * @phpstan-param class-string<T> $class
	 * @return Entity[]
	 * @phpstan-return list<T>
	 */
	private function entitiesOf(string $class) : array{
		return array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof $class && !$e->isClosed() && !$e->isFlaggedForDespawn()));
	}

	/**
	 * Vanilla data with a small trial_chamber_melee equipment table: an iron helmet and an iron sword.
	 */
	private function useEquipmentLootData() : void{
		$dir = sys_get_temp_dir() . "/pm-trial-ominous-test-" . uniqid();
		mkdir($dir . "/data/minecraft/loot_table/equipment", 0777, true);
		Filesystem::safeFilePutContents($dir . "/" . LootData::JAVA_ITEMS_FILE, json_encode(["items" => [
			"minecraft:iron_helmet" => [1, 165, 9],
			"minecraft:iron_sword" => [1, 250, 14],
		]], JSON_THROW_ON_ERROR));
		Filesystem::safeFilePutContents($dir . "/data/minecraft/loot_table/equipment/trial_chamber_melee.json", json_encode(["type" => "minecraft:equipment", "pools" => [
			["rolls" => 1, "entries" => [["type" => "minecraft:item", "name" => "minecraft:iron_helmet"]]],
			["rolls" => 1, "entries" => [["type" => "minecraft:item", "name" => "minecraft:iron_sword"]]],
		]], JSON_THROW_ON_ERROR));
		$this->lootDir = $dir;
		LootTables::setDataRoot($dir);
	}

	public function testOminousStateOfTheBlock() : void{
		$tile = $this->spawner("melee/zombie");
		$block = $this->get(0, 1, 0);
		self::assertInstanceOf(TrialSpawner::class, $block);
		$host = $tile->getHost();
		$host->setBlock($block);
		self::assertFalse($host->isOminous());
		self::assertSame($tile->getNormalConfig(), $host->getActiveConfig());
		$host->applyOminous([]);
		self::assertTrue($block->isOminous());
		self::assertSame($tile->getOminousConfig(), $host->getActiveConfig());
		$host->removeOminous();
		self::assertFalse($block->isOminous());
	}

	public function testBecomingOminousRemovesTheMobs() : void{
		$tile = $this->spawner("melee/zombie");
		$normal = TrialSpawnerConfigs::get("minecraft:trial_chamber/melee/zombie/normal");
		self::assertNotNull($normal);
		$block = $this->get(0, 1, 0);
		self::assertInstanceOf(TrialSpawner::class, $block);
		$host = $tile->getHost();
		$host->setBlock($block);
		$id = null;
		for($i = 0; $i < 50 && $id === null; ++$i){
			$data = $normal->pickSpawnData();
			self::assertNotNull($data);
			$id = $host->spawnMob($data, $normal);
		}
		self::assertNotNull($id);
		$zombies = $this->entitiesOf(Zombie::class);
		self::assertCount(1, $zombies);
		$host->applyOminous([$id]);
		self::assertTrue($zombies[0]->isFlaggedForDespawn() || $zombies[0]->isClosed());
		self::assertTrue($block->isOminous());
	}

	public function testOminousMobsGetEquipmentThatNeverDrops() : void{
		$this->useEquipmentLootData();
		$tile = $this->spawner("melee/zombie");
		$ominous = $tile->getOminousConfig();
		$host = $tile->getHost();
		$zombie = null;
		for($i = 0; $i < 50 && $zombie === null; ++$i){
			$data = $ominous->pickSpawnData();
			self::assertNotNull($data);
			self::assertSame("minecraft:equipment/trial_chamber_melee", $data->getEquipmentLootTable());
			if($host->spawnMob($data, $ominous) !== null){
				$zombie = $this->entitiesOf(Zombie::class)[0] ?? null;
			}
		}
		self::assertInstanceOf(Zombie::class, $zombie);
		self::assertTrue($zombie->getArmorInventory()->getHelmet()->equals(VanillaItems::IRON_HELMET(), false, false));
		self::assertTrue($zombie->getMainHandItem()->equals(VanillaItems::IRON_SWORD(), false, false));

		//never dropped, even on player kills (vanilla slot drop chance 0)
		$nbt = $zombie->saveNBT();
		self::assertSame(0b10001, $nbt->getByte("NoDropSlots") & 0b10001);
		$loaded = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(Zombie::class, $loaded);
		self::assertSame($nbt->getByte("NoDropSlots"), $loaded->saveNBT()->getByte("NoDropSlots"));
		$loaded->close();
	}

	public function testItemSpawnerShootsArrowsDownAndVanishes() : void{
		$spawner = new OminousItemSpawner(new Location(0.5, 6.5, 0.5, $this->world, 0, 0), VanillaItems::ARROW());
		$spawner->spawnToAll();
		$after = $spawner->getSpawnItemAfterTicks();
		self::assertGreaterThanOrEqual(OminousItemSpawner::SPAWN_ITEM_DELAY_MIN, $after);
		self::assertLessThanOrEqual(OminousItemSpawner::SPAWN_ITEM_DELAY_MAX, $after);

		$spawner->attack(new EntityDamageEvent($spawner, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 100));
		self::assertTrue($spawner->isAlive(), "cannot be hurt");
		$this->runTicks($after - 2);
		self::assertCount(0, $this->entitiesOf(Arrow::class));
		self::assertFalse($spawner->isFlaggedForDespawn());
		self::assertSame(6.5, $spawner->getPosition()->y, "no gravity");
		$this->runTicks(4);
		$arrows = $this->entitiesOf(Arrow::class);
		self::assertCount(1, $arrows);
		self::assertLessThan(0, $arrows[0]->getMotion()->y, "shot straight down");
		self::assertTrue($spawner->isClosed() || $spawner->isFlaggedForDespawn());
	}

	public function testItemSpawnerItemsAndSave() : void{
		$potion = VanillaItems::LINGERING_POTION()->setType(PotionType::WEAVING);
		$spawner = new OminousItemSpawner(new Location(0.5, 6.5, 0.5, $this->world, 0, 0), $potion);
		$nbt = $spawner->saveNBT();
		$spawner->close();
		$loaded = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(OminousItemSpawner::class, $loaded);
		self::assertTrue($loaded->getItem()->equals($potion));
		self::assertSame($spawner->getSpawnItemAfterTicks(), $loaded->getSpawnItemAfterTicks());
		$loaded->spawnToAll();
		$this->runTicks($loaded->getSpawnItemAfterTicks() + 2);
		$potions = $this->entitiesOf(SplashPotionEntity::class);
		self::assertCount(1, $potions);
		self::assertTrue($potions[0]->willLinger());
		self::assertSame(PotionType::WEAVING, $potions[0]->getPotionType());

		//other items are dropped
		$spawner = new OminousItemSpawner(new Location(3.5, 4.5, 0.5, $this->world, 0, 0), VanillaItems::DIAMOND());
		$spawner->spawnToAll();
		$this->runTicks($spawner->getSpawnItemAfterTicks() + 2);
		$items = $this->entitiesOf(ItemEntity::class);
		self::assertCount(1, $items);
		self::assertTrue($items[0]->getItem()->equals(VanillaItems::DIAMOND()));
	}
}
