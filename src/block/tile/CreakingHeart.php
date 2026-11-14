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

namespace pocketmine\block\tile;

use pocketmine\entity\monster\Creaking;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\world\World;

/**
 * Block entity of a creaking heart: remembers the creaking the heart summoned (by its persistent UniqueID, like the
 * vanilla heart remembers its creaking's UUID) and the hurt-reaction cooldown. The creaking remembers the heart's
 * position ({@link Creaking::getHeartPosition()}).
 */
class CreakingHeart extends Tile{
	public const TAG_CREAKING = "creaking"; //TAG_Long: UniqueID of the bound creaking

	/**
	 * Ticks after loading during which a saved creaking that wasn't found yet is still waited for (vanilla: 20). Its
	 * chunk may load a little later than the heart's.
	 */
	public const LOAD_GRACE_TICKS = 20;

	private ?int $creakingUniqueId = null;
	private ?Creaking $creaking = null;
	private int $createdTick;
	/** Server tick until which hits on the creaking don't make the heart react again (vanilla emitter: 100 ticks). */
	private int $hurtCooldownUntil = 0;
	private int $outputSignal = 0;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->createdTick = $world->getServer()->getTick();
		//the heart ticks through scheduled block updates, which aren't saved: start them again when loaded or placed
		$world->scheduleDelayedBlockUpdate($pos, 1);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$tag = $nbt->getTag(self::TAG_CREAKING);
		$this->creakingUniqueId = $tag instanceof LongTag ? $tag->getValue() : null;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->creakingUniqueId !== null){
			$nbt->setLong(self::TAG_CREAKING, $this->creakingUniqueId);
		}
	}

	/**
	 * Returns whether the heart has a creaking, even if it wasn't found (loaded) yet.
	 */
	public function hasCreaking() : bool{
		return $this->creakingUniqueId !== null;
	}

	public function getCreakingUniqueId() : ?int{
		return $this->creakingUniqueId;
	}

	/**
	 * Returns whether the creaking is the one this heart summoned.
	 */
	public function isBoundTo(Creaking $creaking) : bool{
		return $this->creakingUniqueId !== null && $this->creakingUniqueId === $creaking->getPersistentUniqueId();
	}

	/**
	 * Returns the heart's creaking if it is loaded. A saved creaking still missing after {@link LOAD_GRACE_TICKS} is
	 * forgotten (vanilla), so the heart can summon another one; if it loads later it crumbles (no longer bound).
	 */
	public function getCreaking() : ?Creaking{
		if($this->creaking !== null && !$this->creaking->isClosed() && $this->creaking->isAlive()){
			return $this->creaking;
		}
		$this->creaking = null;
		if($this->creakingUniqueId === null){
			return null;
		}
		$world = $this->position->getWorld();
		$range = Creaking::HEART_DESPAWN_DISTANCE + 2;
		$center = $this->position->add(0.5, 0.5, 0.5);
		foreach($world->getNearbyEntities(new AxisAlignedBB(
			$center->x - $range, $center->y - $range, $center->z - $range,
			$center->x + $range, $center->y + $range, $center->z + $range
		)) as $entity){
			if($entity instanceof Creaking && $entity->isAlive() && $this->isBoundTo($entity)){
				return $this->creaking = $entity;
			}
		}
		if($world->getServer()->getTick() - $this->createdTick >= self::LOAD_GRACE_TICKS){
			$this->creakingUniqueId = null;
		}
		return null;
	}

	/**
	 * Binds a creaking to this heart (and the heart to the creaking).
	 */
	public function setCreaking(Creaking $creaking) : void{
		$this->creaking = $creaking;
		$this->creakingUniqueId = $creaking->getPersistentUniqueId();
		$creaking->setHeartPosition($this->position->asVector3());
	}

	/**
	 * Forgets the creaking (it crumbled, died or was replaced).
	 */
	public function clearCreaking() : void{
		$this->creaking = null;
		$this->creakingUniqueId = null;
	}

	/**
	 * Called by the creaking when it is attached to its heart again after loading.
	 *
	 * @internal
	 */
	public function relinkCreaking(Creaking $creaking) : void{
		if($this->isBoundTo($creaking)){
			$this->creaking = $creaking;
		}
	}

	/**
	 * Starts the hurt-reaction cooldown if it is over and returns whether the heart may react to a hit now.
	 */
	public function tryStartHurtReaction(int $currentTick, int $cooldown) : bool{
		if($currentTick < $this->hurtCooldownUntil){
			return false;
		}
		$this->hurtCooldownUntil = $currentTick + $cooldown;
		return true;
	}

	public function getOutputSignal() : int{ return $this->outputSignal; }

	/**
	 * @internal
	 */
	public function setOutputSignal(int $signal) : void{ $this->outputSignal = $signal; }

	protected function onBlockDestroyedHook() : void{
		//breaking the heart kills its creaking: it twitches, then crumbles
		$creaking = $this->getCreaking();
		$this->clearCreaking();
		$creaking?->startTwitching();
	}
}
