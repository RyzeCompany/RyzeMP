<?php

/*
 * Adapted from VanillaMine's LightningBolt (LGPL-3.0-or-later).
 * Copyright (c) PocketMine Team and VanillaMine contributors.
 */
declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\world\LightningStrikeEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\sound\ThunderSound;

/**
 * Short-lived Bedrock lightning actor. Does not persist or use custom protocol data.
 * Extended block conversions (including copper and rod power) are not enabled yet.
 */
class LightningBolt extends Entity implements NeverSavedWithChunkEntity{
	private int $life = 2;
	/** @var array<int, true> */
	private array $struck = [];

	public function __construct(Location $location, private bool $visualOnly = false, ?CompoundTag $nbt = null){
		parent::__construct($location, $nbt);
	}

	public static function getNetworkTypeId() : string{
		return "minecraft:lightning_bolt";
	}

	public static function strike(Location $location, bool $visualOnly = false, int $cause = LightningStrikeEvent::CAUSE_CUSTOM) : ?self{
		$bolt = new self($location, $visualOnly);
		$event = new LightningStrikeEvent($bolt, $cause);
		$event->call();
		if($event->isCancelled()){
			$bolt->close();
			return null;
		}
		$bolt->spawnToAll();
		return $bolt;
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.0, 0.0); }
	protected function getInitialDragMultiplier() : float{ return 0.0; }
	protected function getInitialGravity() : float{ return 0.0; }

	public function isFireProof() : bool{ return true; }
	public function canBeCollidedWith() : bool{ return false; }
	public function canSaveWithChunk() : bool{ return false; }
	public function isVisualOnly() : bool{ return $this->visualOnly; }

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$changed = parent::entityBaseTick($tickDiff);
		if($this->isClosed() || $this->isFlaggedForDespawn()){
			return $changed;
		}
		if($this->life === 2){
			$this->broadcastSound(new ThunderSound());
			$this->broadcastSound(new ExplodeSound());
		}
		if($this->life > 0 && !$this->visualOnly){
			$this->strikeEntities();
		}
		$this->life -= $tickDiff;
		if($this->life <= 0){
			$this->flagForDespawn();
		}
		return true;
	}

	private function strikeEntities() : void{
		$pos = $this->getPosition();
		$box = new AxisAlignedBB($pos->x - 3, $pos->y - 3, $pos->z - 3,
			$pos->x + 3, $pos->y + 9, $pos->z + 3);
		foreach($this->getWorld()->getNearbyEntities($box, $this) as $entity){
			if(isset($this->struck[$entity->getId()]) || !$entity->isAlive() || $entity->isClosed() || $entity->isFlaggedForDespawn()){
				continue;
			}
			$this->struck[$entity->getId()] = true;
			if($entity instanceof Player && !$entity->hasFiniteResources()){
				continue;
			}
			$entity->onStruckByLightning($this);
		}
	}
}
