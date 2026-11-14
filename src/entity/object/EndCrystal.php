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

namespace pocketmine\entity\object;

use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Explosive;
use pocketmine\entity\projectile\ExperienceBottle;
use pocketmine\entity\projectile\SplashPotion;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\world\dimension\DragonFight;
use pocketmine\world\Explosion;
use function max;

/**
 * End crystal. Any hit destroys it, whatever its damage (melee, any projectile that hurts entities, even snowballs
 * and eggs, explosions, which chain into other crystals): it explodes with power 6, without fire. Like vanilla it
 * ignores damage from the ender dragon (and its fireballs), and splash potions / bottles o' enchanting don't break
 * it. Invulnerable crystals (the ones rebuilt on the pillars while the dragon is being respawned) only die in the
 * void.
 */
class EndCrystal extends Entity implements Explosive{
	private const TAG_SHOWBASE = "ShowBottom"; //TAG_Byte
	private const TAG_INVULNERABLE = "Invulnerable"; //TAG_Byte

	private const TAG_BLOCKTARGET_X = "BlockTargetX"; //TAG_Int
	private const TAG_BLOCKTARGET_Y = "BlockTargetY"; //TAG_Int
	private const TAG_BLOCKTARGET_Z = "BlockTargetZ"; //TAG_Int

	/** Power of the explosion of a destroyed crystal. */
	public const EXPLOSION_POWER = 6.0;

	public static function getNetworkTypeId() : string{ return EntityIds::ENDER_CRYSTAL; }

	protected bool $showBase = false;
	protected ?Vector3 $beamTarget = null;
	protected bool $invulnerable = false;

	private bool $primed = false;
	private bool $destroyNotified = false;

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(2.0, 2.0); }

	protected function getInitialDragMultiplier() : float{ return 1.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	public function isFireProof() : bool{
		return true;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::END_CRYSTAL();
	}

	public function showBase() : bool{
		return $this->showBase;
	}

	public function setShowBase(bool $showBase) : void{
		$this->showBase = $showBase;
		$this->networkPropertiesDirty = true;
	}

	public function getBeamTarget() : ?Vector3{
		return $this->beamTarget;
	}

	/**
	 * Sets the block the crystal's beam points at (shown by clients), or null for no beam.
	 */
	public function setBeamTarget(?Vector3 $beamTarget) : void{
		$this->beamTarget = $beamTarget?->floor();
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Whether the crystal ignores all damage except the void's (vanilla "permanently invulnerable").
	 */
	public function isInvulnerable() : bool{
		return $this->invulnerable;
	}

	public function setInvulnerable(bool $invulnerable) : void{
		$this->invulnerable = $invulnerable;
	}

	/**
	 * Returns whether this damage leaves the crystal untouched: invulnerable crystals, the ender dragon (vanilla
	 * crystals ignore any damage the dragon causes) and thrown potions / experience bottles (they don't hurt what
	 * they hit).
	 */
	protected function ignoresDamage(EntityDamageEvent $source) : bool{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			return false;
		}
		if($this->invulnerable){
			return true;
		}
		if($source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			$direct = $source instanceof EntityDamageByChildEntityEvent ? $source->getChild() : $damager;
			if($damager instanceof EnderDragon || $direct instanceof EnderDragon || $direct?->getOwningEntity() instanceof EnderDragon){
				return true;
			}
			if($direct instanceof SplashPotion || $direct instanceof ExperienceBottle){
				return true;
			}
		}
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->ignoresDamage($source)){
			$source->cancel();
		}else{
			//any hit destroys the crystal, even one that deals no damage (snowballs, eggs...)
			$source->setBaseDamage(max($source->getBaseDamage(), $this->getHealth()));
		}
		parent::attack($source);
		if(
			$source->getCause() !== EntityDamageEvent::CAUSE_VOID &&
			!$source->isCancelled()
		){
			$this->primed = true;
		}
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->setMaxHealth(1);
		$this->setHealth(1);

		$this->setShowBase($nbt->getByte(self::TAG_SHOWBASE, 0) === 1);
		$this->invulnerable = $nbt->getByte(self::TAG_INVULNERABLE, 0) === 1;

		if(
			($beamXTag = $nbt->getTag(self::TAG_BLOCKTARGET_X)) instanceof IntTag &&
			($beamYTag = $nbt->getTag(self::TAG_BLOCKTARGET_Y)) instanceof IntTag &&
			($beamZTag = $nbt->getTag(self::TAG_BLOCKTARGET_Z)) instanceof IntTag
		){
			$this->setBeamTarget(new Vector3($beamXTag->getValue(), $beamYTag->getValue(), $beamZTag->getValue()));
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();

		$nbt->setByte(self::TAG_SHOWBASE, $this->showBase ? 1 : 0);
		if($this->invulnerable){
			$nbt->setByte(self::TAG_INVULNERABLE, 1);
		}
		if($this->beamTarget !== null){
			$nbt->setInt(self::TAG_BLOCKTARGET_X, $this->beamTarget->getFloorX());
			$nbt->setInt(self::TAG_BLOCKTARGET_Y, $this->beamTarget->getFloorY());
			$nbt->setInt(self::TAG_BLOCKTARGET_Z, $this->beamTarget->getFloorZ());
		}
		return $nbt;
	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		if($this->primed){
			$this->primed = false;
			$this->explode();
		}
		if(!$this->destroyNotified){
			$this->destroyNotified = true;
			DragonFight::notifyCrystalDestroyed($this);
		}
		return true;
	}

	public function explode() : void{
		$ev = new EntityPreExplodeEvent($this, self::EXPLOSION_POWER);
		$ev->call();
		if(!$ev->isCancelled()){
			$explosion = new Explosion($this->getPosition(), $ev->getRadius(), $this, $ev->getFireChance());
			if($ev->isBlockBreaking()){
				$explosion->explodeA();
			}
			$explosion->explodeB();
		}
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setGenericFlag(EntityMetadataFlags::SHOWBASE, $this->showBase);
		$properties->setBlockPos(EntityMetadataProperties::BLOCK_TARGET, BlockPosition::fromVector3($this->beamTarget ?? Vector3::zero()));
	}
}
