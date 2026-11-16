<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\UIInventorySlotOffset;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class InventorySlotContainerNameTest extends TestCase{
	public function testCursorUpdatesEncodeForEveryAcceptedProtocol() : void{
		$packets = $this->sendSlot(ContainerIds::UI, UIInventorySlotOffset::CURSOR);
		self::assertCount(2, $packets);
		foreach($packets as $packet){
			self::assertSame(ContainerUIIds::CURSOR, $packet->containerName?->getContainerId());
			foreach(ClientVersionSupport::getVersions() as $protocolId => $version){
				$packet->encode(new ByteBufferWriter(), $protocolId, $version);
			}
		}
	}

	public function testOtherUiSlotsUseTheirOwnContainer() : void{
		foreach([
			1 => ContainerUIIds::ANVIL_INPUT,
			2 => ContainerUIIds::ANVIL_MATERIAL,
			27 => ContainerUIIds::BEACON_PAYMENT,
			28 => ContainerUIIds::CRAFTING_INPUT,
			53 => ContainerUIIds::SMITHING_TABLE_TEMPLATE
		] as $slot => $containerId){
			$packet = $this->sendSlot(ContainerIds::UI, $slot)[1];
			self::assertSame($containerId, $packet->containerName?->getContainerId());
			$packet->encode(new ByteBufferWriter(), 844, "1.21.114");
		}
	}

	public function testRegularWindowUsesDefaultContainer() : void{
		$packet = $this->sendSlot(ContainerIds::INVENTORY, 0)[1];
		self::assertSame(0, $packet->containerName?->getContainerId());
		$packet->encode(new ByteBufferWriter(), 844, "1.21.114");
	}

	/** @return list<InventorySlotPacket> */
	private function sendSlot(int $windowId, int $slot) : array{
		$packets = [];
		$session = $this->createMock(NetworkSession::class);
		$session->method("sendDataPacket")->willReturnCallback(
			static function(ClientboundPacket $packet, bool $immediate = false) use (&$packets) : bool{
				self::assertInstanceOf(InventorySlotPacket::class, $packet);
				$packets[] = $packet;
				return true;
			}
		);
		$manager = (new ReflectionClass(InventoryManager::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(InventoryManager::class, "session"))->setValue($manager, $session);
		(new ReflectionMethod(InventoryManager::class, "sendInventorySlotPackets"))->invoke(
			$manager, $windowId, $slot, new ItemStackWrapper(1, ItemStack::null())
		);
		return $packets;
	}
}
