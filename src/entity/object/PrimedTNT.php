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

use pocketmine\block\BlockTypeIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Explosive;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\world\Explosion;
use pocketmine\world\Position;

class PrimedTNT extends Entity implements Explosive{
	/** Height of the explosion centre above the TNT's feet, as a fraction of its height. */
	private const EXPLOSION_HEIGHT_FRACTION = 0.0625;
	/** Horizontal motion kept per tick on the ground, whatever the block (vanilla PrimedTnt.tick()). */
	private const GROUND_HORIZONTAL_KEPT = 0.7;
	/** BDS 1.26.60 pushes primed TNT twice as hard as Java's 0.014 (measured in the air and on the ground of a stream). */
	private const WATER_CURRENT_PUSH = 0.028;

	private const TAG_FUSE = "Fuse"; //TAG_Short

	public static function getNetworkTypeId() : string{ return EntityIds::TNT; }

	protected int $fuse;
	protected bool $worksUnderwater = false;
	/** What currents added to the motion since the last movement tick, kept out of that tick's drag and friction. */
	private ?Vector3 $currentPush = null;

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.98, 0.98); }

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.04; }

	public function getFuse() : int{
		return $this->fuse;
	}

	public function setFuse(int $fuse) : void{
		if($fuse < 0 || $fuse > 32767){
			throw new \InvalidArgumentException("Fuse must be in the range 0-32767");
		}
		$this->fuse = $fuse;
		$this->networkPropertiesDirty = true;
	}

	public function worksUnderwater() : bool{ return $this->worksUnderwater; }

	public function setWorksUnderwater(bool $worksUnderwater) : void{
		$this->worksUnderwater = $worksUnderwater;
		$this->networkPropertiesDirty = true;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
		}
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->fuse = $nbt->getShort(self::TAG_FUSE, 80);
	}

	public function canCollideWith(Entity $entity) : bool{
		return false;
	}

	protected function getWaterCurrentPush() : float{
		return self::WATER_CURRENT_PUSH;
	}

	protected function checkBlockIntersections() : void{
		$before = $this->motion;
		parent::checkBlockIntersections();
		if($this->motion !== $before){
			$push = $this->motion->subtractVector($before);
			$this->currentPush = $this->currentPush?->addVector($push) ?? $push;
		}
	}

	protected function tryChangeMovement() : void{
		//vanilla slows TNT on the ground by 0.7, not by the block's friction, and only then adds the push of currents: in a
		//cannon's flowing water BDS 1.26.60 drifts primed TNT 0.089 blocks per tick (block friction held it near 0.02)
		$push = $this->currentPush;
		$this->currentPush = null;
		$motion = $push !== null ? $this->motion->subtractVector($push) : $this->motion;
		$friction = 1 - $this->drag;
		$horizontal = $this->onGround ? $friction * self::GROUND_HORIZONTAL_KEPT : $friction;
		$mY = $this->gravityEnabled ? $motion->y - $this->gravity : $motion->y;
		$this->motion = new Vector3($motion->x * $horizontal, $mY * $friction, $motion->z * $horizontal);
		if($push !== null){
			$this->motion = $this->motion->addVector($push);
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setShort(self::TAG_FUSE, $this->fuse);

		return $nbt;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		if($this->closed){
			return false;
		}

		$hasUpdate = parent::entityBaseTick($tickDiff);

		if(!$this->isFlaggedForDespawn()){
			$this->fuse -= $tickDiff;
			$this->networkPropertiesDirty = true;

			if($this->fuse <= 0){
				$this->flagForDespawn();
				$this->explode();
			}
		}

		return $hasUpdate || $this->fuse >= 0;
	}

	public function explode() : void{
		$ev = new EntityPreExplodeEvent($this, 4);
		$ev->call();
		if(!$ev->isCancelled()){
			//vanilla blasts from just above the bottom of the TNT (Java getY(0.0625)), not its middle: rays from half a
			//block higher carve far more of the floor (a TNT on a dirt pit in stone broke ~67 blocks, BDS and Java ~51)
			$explosion = new Explosion(Position::fromObject($this->location->add(0, $this->size->getHeight() * self::EXPLOSION_HEIGHT_FRACTION, 0), $this->getWorld()), $ev->getRadius(), $this, $ev->getFireChance(), $this->worksUnderwater ? [BlockTypeIds::WATER] : []);
			if($ev->isBlockBreaking()){
				$explosion->explodeA();
			}
			$explosion->explodeB();
		}
	}

	public function getPickedItem() : ?Item{
		return VanillaBlocks::TNT()->setWorksUnderwater($this->worksUnderwater)->asItem();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setGenericFlag(EntityMetadataFlags::IGNITED, true);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->worksUnderwater ? 1 : 0);
		$properties->setInt(EntityMetadataProperties::FUSE_LENGTH, $this->fuse);
	}

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3->add(0, 0.49, 0);
	}
}
