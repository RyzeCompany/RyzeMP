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

use pocketmine\entity\animation\FireworkParticlesAnimation;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Explosive;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\FireworkRocket as FireworkItem;
use pocketmine\item\FireworkRocketExplosion;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\utils\Utils;
use pocketmine\world\sound\FireworkCrackleSound;
use pocketmine\world\sound\FireworkLaunchSound;
use function count;
use function sqrt;

class FireworkRocket extends Entity implements Explosive, NeverSavedWithChunkEntity{

	public static function getNetworkTypeId() : string{ return EntityIds::FIREWORKS_ROCKET; }

	protected int $maxFlightTimeTicks;

	/** @var FireworkRocketExplosion[] */
	protected array $explosions = [];

	/**
	 * Whether the firework is boosting its owner while gliding. An attached firework follows its owner instead of
	 * flying on its own; the client propels itself.
	 */
	protected bool $attachedToOwner = false;

	/**
	 * Whether the firework was shot from a crossbow: it flies straight at constant speed and explodes when it hits a
	 * block or a mob (Java FireworkRocketEntity shotAtAngle).
	 */
	private bool $shotAsProjectile = false;

	/**
	 * @param FireworkRocketExplosion[] $explosions
	 */
	public function __construct(Location $location, int $maxFlightTimeTicks, array $explosions, ?CompoundTag $nbt = null){
		if($maxFlightTimeTicks < 0){
			throw new \InvalidArgumentException("Life ticks cannot be negative");
		}
		$this->maxFlightTimeTicks = $maxFlightTimeTicks;
		$this->setExplosions($explosions);

		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	/**
	 * Returns the total number of ticks the firework will fly for before it explodes.
	 */
	public function getMaxFlightTimeTicks() : int{
		return $this->maxFlightTimeTicks;
	}

	/**
	 * Sets the total number of ticks the firework will fly for before it explodes.
	 *
	 * @return $this
	 */
	public function setMaxFlightTimeTicks(int $maxFlightTimeTicks) : self{
		if($maxFlightTimeTicks < 0){
			throw new \InvalidArgumentException("Max flight time ticks cannot be negative");
		}
		$this->maxFlightTimeTicks = $maxFlightTimeTicks;
		return $this;
	}

	/**
	 * @return FireworkRocketExplosion[]
	 */
	public function getExplosions() : array{
		return $this->explosions;
	}

	/**
	 * @param FireworkRocketExplosion[] $explosions
	 *
	 * @return $this
	 */
	public function setExplosions(array $explosions) : self{
		Utils::validateArrayValueType($explosions, function(FireworkRocketExplosion $_) : void{});
		$this->explosions = $explosions;
		return $this;
	}

	public function isAttachedToOwner() : bool{
		return $this->attachedToOwner;
	}

	/**
	 * Makes the firework boost its owning entity (elytra flight) instead of flying on its own.
	 *
	 * @return $this
	 */
	public function setAttachedToOwner(bool $attached) : self{
		$this->attachedToOwner = $attached;
		$this->networkPropertiesDirty = true;
		return $this;
	}

	public function isShotAsProjectile() : bool{
		return $this->shotAsProjectile;
	}

	/**
	 * Makes the firework fly like a projectile (crossbow): no acceleration, explosion on impact with a block or a mob
	 * other than its owner.
	 *
	 * @return $this
	 */
	public function setShotAsProjectile(bool $value) : self{
		$this->shotAsProjectile = $value;
		return $this;
	}

	/**
	 * Projectile mode: explodes if a mob lies on this tick's path (one box query per tick, only for crossbow rockets).
	 */
	private function checkProjectileEntityHit() : bool{
		$start = $this->location->asVector3();
		$end = $start->addVector($this->motion);
		$owner = $this->getOwningEntity();
		foreach($this->getWorld()->getNearbyEntities($this->boundingBox->addCoord($this->motion->x, $this->motion->y, $this->motion->z)->expand(1, 1, 1), $this) as $entity){
			if(!$entity instanceof Living || !$entity->isAlive() || !$entity->canBeCollidedWith() || ($entity === $owner && $this->ticksLived < 5)){
				continue;
			}
			if($entity->getBoundingBox()->expandedCopy(0.3, 0.3, 0.3)->calculateIntercept($start, $end) !== null){
				return true;
			}
		}
		return false;
	}

	private function explodeNow() : void{
		$this->flagForDespawn();
		$this->explode();
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		parent::move($dx, $dy, $dz);
		if($this->shotAsProjectile && $this->isCollided && !$this->isFlaggedForDespawn()){
			$this->explodeNow();
		}
	}

	protected function onFirstUpdate(int $currentTick) : void{
		parent::onFirstUpdate($currentTick);

		$this->broadcastSound(new FireworkLaunchSound());
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);

		if(!$this->isFlaggedForDespawn()){
			//Don't keep accelerating long-lived fireworks - this gets very rapidly out of control and makes the server
			//die. Vanilla fireworks will only live for about 52 ticks maximum anyway, so this only makes sure plugin
			//created fireworks don't murder the server
			$owner = $this->attachedToOwner ? $this->getOwningEntity() : null;
			if($owner !== null){
				//follow the gliding owner; the client moves itself (see MovementEffectPacket GLIDE_BOOST)
				$this->motion = Vector3::zero();
				$this->setPosition($owner->getPosition());
			}elseif($this->shotAsProjectile){
				//constant speed; hits are checked along the path
				if($this->checkProjectileEntityHit()){
					$this->explodeNow();
					return $hasUpdate;
				}
			}elseif($this->ticksLived < 60){
				$this->addMotion($this->motion->x * 0.15, 0.04, $this->motion->z * 0.15);
			}

			if($this->ticksLived >= $this->maxFlightTimeTicks){
				$this->explodeNow();
			}
		}

		return $hasUpdate;
	}

	public function explode() : void{
		if(($explosionCount = count($this->explosions)) !== 0){
			$this->broadcastAnimation(new FireworkParticlesAnimation($this));
			foreach($this->explosions as $explosion){
				$this->broadcastSound($explosion->getType()->getExplosionSound());
				if($explosion->willTwinkle()){
					$this->broadcastSound(new FireworkCrackleSound());
				}
			}

			$force = ($explosionCount * 2) + 5;
			$world = $this->getWorld();
			foreach($world->getCollidingEntities($this->getBoundingBox()->expandedCopy(5, 5, 5), $this) as $entity){
				if(!$entity instanceof Living){
					continue;
				}

				$position = $entity->getPosition();
				$distance = $position->distanceSquared($this->location);
				if($distance > 25){
					continue;
				}

				//cast two rays - one to the entity's feet and another to halfway up its body (according to Java, anyway)
				//this seems like it'd miss some cases but who am I to argue with vanilla logic :>
				$height = $entity->getBoundingBox()->getYLength();
				for($i = 0; $i < 2; $i++){
					$target = $position->add(0, 0.5 * $i * $height, 0);
					//a rocket attached to a gliding player sits exactly at the player's position: VoxelRayTrace throws for
					//zero-length rays (this crashed the server), and nothing can be in between anyway
					$rayPoints = $target->distanceSquared($this->location) < 1.0E-8 ? [] : VoxelRayTrace::betweenPoints($this->location, $target);
					foreach($rayPoints as $blockPos){
						if($world->getBlock($blockPos)->calculateIntercept($this->location, $target) !== null){
							continue 2; //obstruction, try another path
						}
					}

					//no obstruction
					$damage = $force * sqrt((5 - $position->distance($this->location)) / 5);
					$ev = new EntityDamageByEntityEvent($this, $entity, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, $damage);
					$entity->attack($ev);
					break;
				}
			}
		}
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$explosions = new ListTag();
		foreach($this->explosions as $explosion){
			$explosions->push($explosion->toCompoundTag());
		}
		$fireworksData = CompoundTag::create()
			->setTag(FireworkItem::TAG_FIREWORK_DATA, CompoundTag::create()
				->setTag(FireworkItem::TAG_EXPLOSIONS, $explosions)
			);

		$properties->setCompoundTag(EntityMetadataProperties::FIREWORK_ITEM, new CacheableNbt($fireworksData));

		$owner = $this->attachedToOwner ? $this->getOwningEntity() : null;
		if($owner !== null){
			//actor data key 18 (CustomDisplay in gophertunnel, MINECART_HAS_DISPLAY here) holds the runtime ID of the
			//entity a boosting firework is attached to; same as dragonfly (server/session/entity_metadata.go)
			$properties->setLong(EntityMetadataProperties::MINECART_HAS_DISPLAY, $owner->getId());
		}
	}
}
