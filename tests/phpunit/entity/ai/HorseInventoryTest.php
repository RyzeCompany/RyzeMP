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

namespace pocketmine\entity\ai;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animal\AbstractHorse;
use pocketmine\entity\animal\Donkey;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\Mule;
use pocketmine\entity\animal\SkeletonHorse;
use pocketmine\entity\animal\TraderLlama;
use pocketmine\entity\animal\ZombieHorse;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\inventory\HorseInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\TransactionValidationException;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_map;
use function array_values;

final class HorseInventoryTest extends MobTestCase{

	private function loc(float $x, float $y, float $z) : Location{
		return new Location($x, $y, $z, $this->world, 0, 0);
	}

	/**
	 * A mocked player next to the given position, holding the item.
	 *
	 * @phpstan-return Player&MockObject
	 */
	private function player(Item $held, Vector3 $at, bool $sneaking = false) : Player{
		$player = $this->createMock(Player::class);
		$inventory = new PlayerInventory($player);
		$inventory->setItemInHand($held);
		$player->method("getInventory")->willReturn($inventory);
		$player->method("hasFiniteResources")->willReturn(true);
		$player->method("getUniqueId")->willReturn(Uuid::uuid4());
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getPosition")->willReturn(Position::fromObject($at, $this->world));
		$player->method("isSneaking")->willReturn($sneaking);
		$player->method("isConnected")->willReturn(false);
		$player->method("isAlive")->willReturn(true);
		$player->method("getName")->willReturn("Tester");
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		return $player;
	}

	private static function flag(Entity $entity, int $flag) : bool{
		$flags = $entity->getNetworkProperties()->getAll()[$flag >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS] ?? null;
		self::assertInstanceOf(LongMetadataProperty::class, $flags);
		return ($flags->getValue() & (1 << ($flag % 64))) !== 0;
	}

	private function accepts(HorseInventory $inventory, int $slot, Item $item) : bool{
		try{
			(new SlotChangeAction($inventory, $slot, $inventory->getItem($slot), $item))->validate($this->player(VanillaItems::AIR(), Vector3::zero()));
			return true;
		}catch(TransactionValidationException){
			return false;
		}
	}

	public function testInventorySizes() : void{
		$owner = $this->player(VanillaItems::AIR(), new Vector3(0, 1, 0));

		$horse = new Horse($this->loc(0.5, 1, 0.5));
		self::assertSame(2, $horse->getInventory()->getSize(), "saddle and armor");
		self::assertSame(2, (new ZombieHorse($this->loc(2.5, 1, 0.5)))->getInventory()->getSize());
		self::assertSame(1, (new SkeletonHorse($this->loc(4.5, 1, 0.5)))->getInventory()->getSize(), "saddle only");
		$horse->setChested(true);
		self::assertFalse($horse->isChested(), "horses take no chest");

		foreach([new Donkey($this->loc(6.5, 1, 0.5)), new Mule($this->loc(8.5, 1, 0.5))] as $chestedKind){
			self::assertSame(1, $chestedKind->getInventory()->getSize());
			$chestedKind->setOwner($owner);
			$chestedKind->setChested(true);
			self::assertSame(1 + AbstractHorse::CHEST_SIZE, $chestedKind->getInventory()->getSize());
		}

		for($strength = 1; $strength <= 5; ++$strength){
			$llama = new Llama($this->loc(10.5 + $strength * 2, 1, 0.5), CompoundTag::create()->setInt(Llama::TAG_STRENGTH, $strength));
			self::assertSame(1, $llama->getInventory()->getSize(), "carpet only");
			$llama->setChested(true);
			self::assertSame(1 + 3 * $strength, $llama->getInventory()->getSize(), "3 chest slots per strength");
		}

		//a weaker llama drops what no longer fits
		$llama = new Llama($this->loc(0.5, 1, 6.5), CompoundTag::create()->setInt(Llama::TAG_STRENGTH, 5));
		$llama->setChested(true);
		$llama->getInventory()->setItem(15, VanillaItems::DIAMOND());
		$llama->getInventory()->setItem(2, VanillaItems::EMERALD());
		$llama->setStrength(2);
		self::assertSame(7, $llama->getInventory()->getSize());
		self::assertSame(ItemTypeIds::EMERALD, $llama->getInventory()->getItem(2)->getTypeId());
	}

	public function testSlotRules() : void{
		$horse = new Horse($this->loc(0.5, 1, 0.5));
		$inv = $horse->getInventory();
		self::assertTrue($horse->canHoldInSlot(HorseInventory::SLOT_EQUIP, VanillaItems::SADDLE()));
		self::assertFalse($horse->canHoldInSlot(HorseInventory::SLOT_EQUIP, VanillaItems::DIAMOND_HORSE_ARMOR()));
		self::assertTrue($horse->canHoldInSlot(HorseInventory::SLOT_ARMOR, VanillaItems::DIAMOND_HORSE_ARMOR()));
		self::assertTrue($horse->canHoldInSlot(HorseInventory::SLOT_ARMOR, VanillaItems::NETHERITE_HORSE_ARMOR()));
		self::assertFalse($horse->canHoldInSlot(HorseInventory::SLOT_ARMOR, VanillaItems::SADDLE()));
		self::assertFalse($horse->canHoldInSlot(HorseInventory::SLOT_ARMOR, VanillaItems::DIAMOND_CHESTPLATE()));
		self::assertTrue($this->accepts($inv, HorseInventory::SLOT_EQUIP, VanillaItems::SADDLE()));
		self::assertFalse($this->accepts($inv, HorseInventory::SLOT_EQUIP, VanillaBlocks::DIRT()->asItem()), "transactions are validated");

		$llama = new Llama($this->loc(4.5, 1, 0.5));
		$carpet = VanillaBlocks::CARPET()->setColor(DyeColor::RED)->asItem();
		self::assertTrue($llama->canHoldInSlot(HorseInventory::SLOT_EQUIP, $carpet));
		self::assertFalse($llama->canHoldInSlot(HorseInventory::SLOT_EQUIP, (clone $carpet)->setCount(2)), "one carpet");
		self::assertFalse($llama->canHoldInSlot(HorseInventory::SLOT_EQUIP, VanillaBlocks::MOSS_CARPET()->asItem()), "wool carpets only");
		self::assertFalse($llama->canHoldInSlot(HorseInventory::SLOT_EQUIP, VanillaItems::SADDLE()), "llamas take no saddle");

		$donkey = new Donkey($this->loc(8.5, 1, 0.5));
		$donkey->setOwner($this->player(VanillaItems::AIR(), new Vector3(8, 1, 0)));
		$donkey->setChested(true);
		self::assertTrue($donkey->canHoldInSlot(1, VanillaBlocks::DIRT()->asItem()->setCount(64)), "anything goes in the chest");
		self::assertFalse($donkey->canHoldInSlot(HorseInventory::SLOT_EQUIP, VanillaItems::DIAMOND_HORSE_ARMOR()), "donkeys wear no armor");
	}

	public function testSaddleAndArmorThroughTheInventory() : void{
		$horse = new Horse($this->loc(0.5, 1, 0.5));
		$owner = $this->player(VanillaItems::AIR(), new Vector3(0.5, 1, 1.5));
		$horse->setOwner($owner);
		self::assertFalse($horse->canBeSteeredBy($owner));

		$horse->getInventory()->setItem(HorseInventory::SLOT_EQUIP, VanillaItems::SADDLE());
		self::assertTrue($horse->isSaddled());
		self::assertTrue($horse->canBeSteeredBy($owner));
		$this->runTicks(1);
		self::assertTrue(self::flag($horse, EntityMetadataFlags::SADDLED));
		$horse->getInventory()->clear(HorseInventory::SLOT_EQUIP);
		self::assertFalse($horse->isSaddled());
		$this->runTicks(1);
		self::assertFalse(self::flag($horse, EntityMetadataFlags::SADDLED));
		$horse->setSaddled(true);
		self::assertSame(ItemTypeIds::SADDLE, $horse->getInventory()->getItem(HorseInventory::SLOT_EQUIP)->getTypeId());

		//armor protects: diamond horse armor gives 11 points (a 10 damage hit: 24% less with the vanilla armour formula)
		self::assertSame(0, $horse->getArmorPoints());
		$horse->setHorseArmor(VanillaItems::DIAMOND_HORSE_ARMOR());
		self::assertSame(11, $horse->getArmorPoints());
		self::assertSame(ItemTypeIds::DIAMOND_HORSE_ARMOR, $horse->getBodyItem()->getTypeId(), "shown in the body slot");
		$event = new EntityDamageEvent($horse, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 10);
		$horse->applyDamageModifiers($event);
		self::assertEqualsWithDelta(7.6, $event->getFinalDamage(), 0.001);
	}

	public function testUsingItemsOnTheAnimal() : void{
		$at = new Vector3(0.5, 1, 1.5);
		$donkey = new Donkey($this->loc(0.5, 1, 0.5));
		$chestItem = VanillaBlocks::CHEST()->asItem();

		$stranger = $this->player($chestItem, $at);
		self::assertSame("", $donkey->getInteractButtonText($stranger), "a wild one takes no chest");
		$donkey->onInteract($stranger, $at);
		self::assertFalse($donkey->isChested());

		$owner = $this->player((clone $chestItem)->setCount(2), $at);
		$donkey->setOwner($owner);
		self::assertSame("action.interact.attachchest", $donkey->getInteractButtonText($owner));
		self::assertTrue($donkey->onInteract($owner, $at));
		self::assertTrue($donkey->isChested());
		self::assertSame(1, $owner->getInventory()->getItemInHand()->getCount(), "the chest was used");
		$this->runTicks(1);
		self::assertTrue(self::flag($donkey, EntityMetadataFlags::CHESTED));
		$base = $donkey->getNetworkProperties()->getAll()[EntityMetadataProperties::CONTAINER_BASE_SIZE] ?? null;
		self::assertInstanceOf(IntMetadataProperty::class, $base);
		self::assertSame(16, $base->getValue());
		$type = $donkey->getNetworkProperties()->getAll()[EntityMetadataProperties::CONTAINER_TYPE] ?? null;
		self::assertInstanceOf(ByteMetadataProperty::class, $type);
		self::assertSame(WindowTypes::HORSE, $type->getValue());
		self::assertFalse($donkey->onInteract($owner, $at) && $donkey->getInventory()->getSize() !== 16, "only one chest");

		$horse = new Horse($this->loc(4.5, 1, 0.5));
		$rider = $this->player(VanillaItems::IRON_HORSE_ARMOR(), $at);
		$horse->setOwner($rider);
		self::assertSame("action.interact.equiphorsearmor", $horse->getInteractButtonText($rider));
		self::assertTrue($horse->onInteract($rider, $at));
		self::assertSame(ItemTypeIds::IRON_HORSE_ARMOR, $horse->getHorseArmor()->getTypeId());
		self::assertTrue($rider->getInventory()->getItemInHand()->isNull());

		$llama = new Llama($this->loc(8.5, 1, 0.5));
		$carpets = VanillaBlocks::CARPET()->setColor(DyeColor::BLUE)->asItem()->setCount(5);
		$llamaOwner = $this->player($carpets, new Vector3(8.5, 1, 1.5));
		$llama->setOwner($llamaOwner);
		self::assertSame("action.interact.equipcarpet", $llama->getInteractButtonText($llamaOwner));
		self::assertTrue($llama->onInteract($llamaOwner, new Vector3(8.5, 1, 1.5)));
		self::assertSame(1, $llama->getCarpet()->getCount());
		self::assertSame(4, $llamaOwner->getInventory()->getItemInHand()->getCount());
		self::assertTrue(Llama::isCarpet($llama->getBodyItem()));
	}

	public function testSneakingOpensTheInventory() : void{
		$horse = new Horse($this->loc(0.5, 1, 0.5));
		$at = new Vector3(0.5, 1, 1.5);
		$player = $this->player(VanillaItems::SADDLE(), $at, sneaking: true);
		$player->expects(self::once())->method("setCurrentWindow")->with($horse->getInventory())->willReturn(true);
		self::assertFalse($horse->canOpenInventory($player), "untamed");
		$horse->setOwner($player);
		self::assertSame("action.interact.opencontainer", $horse->getInteractButtonText($player));
		self::assertTrue($horse->onInteract($player, $at));
		self::assertFalse($horse->isSaddled(), "sneaking opens the screen instead of saddling");

		$far = $this->player(VanillaItems::AIR(), new Vector3(20, 1, 0.5), sneaking: true);
		self::assertFalse($horse->canOpenInventory($far), "too far");
	}

	public function testChestItemsAreSaved() : void{
		$donkey = new Donkey($this->loc(0.5, 1, 0.5));
		$donkey->setOwner($this->player(VanillaItems::AIR(), new Vector3(0, 1, 0)));
		$donkey->setChested(true);
		$donkey->setSaddled(true);
		$donkey->getInventory()->setItem(1, VanillaItems::DIAMOND()->setCount(7));
		$donkey->getInventory()->setItem(15, VanillaBlocks::DIRT()->asItem()->setCount(64));
		$nbt = $donkey->saveNBT();
		self::assertSame(1, $nbt->getByte(AbstractHorse::TAG_CHESTED));
		$items = $nbt->getListTag(AbstractHorse::TAG_CHEST_ITEMS);
		self::assertInstanceOf(ListTag::class, $items);
		self::assertCount(3, $items);

		$loaded = new Donkey($this->loc(4.5, 1, 0.5), $nbt);
		self::assertTrue($loaded->isChested());
		self::assertTrue($loaded->isSaddled());
		self::assertSame(16, $loaded->getInventory()->getSize());
		self::assertSame(7, $loaded->getInventory()->getItem(1)->getCount());
		self::assertSame(64, $loaded->getInventory()->getItem(15)->getCount());

		//saved before the inventory existed: only the Saddled byte
		$old = new Horse($this->loc(8.5, 1, 0.5), CompoundTag::create()->setByte(AbstractHorse::TAG_SADDLED, 1));
		self::assertTrue($old->isSaddled());

		$llama = new TraderLlama($this->loc(12.5, 1, 0.5), CompoundTag::create()->setInt(Llama::TAG_STRENGTH, 2));
		$llama->setOwner($this->player(VanillaItems::AIR(), new Vector3(12, 1, 0)));
		$llama->setChested(true);
		$llama->setCarpet(VanillaBlocks::CARPET()->setColor(DyeColor::LIME)->asItem());
		$llama->getInventory()->setItem(6, VanillaItems::EMERALD());
		$loadedLlama = new TraderLlama($this->loc(16.5, 1, 0.5), $llama->saveNBT());
		self::assertSame(7, $loadedLlama->getInventory()->getSize());
		self::assertTrue(Llama::isCarpet($loadedLlama->getCarpet()));
		self::assertSame(ItemTypeIds::EMERALD, $loadedLlama->getInventory()->getItem(6)->getTypeId());
	}

	public function testDropsOnDeath() : void{
		$mule = new Mule($this->loc(0.5, 1, 0.5));
		$mule->setOwner($this->player(VanillaItems::AIR(), new Vector3(0, 1, 0)));
		$mule->setChested(true);
		$mule->setSaddled(true);
		$mule->getInventory()->setItem(3, VanillaItems::GOLD_INGOT()->setCount(5));
		$drops = array_values(array_filter($mule->getDrops(), fn(Item $item) => $item->getTypeId() !== ItemTypeIds::LEATHER));
		$ids = array_map(fn(Item $item) => $item->getTypeId(), $drops);
		self::assertContains(ItemTypeIds::SADDLE, $ids);
		self::assertContains(ItemTypeIds::GOLD_INGOT, $ids);
		self::assertContains(VanillaBlocks::CHEST()->asItem()->getTypeId(), $ids, "the chest drops too");
		self::assertCount(3, $drops);

		$horse = new Horse($this->loc(4.5, 1, 0.5));
		$horse->setHorseArmor(VanillaItems::GOLDEN_HORSE_ARMOR());
		$ids = array_map(fn(Item $item) => $item->getTypeId(), $horse->getDrops());
		self::assertContains(ItemTypeIds::GOLDEN_HORSE_ARMOR, $ids);
	}

	public function testHorseScreenPackets() : void{
		$horse = new Horse($this->loc(0.5, 1, 0.5));
		$packet = InventoryManager::createHorseEquipPacket(5, $horse->getInventory());
		self::assertSame(5, $packet->windowId);
		self::assertSame(WindowTypes::HORSE, $packet->windowType);
		self::assertSame($horse->getId(), $packet->actorUniqueId);
		$slots = $packet->nbt->getRoot()->getListTag("slots");
		self::assertInstanceOf(ListTag::class, $slots);
		self::assertCount(2, $slots, "saddle and armor");

		$llama = new Llama($this->loc(4.5, 1, 0.5));
		$slots = InventoryManager::createHorseEquipPacket(6, $llama->getInventory())->nbt->getRoot()->getListTag("slots");
		self::assertInstanceOf(ListTag::class, $slots);
		self::assertCount(1, $slots, "carpet only");
	}
}
