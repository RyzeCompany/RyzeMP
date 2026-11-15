<?php

/*
 * This file is part of RyzeMP.
 *
 * RyzeMP is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License, version 3
 * or (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Container;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\inventory\HopperTransferItemEvent;
use pocketmine\event\RegisteredListener;
use pocketmine\inventory\Inventory;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\plugin\Plugin;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\redstone\RedstoneTestCase;

final class HopperTransferItemEventTest extends RedstoneTestCase{

	private function inventoryAt(int $x, int $y, int $z) : Inventory{
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(Container::class, $tile);
		return $tile->getInventory();
	}

	/**
	 * @param \Closure(HopperTransferItemEvent): void $callback
	 * @param \Closure(): void $body
	 */
	private function withHandler(\Closure $callback, \Closure $body) : void{
		$listener = new RegisteredListener(
			$callback,
			EventPriority::NORMAL,
			$this->createConfiguredMock(Plugin::class, ["isEnabled" => true]),
			false,
			new TimingsHandler("hopper-transfer-test")
		);
		$list = HandlerListManager::global()->getListFor(HopperTransferItemEvent::class);
		$list->register($listener);
		try{
			self::assertTrue(HopperTransferItemEvent::hasHandlers());
			$body();
		}finally{
			$list->unregister($listener);
		}
	}

	public function testCancelledPushLeavesBothInventoriesUnchanged() : void{
		$this->floor(-1, -1, 1, 1);
		$this->set(0, 1, 0, VanillaBlocks::CHEST());
		$this->set(0, 2, 0, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
		$this->runTicks(2);
		$source = $this->inventoryAt(0, 2, 0);
		$target = $this->inventoryAt(0, 1, 0);
		$events = 0;
		$this->withHandler(function(HopperTransferItemEvent $event) use ($source, $target, &$events) : void{
			++$events;
			self::assertSame($source, $event->getSourceInventory());
			self::assertSame($target, $event->getDestinationInventory());
			self::assertSame(0, $event->getSourceSlot());
			self::assertSame(0, $event->getDestinationSlot());
			self::assertSame(1, $event->getItem()->getCount());
			self::assertTrue($target->getItem(0)->isNull(), "destination must not be changed before the event");
			$event->cancel();
		}, function() use ($source, $target) : void{
			$source->setItem(0, VanillaItems::DIAMOND()->setCount(2));
			$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 2);
			self::assertSame(2, $source->getItem(0)->getCount());
			self::assertTrue($target->getItem(0)->isNull());
		});
		self::assertGreaterThan(0, $events);
		$hopper = $this->get(0, 2, 0);
		self::assertInstanceOf(Hopper::class, $hopper);
		$hopper->wakeUp();
		$this->runTicks(2);
		self::assertSame(1, $target->getItem(0)->getCount());
		self::assertSame(1, $source->getItem(0)->getCount());
	}

	public function testCancelledPullLeavesBothInventoriesUnchanged() : void{
		$this->floor(-1, -1, 1, 1);
		$this->set(0, 2, 0, VanillaBlocks::CHEST());
		$this->set(0, 1, 0, VanillaBlocks::HOPPER()->setFacing(Facing::NORTH));
		$this->runTicks(2);
		$source = $this->inventoryAt(0, 2, 0);
		$target = $this->inventoryAt(0, 1, 0);
		$events = 0;
		$this->withHandler(function(HopperTransferItemEvent $event) use ($source, $target, &$events) : void{
			++$events;
			self::assertSame($source, $event->getSourceInventory());
			self::assertSame($target, $event->getDestinationInventory());
			self::assertSame(0, $event->getSourceSlot());
			self::assertSame(0, $event->getDestinationSlot());
			$event->cancel();
		}, function() use ($source, $target) : void{
			$source->setItem(0, VanillaItems::DIAMOND()->setCount(2));
			$this->runTicks(ItemTransfer::HOPPER_COOLDOWN_TICKS * 2);
			self::assertSame(2, $source->getItem(0)->getCount());
			self::assertTrue($target->getItem(0)->isNull());
		});
		self::assertGreaterThan(0, $events);
		$hopper = $this->get(0, 1, 0);
		self::assertInstanceOf(Hopper::class, $hopper);
		$hopper->wakeUp();
		$this->runTicks(2);
		self::assertSame(1, $target->getItem(0)->getCount());
		self::assertSame(1, $source->getItem(0)->getCount());
	}

	public function testEventExposesDefensiveItemCopy() : void{
		$block = VanillaBlocks::HOPPER();
		$source = new \pocketmine\inventory\SimpleInventory(5);
		$target = new \pocketmine\inventory\SimpleInventory(27);
		$event = new HopperTransferItemEvent($block, $source, 3, $target, 7, VanillaItems::DIAMOND()->setCount(2));
		self::assertSame($block, $event->getHopper());
		self::assertSame(3, $event->getSourceSlot());
		self::assertSame(7, $event->getDestinationSlot());
		$event->getItem()->setCount(99);
		self::assertSame(2, $event->getItem()->getCount());
	}
}
