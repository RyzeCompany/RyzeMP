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

namespace pocketmine\entity\projectile;

use pocketmine\block\Dispenser;
use pocketmine\block\tile\Container;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\item\ItemTypeDeserializeException;
use pocketmine\data\bedrock\item\ItemTypeNames;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\item\PotionType;
use pocketmine\item\TippedArrow;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\redstone\RedstoneTestCase;
use function array_filter;
use function array_values;

class TippedArrowTest extends RedstoneTestCase{

	public function testArrowEffectsLastAnEighth() : void{
		$effects = TippedArrow::getArrowEffects(PotionType::SLOWNESS);
		self::assertCount(1, $effects);
		self::assertSame(VanillaEffects::SLOWNESS(), $effects[0]->getType());
		self::assertSame(1800 / 8, $effects[0]->getDuration());

		$turtleMaster = TippedArrow::getArrowEffects(PotionType::STRONG_TURTLE_MASTER);
		self::assertCount(2, $turtleMaster);
		self::assertSame(400 / 8, $turtleMaster[0]->getDuration());

		//instant effects are applied as they are
		$harming = TippedArrow::getArrowEffects(PotionType::HARMING);
		self::assertSame(VanillaEffects::INSTANT_DAMAGE(), $harming[0]->getType());
	}

	public function testItemRoundTrip() : void{
		$serializer = GlobalItemDataHandlers::getSerializer();
		$deserializer = GlobalItemDataHandlers::getDeserializer();

		$arrow = $serializer->serializeType(VanillaItems::ARROW());
		self::assertSame(ItemTypeNames::ARROW, $arrow->getName());
		self::assertSame(0, $arrow->getMeta());
		self::assertTrue($deserializer->deserializeType(new SavedItemData(ItemTypeNames::ARROW))->equals(VanillaItems::ARROW()));

		foreach(PotionType::cases() as $type){
			$tipped = VanillaItems::TIPPED_ARROW()->setType($type);
			$data = $serializer->serializeType($tipped);
			self::assertSame(ItemTypeNames::ARROW, $data->getName());
			self::assertGreaterThan(0, $data->getMeta());
			self::assertTrue($deserializer->deserializeType($data)->equals($tipped), $type->name);
		}
		//vanilla: poison is potion 25, so the arrow of poison has meta 26
		self::assertSame(26, $serializer->serializeType(VanillaItems::TIPPED_ARROW()->setType(PotionType::POISON))->getMeta());
	}

	public function testUnknownTippedArrowMetaIsRejected() : void{
		$this->expectException(ItemTypeDeserializeException::class);
		GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData(ItemTypeNames::ARROW, 200));
	}

	public function testArrowAppliesPotionOnHit() : void{
		$this->floor(-2, -4, 4, 4);
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 2.5), $this->world));
		$zombie->spawnToAll();

		$arrow = new Arrow(Location::fromObject(new Vector3(0.5, 2, -1.5), $this->world), null, false);
		$arrow->setPotionType(PotionType::SLOWNESS);
		$arrow->setMotion(new Vector3(0, 0, 1.5));
		$arrow->spawnToAll();

		$this->runTicks(20);

		$slowness = $zombie->getEffects()->get(VanillaEffects::SLOWNESS());
		self::assertNotNull($slowness, "the tipped arrow should have applied slowness");
		self::assertLessThanOrEqual(1800 / 8, $slowness->getDuration());
		self::assertGreaterThan(1800 / 8 - 20, $slowness->getDuration());
		self::assertLessThan($zombie->getMaxHealth(), $zombie->getHealth());
	}

	public function testNormalArrowAppliesNothing() : void{
		$this->floor(-2, -4, 4, 4);
		$zombie = new Zombie(Location::fromObject(new Vector3(0.5, 1, 2.5), $this->world));
		$zombie->spawnToAll();

		$arrow = new Arrow(Location::fromObject(new Vector3(0.5, 2, -1.5), $this->world), null, false);
		$arrow->setMotion(new Vector3(0, 0, 1.5));
		$arrow->spawnToAll();

		$this->runTicks(20);

		self::assertLessThan($zombie->getMaxHealth(), $zombie->getHealth());
		self::assertCount(0, $zombie->getEffects()->all());
	}

	public function testPotionIsSavedAndPickedUp() : void{
		$arrow = new Arrow(Location::fromObject(new Vector3(0.5, 2, 0.5), $this->world), null, false);
		$arrow->setPotionType(PotionType::LONG_POISON);
		self::assertTrue($arrow->getPickupItem()->equals(VanillaItems::TIPPED_ARROW()->setType(PotionType::LONG_POISON)));

		$nbt = $arrow->saveNBT();
		self::assertSame(27, $nbt->getByte("auxValue"));
		$loaded = new Arrow(Location::fromObject(new Vector3(0.5, 2, 0.5), $this->world), null, false, $nbt);
		self::assertSame(PotionType::LONG_POISON, $loaded->getPotionType());

		$normal = new Arrow(Location::fromObject(new Vector3(0.5, 2, 0.5), $this->world), null, false);
		self::assertTrue($normal->getPickupItem()->equals(VanillaItems::ARROW()));
		self::assertSame(0, $normal->saveNBT()->getByte("auxValue"));
	}

	public function testDispenserShootsTippedArrow() : void{
		$this->floor(-2, -2, 4, 2);
		$this->set(0, 1, 0, VanillaBlocks::DISPENSER()->setFacing(Facing::EAST));
		$this->runTicks(1);
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(Container::class, $tile);
		$tile->getInventory()->addItem(VanillaItems::TIPPED_ARROW()->setType(PotionType::WEAKNESS));

		$this->set(0, 1, 1, VanillaBlocks::REDSTONE());
		$this->runTicks(Dispenser::DISPENSE_DELAY_TICKS + 1);

		$arrows = array_values(array_filter($this->world->getEntities(), fn(Entity $e) => $e instanceof Arrow));
		self::assertCount(1, $arrows);
		self::assertSame(PotionType::WEAKNESS, $arrows[0]->getPotionType());
	}
}
