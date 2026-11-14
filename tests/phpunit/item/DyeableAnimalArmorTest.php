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

namespace pocketmine\item;

use pmmp\encoding\ByteBufferReader;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\WaterCauldron;
use pocketmine\color\Color;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\inventory\HorseInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraData;
use pocketmine\player\Player;
use pocketmine\utils\Binary;
use Ramsey\Uuid\Uuid;

/**
 * Leather horse armor and wolf armor dyed in a cauldron and washed (B3-15, I1-09), with the colour saved like leather
 * armour's and shown on the horse.
 */
final class DyeableAnimalArmorTest extends MobTestCase{

	public function testColourRoundTripsLikeLeatherArmour() : void{
		foreach([VanillaItems::LEATHER_HORSE_ARMOR(), VanillaItems::WOLF_ARMOR()] as $item){
			$item->setCustomColor(new Color(0x12, 0x34, 0x56));
			$tag = $item->getNamedTag()->getTag(Armor::TAG_CUSTOM_COLOR);
			self::assertInstanceOf(IntTag::class, $tag);
			self::assertSame(Binary::signInt(0xff123456), $tag->getValue());

			$copy = Item::nbtDeserialize($item->nbtSerialize());
			self::assertInstanceOf(DyeableItem::class, $copy);
			self::assertSame(0xff123456, $copy->getCustomColor()?->toARGB());
			self::assertTrue($copy->equals($item));

			$copy->clearCustomColor();
			self::assertNull($copy->getNamedTag()->getTag(Armor::TAG_CUSTOM_COLOR));
		}
	}

	public function testDyeAndWashInCauldron() : void{
		foreach([VanillaItems::LEATHER_HORSE_ARMOR(), VanillaItems::WOLF_ARMOR()] as $item){
			$this->world->setBlockAt(0, 1, 0, VanillaBlocks::WATER_CAULDRON()->setFillLevel(WaterCauldron::MAX_FILL_LEVEL));
			$cauldron = $this->world->getBlockAt(0, 1, 0);
			self::assertInstanceOf(WaterCauldron::class, $cauldron);
			$cauldron->onInteract(VanillaItems::DYE()->setColor(DyeColor::RED), 1, new Vector3(0.5, 1, 0.5));

			$cauldron = $this->world->getBlockAt(0, 1, 0);
			self::assertInstanceOf(WaterCauldron::class, $cauldron);
			self::assertNotNull($cauldron->getCustomWaterColor());
			$cauldron->onInteract($item, 1, new Vector3(0.5, 1, 0.5));
			self::assertSame(DyeColor::RED->getRgbValue()->toARGB(), $item->getCustomColor()?->toARGB(), "dyed with the water's colour");
			$cauldron = $this->world->getBlockAt(0, 1, 0);
			self::assertInstanceOf(WaterCauldron::class, $cauldron);
			self::assertSame(WaterCauldron::MAX_FILL_LEVEL - WaterCauldron::DYE_ARMOR_USE_AMOUNT, $cauldron->getFillLevel());

			//plain water washes the colour off
			$this->world->setBlockAt(0, 1, 0, VanillaBlocks::WATER_CAULDRON()->setFillLevel(WaterCauldron::MAX_FILL_LEVEL));
			$cauldron = $this->world->getBlockAt(0, 1, 0);
			self::assertInstanceOf(WaterCauldron::class, $cauldron);
			$cauldron->onInteract($item, 1, new Vector3(0.5, 1, 0.5));
			self::assertNull($item->getCustomColor());
			$cauldron = $this->world->getBlockAt(0, 1, 0);
			self::assertInstanceOf(WaterCauldron::class, $cauldron);
			self::assertSame(WaterCauldron::MAX_FILL_LEVEL - WaterCauldron::CLEAN_ARMOR_USE_AMOUNT, $cauldron->getFillLevel());
		}
	}

	public function testHorseShowsDyedArmour() : void{
		$horse = new Horse(new Location(0.5, 1, 0.5, $this->world, 0, 0));
		$owner = $this->createMock(Player::class);
		$owner->method("getUniqueId")->willReturn(Uuid::uuid4());
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($owner, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($owner, $this->createMock(\Logger::class));
		$horse->setOwner($owner);
		$armor = VanillaItems::LEATHER_HORSE_ARMOR()->setCustomColor(DyeColor::BLUE->getRgbValue());
		$horse->getInventory()->setItem(HorseInventory::SLOT_ARMOR, $armor);

		//the body slot of MobArmorEquipmentPacket carries the item with its colour tag
		$net = TypeConverter::getInstance()->coreItemStackToNet($horse->getBodyItem());
		$nbt = ItemStackExtraData::read(new ByteBufferReader($net->getRawExtraData()))->getNbt();
		self::assertNotNull($nbt);
		self::assertSame(Binary::signInt(DyeColor::BLUE->getRgbValue()->toARGB()), $nbt->getInt(Armor::TAG_CUSTOM_COLOR));
		$horse->close();
	}
}
