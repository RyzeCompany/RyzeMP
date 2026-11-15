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

use pocketmine\block\tile\Beehive as TileBeehive;
use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\animal\Bee;
use pocketmine\entity\Location;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\GlassBottle;
use pocketmine\item\Item;
use pocketmine\item\Shears;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\BeehiveShearSound;
use pocketmine\world\sound\BottleFillSound;
use function count;
use function min;

/**
 * Beehive (and, through BeeNest, the bee nest). Full hives (honey level 5) give 3 honeycombs when sheared and a honey
 * bottle when a glass bottle is used on them, resetting the honey level.
 *
 * Bees enter the hive ({@link Beehive::addBee()}) to spend 2 minutes inside (30 seconds without nectar) and leave by
 * the front; each bee coming back with nectar raises the honey level by one. In the Overworld bees stay inside at
 * night and while it rains (Bedrock shelter_detection, see {@link Bee::isShelterTime()}), and they never leave by a
 * front block that has a collision box (they try again on the next update).
 *
 * Harvesting honey without a lit campfire up to 5 blocks below makes the bees inside leave and go for the nearest
 * player (Bedrock exited_disturbed_hive -> take_nearest_target). Mined with Silk Touch, the hive keeps its bees in the
 * dropped item. Otherwise breaking the hive releases its bees, and they and the bees within 16 blocks outside go for
 * the nearest player (block_sensor hive_destroyed).
 */
class Beehive extends Opaque implements HorizontalFacing, ComparatorReadable{
	use HorizontalFacingTrait;

	public const MAX_HONEY_LEVEL = 5;

	private int $honeyLevel = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->boundedIntAuto(0, self::MAX_HONEY_LEVEL, $this->honeyLevel);
	}

	public function getHoneyLevel() : int{ return $this->honeyLevel; }

	/** @return $this */
	public function setHoneyLevel(int $honeyLevel) : self{
		if($honeyLevel < 0 || $honeyLevel > self::MAX_HONEY_LEVEL){
			throw new \InvalidArgumentException("Honey level must be in range 0-" . self::MAX_HONEY_LEVEL);
		}
		$this->honeyLevel = $honeyLevel;
		return $this;
	}

	public function getComparatorOutput() : int{
		return $this->honeyLevel;
	}

	public function getFlameEncouragement() : int{
		return 5;
	}

	public function getFlammability() : int{
		return 20;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->honeyLevel < self::MAX_HONEY_LEVEL){
			return false;
		}
		$world = $this->position->getWorld();
		$center = $this->position->add(0.5, 0.5, 0.5);
		if($item instanceof Shears){
			$item->applyDamage(1);
			$world->dropItem($center->addVector(Vector3::zero()->getSide($face)->multiply(0.6)), VanillaItems::HONEYCOMB()->setCount(3));
			$world->addSound($center, new BeehiveShearSound());
		}elseif($item instanceof GlassBottle){
			$item->pop();
			$returnedItems[] = VanillaItems::HONEY_BOTTLE();
			$world->addSound($center, new BottleFillSound());
		}else{
			return false;
		}
		$this->honeyLevel = 0;
		$world->setBlock($this->position, $this);
		if($this->isSmoked()){
			return true;
		}
		//BDS 1.26.60 also angers the bees around an empty hive (Java only when bees are inside); area of Java's
		//BeehiveBlock.angerNearbyBees()
		$c = $this->position->add(0.5, 0.5, 0.5);
		foreach($world->getNearbyEntities(new AxisAlignedBB($c->x - 8, $c->y - 6, $c->z - 8, $c->x + 8, $c->y + 6, $c->z + 8)) as $entity){
			if($entity instanceof Bee && $entity->isAlive()){
				$entity->takeNearestTarget();
			}
		}
		if(!$this->isFrontBlocked()){
			//Java BeehiveBlockEntity.releaseOccupant(): a blocked front keeps the bees in, except when the hive breaks
			$tile = $world->getTile($this->position);
			if($tile instanceof TileBeehive && $tile->hasOccupants()){
				$this->releaseBees($tile->takeLeavingOccupants(0, true), true, false);
			}
		}
		return true;
	}

	/**
	 * Returns whether a lit campfire up to 5 blocks below calms the bees (its smoke rises through anything but full
	 * blocks). Checked only when honey is harvested.
	 */
	public function isSmoked() : bool{
		$world = $this->position->getWorld();
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		for($dy = 1; $dy <= 5; ++$dy){
			if(!$world->isInWorld($x, $y - $dy, $z)){
				return false;
			}
			$block = $world->getBlockAt($x, $y - $dy, $z);
			if($block instanceof Campfire){
				return $block->isLit();
			}
			if($block->isFullCube()){
				return false;
			}
		}
		return false;
	}

	public const BEE_UPDATE_INTERVAL = 20;
	public const TICKS_WITH_NECTAR = 2400;
	public const TICKS_WITHOUT_NECTAR = 600;

	/**
	 * Takes the bee into the hive (it's removed from the world). Returns false if the hive is full.
	 */
	public function addBee(Bee $bee) : bool{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileBeehive || $tile->isFull()){
			return false;
		}
		$tile->addOccupant(Bee::getNetworkTypeId(), $bee->saveNBT(), $bee->hasNectar() ? self::TICKS_WITH_NECTAR : self::TICKS_WITHOUT_NECTAR);
		$bee->flagForDespawn();
		$world->scheduleDelayedBlockUpdate($this->position, self::BEE_UPDATE_INTERVAL);
		return true;
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileBeehive){
			return;
		}
		//at night, in the rain and while the front is blocked the bees whose time is up wait inside (the front is only
		//checked when one is due)
		$hold = Bee::isShelterTime($world) || ($tile->hasOccupantDue(self::BEE_UPDATE_INTERVAL) && $this->isFrontBlocked());
		$leaving = $tile->takeLeavingOccupants(self::BEE_UPDATE_INTERVAL, false, $hold);
		if(count($leaving) > 0){
			$this->releaseBees($leaving, false, true);
		}
		if($tile->hasOccupants()){
			$world->scheduleDelayedBlockUpdate($this->position, self::BEE_UPDATE_INTERVAL);
		}
	}

	/**
	 * Whether the block in front of the hive has a collision box: the bees can't leave by it (Java
	 * BeehiveBlockEntity.releaseOccupant()).
	 */
	private function isFrontBlocked() : bool{
		return count($this->getSide($this->facing)->getCollisionBoxes()) > 0;
	}

	public function onPostPlace() : void{
		parent::onPostPlace();
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if($tile instanceof TileBeehive && $tile->hasOccupants()){
			//a hive placed with its bees (mined with Silk Touch): they keep counting down
			$world->scheduleDelayedBlockUpdate($this->position, self::BEE_UPDATE_INTERVAL);
		}
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	/**
	 * Mined with Silk Touch, the hive keeps its bees: the item carries the "Occupants" list (Bedrock, like a shulker
	 * box's items) and placing it puts them back.
	 */
	public function getSilkTouchDrops(Item $item) : array{
		$drop = $this->asItem();
		$tile = $this->position->isValid() ? $this->position->getWorld()->getTile($this->position) : null;
		if($tile instanceof TileBeehive && ($occupants = $tile->getOccupantsTag()) !== null){
			$drop->setNamedTag($drop->getNamedTag()->setTag(TileBeehive::TAG_OCCUPANTS, $occupants));
		}
		return [$drop];
	}

	/**
	 * @param CompoundTag[] $bees      saved data of the bees
	 * @param bool          $disturbed whether the bees leave a disturbed hive (they go for the nearest player)
	 */
	private function releaseBees(array $bees, bool $disturbed, bool $collectHoney) : void{
		$world = $this->position->getWorld();
		$front = $this->getSide($this->facing);
		$spawnAt = $front->isSolid() ? $this->position->add(0.5, 1.1, 0.5) : $front->getPosition()->add(0.5, 0.2, 0.5);
		$honey = $this->honeyLevel;
		foreach($bees as $saveData){
			$bee = new Bee(Location::fromObject($spawnAt, $world, 0, 0), $saveData);
			$bee->setHivePosition($this->position->asVector3());
			if($bee->hasNectar()){
				$bee->setHasNectar(false);
				if($collectHoney){
					$honey = min(self::MAX_HONEY_LEVEL, $honey + 1);
				}
			}
			$bee->spawnToAll();
			if($disturbed){
				$bee->takeNearestTarget();
			}
		}
		if($honey !== $this->honeyLevel){
			$this->honeyLevel = $honey;
			$world->setBlock($this->position, $this);
		}
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		$silkTouch = $item->hasEnchantment(VanillaEnchantments::SILK_TOUCH());
		if(!$silkTouch && $player !== null){
			//block_sensor (radius 16): the bees outside go for the nearest player too, except those that have stung
			$c = $this->position->add(0.5, 0.5, 0.5);
			foreach($world->getNearbyEntities(new AxisAlignedBB($c->x - 16, $c->y - 16, $c->z - 16, $c->x + 16, $c->y + 16, $c->z + 16)) as $entity){
				if($entity instanceof Bee && $entity->isAlive() && $entity->getPosition()->distanceSquared($c) <= 16 * 16){
					$entity->takeNearestTarget();
				}
			}
		}
		$tile = $world->getTile($this->position);
		//with Silk Touch the bees stay in the dropped item (see getSilkTouchDrops()), unless nothing drops
		$keptInItem = $silkTouch && ($player === null || $player->hasFiniteResources()) && $this->getBreakInfo()->isToolCompatible($item);
		if($tile instanceof TileBeehive && $tile->hasOccupants() && !$keptInItem){
			$this->releaseBees($tile->takeLeavingOccupants(0, true), !$silkTouch, false);
		}
		return parent::onBreak($item, $player, $returnedItems);
	}
}
