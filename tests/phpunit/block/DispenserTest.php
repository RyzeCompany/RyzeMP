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

use PHPUnit\Framework\TestCase;
use pocketmine\block\inventory\DispenserInventory;
use pocketmine\block\tile\Dispenser as DispenserTile;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\BlockTranslator;
use pocketmine\network\mcpe\InventoryManager;
use pocketmine\network\mcpe\handler\ItemStackContainerIdTranslator;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

final class DispenserTest extends TestCase{

	public function testAllStatesRoundTripAndHaveNetworkMappings() : void{
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		$translators = [
			BlockTranslator::loadFromProtocolId(ProtocolInfo::CURRENT_PROTOCOL),
			BlockTranslator::loadFromProtocolId(ProtocolInfo::PROTOCOL_1_26_51)
		];
		$stateIds = [];
		foreach(Facing::ALL as $facing){
			foreach([false, true] as $triggered){
				$block = VanillaBlocks::DISPENSER()->setFacing($facing)->setTriggered($triggered);
				$stateIds[$block->getStateId()] = true;
				$data = $serializer->serializeBlock($block);
				self::assertSame(BlockTypeNames::DISPENSER, $data->getName());
				self::assertSame($block->getStateId(), $deserializer->deserializeBlock($data)->getStateId());
				foreach($translators as $translator){
					$networkData = $translator->internalIdToNetworkStateData($block->getStateId());
					self::assertSame(BlockTypeNames::DISPENSER, $networkData->getName());
					self::assertSame($block->getStateId(), $deserializer->deserializeBlock($networkData)->getStateId());
				}
			}
		}
		self::assertCount(12, $stateIds);
	}

	public function testInvalidFacingIsRejected() : void{
		$this->expectException(\InvalidArgumentException::class);
		VanillaBlocks::DISPENSER()->setFacing(6);
	}

	public function testAvailableAsItemAndInCreative() : void{
		$item = StringToItemParser::getInstance()->parse("dispenser");
		self::assertNotNull($item);
		self::assertSame(BlockTypeIds::DISPENSER, $item->getBlock()->getTypeId());
		self::assertTrue(CreativeInventory::getInstance()->contains($item));
		self::assertSame(DispenserTile::class, VanillaBlocks::DISPENSER()->getIdInfo()->getTileClass());
	}

	public function testNativeWindowAndSlots() : void{
		$inventory = new DispenserInventory(new Position(10, 64, -20, null));
		self::assertSame(9, $inventory->getSize());
		$open = new \ReflectionMethod(InventoryManager::class, "createContainerOpen");
		$packets = $open->invoke(null, 5, $inventory);
		self::assertIsArray($packets);
		self::assertCount(1, $packets);
		$packet = $packets[0];
		self::assertInstanceOf(ContainerOpenPacket::class, $packet);
		self::assertSame(WindowTypes::DISPENSER, $packet->windowType);
		self::assertSame(5, $packet->windowId);
		for($slot = 0; $slot < 9; ++$slot){
			self::assertSame([5, $slot], ItemStackContainerIdTranslator::translate(ContainerUIIds::LEVEL_ENTITY, 5, $slot));
		}
	}

	public function testInventoryAndNameSurviveTileReload() : void{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->expects(self::never())->method("dropItem");
		$tile = new DispenserTile($world, new Vector3(10, 64, -20));
		$first = VanillaItems::ARROW()->setCount(32);
		$last = VanillaItems::DIAMOND()->setCount(7)->setCustomName("Saved item");
		$tile->getInventory()->setItem(0, $first);
		$tile->getInventory()->setItem(8, $last);
		$tile->setName("Supplies");
		$saved = $tile->saveNBT();
		self::assertSame("Dispenser", $saved->getString(Tile::TAG_ID));
		$tile->close();
		foreach(["Dispenser", "minecraft:dispenser"] as $saveId){
			$saved->setString(Tile::TAG_ID, $saveId);
			$loaded = TileFactory::getInstance()->createFromData($world, $saved);
			self::assertInstanceOf(DispenserTile::class, $loaded);
			self::assertSame("Supplies", $loaded->getName());
			self::assertSame($loaded->getInventory(), $loaded->getRealInventory());
			self::assertCount(2, $loaded->getInventory()->getContents());
			self::assertTrue($first->equalsExact($loaded->getInventory()->getItem(0)));
			self::assertTrue($last->equalsExact($loaded->getInventory()->getItem(8)));
			$loaded->close();
		}
	}

	public function testDestroyDropsContentsAndClearsInventory() : void{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$item = VanillaItems::ARROW()->setCount(32);
		$world->expects(self::once())->method("dropItem")->with(
			self::callback(static fn(Vector3 $pos) : bool => $pos->equals(new Vector3(10.5, 64.5, -19.5))),
			self::callback(static fn(Item $dropped) : bool => $dropped->equalsExact($item))
		);
		$tile = new DispenserTile($world, new Vector3(10, 64, -20));
		$tile->getInventory()->setItem(8, $item);
		$tile->onBlockDestroyed();
		self::assertTrue($tile->isClosed());
		self::assertSame([], $tile->getInventory()->getContents());
	}
}
