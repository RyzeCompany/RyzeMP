<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

use pocketmine\block\Hopper;
use pocketmine\event\inventory\HopperTransferItemEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use Throwable;

/**
 * Performs synchronous, one-item container transfers. Inventories are locked
 * during a transfer to prevent event-listener reentrancy and accidental dupes.
 * The original source and target slots are checked again after the event.
 */
final class HopperTransferHelper{
    /** @var array<int, true> */
    private static array $busy = [];

    private function __construct(){}

    public static function transfer(Hopper $hopper, Inventory $source, Inventory $destination) : bool{
        if($source === $destination){
            return false;
        }
        $sourceId = spl_object_id($source);
        $destinationId = spl_object_id($destination);
        if(isset(self::$busy[$sourceId]) || isset(self::$busy[$destinationId])){
            return false;
        }

        self::$busy[$sourceId] = self::$busy[$destinationId] = true;
        try{
            for($sourceSlot = 0, $size = $source->getSize(); $sourceSlot < $size; ++$sourceSlot){
                $originalSource = $source->getItem($sourceSlot);
                if($originalSource->isNull() || $originalSource->getCount() < 1){
                    continue;
                }
                $candidateSlot = self::findSlot($destination, $originalSource);
                if($candidateSlot === null){
                    continue;
                }

                $transferItem = clone $originalSource;
                $transferItem->setCount(1);
                $event = new HopperTransferItemEvent(
                    $hopper, $source, $sourceSlot, $destination, $candidateSlot, $transferItem
                );
                $event->call();
                if($event->isCancelled()){
                    continue;
                }

                $destinationSlot = $event->getDestinationSlot();
                if(!$destination->slotExists($destinationSlot)){
                    continue;
                }
                $currentSource = $source->getItem($sourceSlot);
                if($currentSource->isNull() || $currentSource->getCount() < 1 || !$currentSource->canStackWith($originalSource)){
                    continue;
                }
                $currentDestination = $destination->getItem($destinationSlot);
                $limit = min($currentSource->getMaxStackSize(), $destination->getMaxStackSize());
                if(!$currentDestination->isNull()){
                    if(!$currentDestination->canStackWith($currentSource)){
                        continue;
                    }
                    $limit = min($limit, $currentDestination->getMaxStackSize());
                    if($currentDestination->getCount() >= $limit){
                        continue;
                    }
                }

                $nextDestination = clone $currentSource;
                $nextDestination->setCount(1);
                if(!$currentDestination->isNull()){
                    $nextDestination = clone $currentDestination;
                    $nextDestination->setCount($currentDestination->getCount() + 1);
                }
                $nextSource = clone $currentSource;
                $nextSource->setCount($nextSource->getCount() - 1);

                // Both slot indices and stacks have been checked before changing either.
                // Best-effort rollback protects against listeners throwing during setItem().
                $destinationWritten = false;
                try{
                    $destination->setItem($destinationSlot, $nextDestination);
                    $destinationWritten = true;
                    $source->setItem($sourceSlot, $nextSource);
                }catch(Throwable $e){
                    if($destinationWritten){
                        try{
                            $destination->setItem($destinationSlot, $currentDestination);
                        }catch(Throwable){
                            // Preserve the original exception; external listeners may be broken.
                        }
                    }
                    throw $e;
                }
                return true;
            }
            return false;
        }finally{
            unset(self::$busy[$sourceId], self::$busy[$destinationId]);
        }
    }

    private static function findSlot(Inventory $destination, Item $item) : ?int{
        $firstEmpty = null;
        $max = min($item->getMaxStackSize(), $destination->getMaxStackSize());
        for($slot = 0, $size = $destination->getSize(); $slot < $size; ++$slot){
            $present = $destination->getItem($slot);
            if($present->isNull()){
                $firstEmpty ??= $slot;
            }elseif($present->canStackWith($item) && $present->getCount() < min($max, $present->getMaxStackSize())){
                return $slot;
            }
        }
        return $firstEmpty;
    }
}
