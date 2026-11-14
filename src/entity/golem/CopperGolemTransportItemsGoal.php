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

namespace pocketmine\entity\golem;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\CopperChest;
use pocketmine\block\inventory\ChestInventory;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\entity\ai\goal\Goal;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\world\sound\ChestCloseSound;
use pocketmine\world\sound\ChestOpenSound;
use pocketmine\world\World;
use function count;
use function intdiv;
use function min;
use const PHP_FLOAT_MAX;

/**
 * Copper golems sort items (vanilla TransportItemsBetweenContainers with the CopperGolemAi settings): with an empty
 * hand the golem walks to the nearest copper chest within 32 blocks (8 vertically) and takes up to 16 items from its
 * first non-empty slot; holding items, it walks to the nearest chest or trapped chest that is empty or already holds
 * the same item, and puts them there. Each chest is opened for 60 ticks (actor property minecraft:chest_interaction:
 * take, take_fail, put, put_fail) before the items move. Chests already tried are skipped (at most 10, forgotten after
 * 5 minutes or when items moved); when nothing is left to try the golem rests for 7 seconds. The golem waits while a
 * player has the chest open.
 *
 * Simplifications: the half of a double chest is visited like a single chest, reachability uses the path finder only
 * (no line of sight check), and trapped chests are not powered while the golem has them open.
 */
class CopperGolemTransportItemsGoal extends Goal{
	public const INTERACTION_TICKS = 60;
	public const MAX_STACK = 16;
	private const HORIZONTAL_RANGE = 32;
	private const VERTICAL_RANGE = 8;
	private const MAX_VISITED = 10;
	private const MAX_UNREACHABLE = 50;
	private const VISITED_MEMORY_TICKS = 6000;
	private const IDLE_COOLDOWN = 140;
	/** Ticks to reach a chest before it counts as unreachable. */
	private const TRAVEL_TIMEOUT = 400;
	private const QUEUE_DISTANCE = 3.0;

	public const INTERACTION_NONE = "none";
	public const INTERACTION_TAKE = "take";
	public const INTERACTION_TAKE_FAIL = "take_fail";
	public const INTERACTION_PUT = "put";
	public const INTERACTION_PUT_FAIL = "put_fail";

	private ?Vector3 $target = null;
	private bool $interacting = false;
	private int $ticksAtTarget = 0;
	private int $travelTicks = 0;
	private int $cooldownUntil = 0;
	/** @phpstan-var array<int, int> block hash => expiry tick */
	private array $visited = [];
	/** @phpstan-var array<int, int> block hash => expiry tick */
	private array $unreachable = [];
	private bool $chestOpened = false;

	public function __construct(
		private CopperGolem $golem,
		private float $speed = 1.0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function canStart() : bool{
		return $this->golem->getAiTick() >= $this->cooldownUntil;
	}

	public function canContinue() : bool{
		return $this->canStart();
	}

	public function stop() : void{
		$this->releaseTarget();
	}

	private function isPickingUp() : bool{
		return $this->golem->getMainHandItem()->isNull();
	}

	/**
	 * Whether the golem wants the chest at this position: copper chests when its hand is empty, chests and trapped
	 * chests when it holds items.
	 */
	private function isWanted(World $world, Vector3 $pos) : bool{
		$block = $world->getBlock($pos);
		if($this->isPickingUp()){
			return $block instanceof CopperChest;
		}
		$id = $block->getTypeId();
		return $id === BlockTypeIds::CHEST || $id === BlockTypeIds::TRAPPED_CHEST;
	}

	private function getInventory(World $world, Vector3 $pos) : ?ChestInventory{
		$tile = $world->getTile($pos);
		return $tile instanceof TileChest ? $tile->getRealInventory() : null;
	}

	private function forgetExpired(int $now) : void{
		foreach($this->visited as $hash => $expiry){
			if($expiry <= $now){
				unset($this->visited[$hash]);
			}
		}
		foreach($this->unreachable as $hash => $expiry){
			if($expiry <= $now){
				unset($this->unreachable[$hash]);
			}
		}
	}

	private function findTarget(World $world) : ?Vector3{
		$loc = $this->golem->getLiveLocation();
		$h = self::HORIZONTAL_RANGE;
		$v = self::VERTICAL_RANGE;
		$area = new AxisAlignedBB($loc->getFloorX() - $h, $loc->getFloorY() - $v, $loc->getFloorZ() - $h, $loc->getFloorX() + $h + 1, $loc->getFloorY() + $v + 1, $loc->getFloorZ() + $h + 1);
		$chunkX = $loc->getFloorX() >> 4;
		$chunkZ = $loc->getFloorZ() >> 4;
		$chunkRange = intdiv($h, 16) + 1;
		$best = null;
		$bestDistance = PHP_FLOAT_MAX;
		for($cx = $chunkX - $chunkRange; $cx <= $chunkX + $chunkRange; ++$cx){
			for($cz = $chunkZ - $chunkRange; $cz <= $chunkZ + $chunkRange; ++$cz){
				$chunk = $world->getChunk($cx, $cz);
				if($chunk === null){
					continue;
				}
				foreach($chunk->getTiles() as $tile){
					if(!$tile instanceof TileChest){
						continue;
					}
					$pos = $tile->getPosition();
					if(!$area->isVectorInside($pos)){
						continue;
					}
					$distance = $pos->add(0.5, 0.5, 0.5)->distanceSquared($loc);
					if($distance >= $bestDistance){
						continue;
					}
					$hash = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
					if(isset($this->visited[$hash]) || isset($this->unreachable[$hash]) || !$this->isWanted($world, $pos)){
						continue;
					}
					$best = $pos->floor();
					$bestDistance = $distance;
				}
			}
		}
		return $best;
	}

	private function enterCooldown() : void{
		$this->releaseTarget();
		$this->cooldownUntil = $this->golem->getAiTick() + self::IDLE_COOLDOWN;
		$this->visited = [];
		$this->unreachable = [];
	}

	private function releaseTarget() : void{
		$this->closeChest();
		$this->target = null;
		$this->interacting = false;
		$this->ticksAtTarget = 0;
		$this->travelTicks = 0;
		$this->setInteraction(self::INTERACTION_NONE);
		$this->golem->getNavigation()->stop();
	}

	private function setInteraction(string $interaction) : void{
		$this->golem->setChestInteraction($interaction);
	}

	private function markUnreachable(Vector3 $pos) : void{
		$hash = World::blockHash($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		unset($this->visited[$hash]);
		$this->unreachable[$hash] = $this->golem->getAiTick() + self::VISITED_MEMORY_TICKS;
		if(count($this->unreachable) > self::MAX_UNREACHABLE){
			$this->enterCooldown();
		}else{
			$this->releaseTarget();
		}
	}

	/**
	 * Whether the golem's box, moved to its position, touches the chest's box grown by $distance horizontally and 0.5
	 * vertically (vanilla isWithinTargetDistance).
	 */
	private function isWithin(float $distance, Vector3 $target) : bool{
		$chestBox = new AxisAlignedBB($target->x + 1 / 16 - $distance, $target->y - 0.5, $target->z + 1 / 16 - $distance, $target->x + 15 / 16 + $distance, $target->y + 14 / 16 + 0.5, $target->z + 15 / 16 + $distance);
		return $chestBox->intersectsWith($this->golem->getBoundingBox());
	}

	public function tick() : void{
		$world = $this->golem->getWorld();
		$now = $this->golem->getAiTick();
		$this->forgetExpired($now);

		if($this->target !== null && (!$this->isWanted($world, $this->target) || $this->getInventory($world, $this->target) === null)){
			$this->releaseTarget();
		}
		if($this->target === null){
			$target = $this->findTarget($world);
			if($target === null){
				$this->enterCooldown();
				return;
			}
			$this->target = $target;
			$this->visited[World::blockHash($target->getFloorX(), $target->getFloorY(), $target->getFloorZ())] = $now + self::VISITED_MEMORY_TICKS;
			if(count($this->visited) > self::MAX_VISITED){
				$this->enterCooldown();
				return;
			}
		}
		$target = $this->target;
		$inventory = $this->getInventory($world, $target);
		if($inventory === null){
			return;
		}

		if(!$this->interacting){
			$playerViewing = $inventory->getViewerCount() > 0;
			if($playerViewing && $this->isWithin(self::QUEUE_DISTANCE, $target)){
				$this->golem->getNavigation()->stop();
				$this->golem->getLookControl()->setLookAt($target->x + 0.5, $target->y + 0.5, $target->z + 0.5);
				return;
			}
			if($this->isWithin(1.0, $target)){
				$this->startInteraction($inventory);
				return;
			}
			if(++$this->travelTicks > self::TRAVEL_TIMEOUT){
				$this->markUnreachable($target);
				return;
			}
			$navigation = $this->golem->getNavigation();
			if($navigation->isDone() && !$navigation->moveTo($target->x + 0.5, $target->y, $target->z + 0.5, $this->speed, 1.0)){
				$this->markUnreachable($target);
			}
			return;
		}

		if(!$this->isWithin(2.0, $target)){
			$this->interacting = false;
			$this->ticksAtTarget = 0;
			$this->closeChest();
			$this->setInteraction(self::INTERACTION_NONE);
			return;
		}
		$this->golem->getLookControl()->setLookAt($target->x + 0.5, $target->y + 0.5, $target->z + 0.5);
		if(++$this->ticksAtTarget < self::INTERACTION_TICKS){
			return;
		}
		if($this->isPickingUp()){
			$taken = self::takeItems($inventory);
			if($taken !== null){
				$this->golem->setMainHandItem($taken);
				$this->visited = [];
				$this->unreachable = [];
			}
		}elseif(self::acceptsItem($inventory, $this->golem->getMainHandItem())){
			$left = self::addItems($inventory, $this->golem->getMainHandItem());
			$this->golem->setMainHandItem($left);
			if($left->isNull()){
				$this->visited = [];
				$this->unreachable = [];
			}
		}
		$this->releaseTarget();
	}

	private function startInteraction(Inventory $inventory) : void{
		$this->interacting = true;
		$this->ticksAtTarget = 0;
		$this->golem->getNavigation()->stop();
		if($this->isPickingUp()){
			$this->setInteraction(self::isEmpty($inventory) ? self::INTERACTION_TAKE_FAIL : self::INTERACTION_TAKE);
		}else{
			$this->setInteraction(self::acceptsItem($inventory, $this->golem->getMainHandItem()) ? self::INTERACTION_PUT : self::INTERACTION_PUT_FAIL);
		}
		$this->openChest($inventory);
	}

	private function openChest(Inventory $inventory) : void{
		if($inventory instanceof ChestInventory && !$this->chestOpened && $inventory->getViewerCount() === 0 && $this->target !== null){
			$inventory->animateBlock(true);
			$this->golem->getWorld()->addSound($this->target->add(0.5, 0.5, 0.5), new ChestOpenSound());
			$this->chestOpened = true;
		}
	}

	private function closeChest() : void{
		if(!$this->chestOpened || $this->target === null){
			$this->chestOpened = false;
			return;
		}
		$this->chestOpened = false;
		$inventory = $this->getInventory($this->golem->getWorld(), $this->target);
		if($inventory !== null && $inventory->getViewerCount() === 0){
			$inventory->animateBlock(false);
			$this->golem->getWorld()->addSound($this->target->add(0.5, 0.5, 0.5), new ChestCloseSound());
		}
	}

	public static function isEmpty(Inventory $inventory) : bool{
		foreach($inventory->getContents() as $item){
			if(!$item->isNull()){
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the golem can leave the held item in the inventory: it is empty or already holds the same item.
	 */
	public static function acceptsItem(Inventory $inventory, Item $held) : bool{
		$empty = true;
		foreach($inventory->getContents() as $item){
			if($item->isNull()){
				continue;
			}
			$empty = false;
			if($item->equals($held, false, false)){
				return true;
			}
		}
		return $empty;
	}

	/**
	 * Takes up to 16 items from the first non-empty slot.
	 */
	public static function takeItems(Inventory $inventory) : ?Item{
		for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
			$item = $inventory->getItem($slot);
			if($item->isNull()){
				continue;
			}
			$count = min($item->getCount(), self::MAX_STACK);
			$taken = (clone $item)->setCount($count);
			$inventory->setItem($slot, $item->setCount($item->getCount() - $count));
			return $taken;
		}
		return null;
	}

	/**
	 * Puts the items in the first empty slot or merges them with matching stacks, in slot order (vanilla
	 * addItemsToContainer). Returns what did not fit.
	 */
	public static function addItems(Inventory $inventory, Item $items) : Item{
		$items = clone $items;
		for($slot = 0, $size = $inventory->getSize(); $slot < $size && !$items->isNull(); ++$slot){
			$existing = $inventory->getItem($slot);
			if($existing->isNull()){
				$inventory->setItem($slot, $items);
				return VanillaItems::AIR();
			}
			if($existing->canStackWith($items) && $existing->getCount() < $existing->getMaxStackSize()){
				$add = min($existing->getMaxStackSize() - $existing->getCount(), $items->getCount());
				$inventory->setItem($slot, $existing->setCount($existing->getCount() + $add));
				$items->setCount($items->getCount() - $add);
			}
		}
		return $items;
	}
}
