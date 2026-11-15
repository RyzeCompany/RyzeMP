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

namespace pocketmine\entity;

use pocketmine\entity\object\LeashKnot;
use pocketmine\entity\vehicle\Boat;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\MobSound;
use function count;
use function sqrt;

/**
 * Leads (vanilla Leashable): a player uses a lead on a leashable mob to lead it, and uses the mob again (or the knot)
 * to let go. Using a fence ties the mobs the player leads within 7 blocks to it (see {@link LeashKnot}); using a boat,
 * an adult happy ghast or another mob with Bedrock's minecraft:leashable_to ties them to that entity instead, and
 * shears cut every lead of the entity (see {@link LeashInteractions}). The mob walks after its holder when more than
 * 3 blocks away, is pulled beyond 6 blocks (vanilla elastic leash: each axis by 0.4 x its share of the direction
 * squared) and the lead breaks beyond 10 blocks; entities with other Bedrock leashable presets change these distances
 * ({@link LeashTrait::getLeashElasticDistance()}...). Leashed mobs don't despawn, and
 * drop the lead when they die. The holder is saved (player UUID, fence position or the persistent ID of a mob, e.g. the
 * wandering trader leading its llamas) and found again when loaded; a mob holder that is not found nearby within 5
 * seconds once the terrain around is loaded is given up (the lead drops, like Java). A mob holder that dies lets go.
 * How the entity goes after its holder is up to the using class ({@link LeashTrait::followLeashHolder()}).
 *
 * @internal used by {@link Mob}, {@link Squid} and {@link Boat} (see {@link Leashable})
 */
trait LeashTrait{
	/**
	 * Moves towards the holder, more than 3 blocks away (not a knot): mobs walk to it.
	 */
	abstract private function followLeashHolder(Entity $holder) : void;

	/**
	 * The lead pulls the entity (beyond 6 blocks): stops its own way of following.
	 */
	abstract private function stopFollowingLeashHolder() : void;

	/**
	 * Removes items from the stack in the player's hand (not in creative).
	 */
	abstract protected function consumeHeldItem(Player $player, int $count = 1) : void;

	private ?Entity $leashHolder = null;
	/** UUID of the player holding the lead, kept while they are away. */
	private ?string $leashPlayerUuid = null;
	/** Fence the lead is tied to, kept until the knot is recreated after loading. */
	private ?Vector3 $leashFence = null;
	/** Persistent ID of the mob holding the lead, kept while it isn't loaded. */
	private ?int $leashEntityId = null;
	/** Failed searches for a mob holder (one per second). */
	private int $leashSearches = 0;

	/**
	 * Whether a lead can be put on this mob.
	 */
	public function canBeLeashed() : bool{
		return false;
	}

	/**
	 * Distance to the holder beyond which the entity goes after it (Bedrock leashable soft_distance; mobs walk from 3
	 * blocks).
	 */
	protected function getLeashFollowDistance() : float{
		return Mob::LEASH_FOLLOW_DISTANCE;
	}

	/**
	 * Distance to the holder beyond which the lead pulls the entity (Bedrock leashable hard_distance, default 6).
	 */
	protected function getLeashElasticDistance() : float{
		return Mob::LEASH_ELASTIC_DISTANCE;
	}

	/**
	 * Distance to the holder beyond which the lead breaks (Bedrock leashable max_distance, default 10).
	 * @internal
	 */
	public function getLeashBreakDistance() : float{
		return Mob::LEASH_BREAK_DISTANCE;
	}

	public function isLeashed() : bool{
		return $this->leashHolder !== null || $this->leashPlayerUuid !== null || $this->leashFence !== null || $this->leashEntityId !== null;
	}

	/**
	 * Returns the player or knot holding the lead, if it is loaded.
	 */
	public function getLeashHolder() : ?Entity{
		return $this->leashHolder;
	}

	/**
	 * Whether the given entity holds the lead, or is the saved mob holder this mob hasn't found again yet (after
	 * loading, the holder is looked for once a second).
	 * @internal
	 */
	public function isLeashedTo(Entity $holder) : bool{
		return $this->leashHolder === $holder || ($this->leashHolder === null && $this->leashEntityId !== null && $this->leashEntityId === $holder->getPersistentUniqueIdIfAssigned());
	}

	public function setLeashHolder(Entity $holder) : void{
		LeashInteractions::onLeashHolderChanged($this->leashHolder, $holder);
		$this->leashHolder = $holder;
		$this->leashPlayerUuid = $holder instanceof Player ? $holder->getUniqueId()->toString() : null;
		$this->leashFence = $holder instanceof LeashKnot ? $holder->getFencePosition() : null;
		$this->leashEntityId = $holder instanceof Player || $holder instanceof LeashKnot ? null : $holder->getPersistentUniqueId();
		$this->leashSearches = 0;
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Removes the lead; it drops as an item if asked.
	 */
	public function unleash(bool $dropLead) : void{
		if(!$this->isLeashed()){
			return;
		}
		LeashInteractions::onLeashHolderChanged($this->leashHolder, null);
		$this->leashHolder = null;
		$this->leashPlayerUuid = null;
		$this->leashFence = null;
		$this->leashEntityId = null;
		$this->networkPropertiesDirty = true;
		if($dropLead){
			$this->getWorld()->dropItem($this->location->add(0, 0.5, 0), VanillaItems::LEAD());
		}
		$this->broadcastSound(new MobSound(LevelSoundEvent::LEAD_UNLEASH, static::getNetworkTypeId()));
		$this->onUnleashed();
	}

	/**
	 * Called when the lead was taken off or broke (Bedrock leashable on_unleash event).
	 */
	protected function onUnleashed() : void{

	}

	protected function loadLeash(CompoundTag $nbt) : void{
		$uuid = $nbt->getString(Mob::TAG_LEASH_PLAYER, "");
		if($uuid !== ""){
			$this->leashPlayerUuid = $uuid;
		}
		$fence = $nbt->getListTag(Mob::TAG_LEASH_FENCE, IntTag::class);
		if($fence !== null && count($fence) === 3){
			$values = $fence->getValue();
			$this->leashFence = new Vector3($values[0]->getValue(), $values[1]->getValue(), $values[2]->getValue());
		}
		if(($entityId = $nbt->getTag(Mob::TAG_LEASH_ENTITY)) instanceof LongTag){
			$this->leashEntityId = $entityId->getValue();
		}
	}

	protected function saveLeash(CompoundTag $nbt) : void{
		if($this->leashPlayerUuid !== null){
			$nbt->setString(Mob::TAG_LEASH_PLAYER, $this->leashPlayerUuid);
		}
		if($this->leashFence !== null){
			$nbt->setTag(Mob::TAG_LEASH_FENCE, new ListTag([
				new IntTag($this->leashFence->getFloorX()), new IntTag($this->leashFence->getFloorY()), new IntTag($this->leashFence->getFloorZ())
			]));
		}
		if($this->leashEntityId !== null){
			$nbt->setLong(Mob::TAG_LEASH_ENTITY, $this->leashEntityId);
		}
	}

	/**
	 * Finds the holder again after loading or when the player comes back.
	 */
	private function findLeashHolder() : void{
		$world = $this->getWorld();
		if($this->leashFence !== null){
			if(!$world->isInLoadedTerrain($this->leashFence)){
				return;
			}
			if(!LeashKnot::canTieTo($world, $this->leashFence)){
				$this->unleash(true);
				return;
			}
			$this->setLeashHolder(LeashKnot::getOrCreate($world, $this->leashFence));
		}elseif($this->leashPlayerUuid !== null){
			foreach($world->getPlayers() as $player){
				if($player->isAlive() && $player->getUniqueId()->toString() === $this->leashPlayerUuid){
					$this->setLeashHolder($player);
					return;
				}
			}
		}elseif($this->leashEntityId !== null){
			$loc = $this->location;
			$r = $this->getLeashBreakDistance();
			$area = new AxisAlignedBB($loc->x - $r, $loc->y - $r, $loc->z - $r, $loc->x + $r, $loc->y + $r, $loc->z + $r);
			foreach($world->getNearbyEntities($area, $this) as $entity){
				if($entity->isAlive() && !$entity->isClosed() && $entity->getPersistentUniqueIdIfAssigned() === $this->leashEntityId){
					$this->setLeashHolder($entity);
					return;
				}
			}
			//the holder would be within the lead's length: give up once all of that is loaded (Java: after 100 ticks)
			if($world->isInLoadedTerrain(new Vector3($area->minX, $loc->y, $area->minZ)) && $world->isInLoadedTerrain(new Vector3($area->maxX, $loc->y, $area->maxZ)) &&
				$world->isInLoadedTerrain(new Vector3($area->minX, $loc->y, $area->maxZ)) && $world->isInLoadedTerrain(new Vector3($area->maxX, $loc->y, $area->minZ)) &&
				++$this->leashSearches >= 5
			){
				$this->unleash(true);
			}
		}
	}

	protected function tickLeash() : void{
		$holder = $this->leashHolder;
		if($holder !== null && ($holder->isClosed() || $holder->getWorld() !== $this->getWorld() || !$holder->isAlive())){
			LeashInteractions::onLeashHolderChanged($holder, null);
			$this->leashHolder = null;
			$this->networkPropertiesDirty = true;
			if($holder instanceof LeashKnot || (!$holder instanceof Player && (!$holder->isAlive() || (!$holder->isClosed() && $holder->getWorld() !== $this->getWorld())))){
				//knots, and mobs that died or went elsewhere, let go (a mob unloaded with its chunk is looked for again)
				$this->unleash(true);
				return;
			}
			$holder = null;
		}
		if($holder === null){
			if(($this->server->getTick() + $this->id) % 20 === 0){
				$this->findLeashHolder();
			}
			return;
		}

		$pos = $this->location;
		//asked on every tick of every leashed mob: the holder's location itself instead of a copy, unless its class
		//overrides getLocation() or getPosition()
		$target = Mob::getLiveLocationOf($holder) ?? $holder->getPosition();
		$dx = $target->x - $pos->x;
		$dy = $target->y - $pos->y;
		$dz = $target->z - $pos->z;
		$distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($distance > $this->getLeashBreakDistance()){
			$this->broadcastSound(new MobSound(LevelSoundEvent::LEAD_BREAK, static::getNetworkTypeId()));
			$this->unleash(true);
			return;
		}
		if($distance > $this->getLeashElasticDistance()){
			$x = $dx / $distance;
			$y = $dy / $distance;
			$z = $dz / $distance;
			$this->setMotion($this->motion->add(
				($x < 0 ? -1 : 1) * $x * $x * 0.4,
				($y < 0 ? -1 : 1) * $y * $y * 0.4,
				($z < 0 ? -1 : 1) * $z * $z * 0.4
			));
			$this->stopFollowingLeashHolder();
		}elseif($distance > $this->getLeashFollowDistance() && !$holder instanceof LeashKnot){
			$this->followLeashHolder($holder);
		}
	}

	/**
	 * Handles leads when a player interacts with the mob. Returns whether something happened.
	 */
	protected function interactLeash(Player $player, Item $item) : bool{
		if($this->leashHolder === $player){
			$this->unleash($player->hasFiniteResources());
			return true;
		}
		if($item->getTypeId() === ItemTypeIds::LEAD && !$this->isLeashed() && $this->canBeLeashed()){
			$this->setLeashHolder($player);
			$this->consumeHeldItem($player);
			$this->broadcastSound(new MobSound(LevelSoundEvent::LEAD_LEASH, static::getNetworkTypeId()));
			return true;
		}
		return false;
	}

	protected function syncLeashData(EntityMetadataCollection $properties) : void{
		$properties->setGenericFlag(EntityMetadataFlags::LEASHED, $this->leashHolder !== null);
		$properties->setLong(EntityMetadataProperties::LEAD_HOLDER_EID, $this->leashHolder?->getId() ?? -1);
	}
}
