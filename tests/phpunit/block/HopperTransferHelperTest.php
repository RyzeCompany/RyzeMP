<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

use PHPUnit\Framework\TestCase;
use pocketmine\block\Hopper;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\inventory\HopperTransferItemEvent;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\VanillaItems;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginManager;
use pocketmine\Server;

final class HopperTransferHelperTest extends TestCase{
    private Hopper $hopper;
    private PluginManager $pluginManager;
    private Plugin $plugin;

    protected function setUp() : void{
        HandlerListManager::global()->unregisterAll();
        $this->hopper = $this->createStub(Hopper::class);
        $this->plugin = $this->createStub(Plugin::class);
        $this->plugin->method("isEnabled")->willReturn(true);
        $this->pluginManager = new PluginManager($this->createMock(Server::class), null);
    }

    protected function tearDown() : void{
        HandlerListManager::global()->unregisterAll();
    }

    public function testMovesExactlyOneItem() : void{
        $source = new SimpleInventory(1);
        $dest = new SimpleInventory(1);
        $source->setItem(0, VanillaItems::APPLE()->setCount(5));
        self::assertTrue(HopperTransferHelper::transfer($this->hopper, $source, $dest));
        self::assertSame(4, $source->getItem(0)->getCount());
        self::assertSame(1, $dest->getItem(0)->getCount());
        self::assertFalse(HopperTransferHelper::transfer($this->hopper, $source, $source));
    }

    public function testFullDestinationCannotConsumeSource() : void{
        $source = new SimpleInventory(1);
        $dest = new SimpleInventory(1);
        $source->setItem(0, VanillaItems::APPLE()->setCount(3));
        $dest->setItem(0, VanillaItems::APPLE()->setCount(64));
        self::assertFalse(HopperTransferHelper::transfer($this->hopper, $source, $dest));
        self::assertSame(3, $source->getItem(0)->getCount());
        self::assertSame(64, $dest->getItem(0)->getCount());
    }

    public function testCancellationDoesNotMoveAnyItem() : void{
        $this->listen(static function(HopperTransferItemEvent $ev) : void{ $ev->cancel(); });
        $source = new SimpleInventory(1);
        $dest = new SimpleInventory(1);
        $source->setItem(0, VanillaItems::APPLE()->setCount(4));
        self::assertFalse(HopperTransferHelper::transfer($this->hopper, $source, $dest));
        self::assertSame(4, $source->getItem(0)->getCount());
        self::assertTrue($dest->isSlotEmpty(0));
    }

    public function testPluginMayRedirectDestinationSlot() : void{
        $this->listen(static function(HopperTransferItemEvent $ev) : void{
            $ev->setDestinationSlot(1);
        });
        $source = new SimpleInventory(1);
        $dest = new SimpleInventory(2);
        $source->setItem(0, VanillaItems::APPLE()->setCount(1));
        self::assertTrue(HopperTransferHelper::transfer($this->hopper, $source, $dest));
        self::assertTrue($source->isSlotEmpty(0));
        self::assertTrue($dest->isSlotEmpty(0));
        self::assertSame(1, $dest->getItem(1)->getCount());
    }

    public function testInvalidRedirectCannotLoseItems() : void{
        $this->listen(static function(HopperTransferItemEvent $ev) : void{
            $ev->setDestinationSlot(99);
        });
        $source = new SimpleInventory(1);
        $dest = new SimpleInventory(1);
        $source->setItem(0, VanillaItems::APPLE()->setCount(2));
        self::assertFalse(HopperTransferHelper::transfer($this->hopper, $source, $dest));
        self::assertSame(2, $source->getItem(0)->getCount());
        self::assertTrue($dest->isSlotEmpty(0));
    }

    public function testReentrantTransferIsRejected() : void{
        $source = new SimpleInventory(1);
        $dest = new SimpleInventory(1);
        $source->setItem(0, VanillaItems::APPLE()->setCount(3));
        $reentered = null;
        $this->listen(function(HopperTransferItemEvent $ev) use ($source, $dest, &$reentered) : void{
            $reentered = HopperTransferHelper::transfer($this->hopper, $source, $dest);
        });
        self::assertTrue(HopperTransferHelper::transfer($this->hopper, $source, $dest));
        self::assertFalse($reentered);
        self::assertSame(2, $source->getItem(0)->getCount());
        self::assertSame(1, $dest->getItem(0)->getCount());
    }

    public function testMutationByEventPreventsTransfer() : void{
        $source = new SimpleInventory(1);
        $dest = new SimpleInventory(1);
        $source->setItem(0, VanillaItems::APPLE()->setCount(3));
        $this->listen(static function(HopperTransferItemEvent $ev) use ($source) : void{
            $source->clear(0);
        });
        self::assertFalse(HopperTransferHelper::transfer($this->hopper, $source, $dest));
        self::assertTrue($dest->isSlotEmpty(0));
    }

    private function listen(\Closure $listener) : void{
        $this->pluginManager->registerEvent(
            HopperTransferItemEvent::class, $listener, EventPriority::NORMAL, $this->plugin
        );
    }
}
