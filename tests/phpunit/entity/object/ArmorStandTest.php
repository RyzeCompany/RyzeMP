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

use pocketmine\block\utils\MobHeadType;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;

class ArmorStandTest extends RedstoneTestCase{

	private function spawnStand() : ArmorStand{
		$this->floor(-1, -1, 1, 1);
		$stand = new ArmorStand(Location::fromObject(new Vector3(0.5, 1, 0.5), $this->world));
		$stand->spawnToAll();
		return $stand;
	}

	public function testItemRoundTrip() : void{
		$item = VanillaItems::ARMOR_STAND();
		self::assertSame(16, $item->getMaxStackSize());
		self::assertSame(ItemTypeNames::ARMOR_STAND, GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName());
		self::assertTrue(GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData(ItemTypeNames::ARMOR_STAND))->equals($item));
	}

	public function testEquipmentAndPoseAreSaved() : void{
		$stand = $this->spawnStand();
		$stand->getArmorInventory()->setHelmet(VanillaItems::IRON_HELMET());
		$stand->getArmorInventory()->setBoots(VanillaItems::DIAMOND_BOOTS());
		$stand->setMainHandItem(VanillaItems::DIAMOND_SWORD());
		$stand->setPose(5);
		self::assertSame(6, $stand->getMaxHealth());

		$nbt = $stand->saveNBT();
		$loaded = EntityFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(ArmorStand::class, $loaded);
		self::assertTrue($loaded->getArmorInventory()->getHelmet()->equals(VanillaItems::IRON_HELMET()));
		self::assertTrue($loaded->getArmorInventory()->getChestplate()->isNull());
		self::assertTrue($loaded->getArmorInventory()->getBoots()->equals(VanillaItems::DIAMOND_BOOTS()));
		self::assertTrue($loaded->getMainHandItem()->equals(VanillaItems::DIAMOND_SWORD()));
		self::assertSame(5, $loaded->getPose());
	}

	public function testSlotAtClickedHeight() : void{
		$stand = $this->spawnStand();
		self::assertNull($stand->getSlotAtHeight(1.8), "empty slots fall back to the hand");
		$armor = $stand->getArmorInventory();
		$armor->setHelmet(VanillaItems::IRON_HELMET());
		$armor->setChestplate(VanillaItems::IRON_CHESTPLATE());
		$armor->setLeggings(VanillaItems::IRON_LEGGINGS());
		$armor->setBoots(VanillaItems::IRON_BOOTS());
		self::assertSame(ArmorInventory::SLOT_HEAD, $stand->getSlotAtHeight(1.8));
		self::assertSame(ArmorInventory::SLOT_CHEST, $stand->getSlotAtHeight(1.3));
		self::assertSame(ArmorInventory::SLOT_LEGS, $stand->getSlotAtHeight(0.7));
		self::assertSame(ArmorInventory::SLOT_FEET, $stand->getSlotAtHeight(0.2));
	}

	public function testPumpkinsAndHeadsGoToTheHeadSlot() : void{
		$method = new \ReflectionMethod(ArmorStand::class, 'isWornOnHead');
		self::assertTrue($method->invoke(null, VanillaBlocks::CARVED_PUMPKIN()->asItem()));
		self::assertTrue($method->invoke(null, VanillaBlocks::MOB_HEAD()->setMobHeadType(MobHeadType::CREEPER)->asItem()));
		self::assertFalse($method->invoke(null, VanillaBlocks::PUMPKIN()->asItem()));
		self::assertFalse($method->invoke(null, VanillaItems::STICK()));
		//the armor inventory accepts what the stand puts there
		$stand = $this->spawnStand();
		$stand->getArmorInventory()->setHelmet(VanillaBlocks::CARVED_PUMPKIN()->asItem());
		self::assertSame(ArmorInventory::SLOT_HEAD, $stand->getSlotAtHeight(1.8));
	}

	public function testRedstoneSignalInItsBlockSetsThePose() : void{
		$this->floor(-1, -1, 8, 1);
		//wire from a redstone block: strength 15 at x=1, 12 at x=4
		for($x = 1; $x <= 6; ++$x){
			$this->set($x, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		}
		$stand = new ArmorStand(Location::fromObject(new Vector3(4.5, 1, 0.5), $this->world));
		$stand->spawnToAll();
		$near = new ArmorStand(Location::fromObject(new Vector3(1.5, 1, 0.5), $this->world));
		$near->spawnToAll();
		$this->runTicks(2);
		self::assertSame(0, $stand->getPose());

		$this->set(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(4);
		self::assertSame(12, $this->wirePower(4, 1, 0));
		self::assertSame(11, $stand->getPose(), "signal 12 -> pose 11");
		self::assertSame(ArmorStand::MAX_POSE, $near->getPose(), "signal 15 -> pose 12");

		$this->set(0, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(4);
		self::assertSame(0, $this->wirePower(4, 1, 0));
		self::assertSame(11, $stand->getPose(), "losing the signal keeps the pose");
	}

	public function testBreaksAndDropsEverything() : void{
		$stand = $this->spawnStand();
		$stand->getArmorInventory()->setChestplate(VanillaItems::GOLDEN_CHESTPLATE());
		$stand->setMainHandItem(VanillaItems::STICK());
		$this->runTicks(1);
		$stand->attack(new EntityDamageEvent($stand, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10));
		self::assertFalse($stand->isAlive());
		$this->runTicks(2);

		$items = array_filter($this->world->getEntities(), fn($e) => $e instanceof ItemEntity);
		self::assertCount(3, $items);
	}
}
