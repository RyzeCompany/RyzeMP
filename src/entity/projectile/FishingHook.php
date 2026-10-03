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

namespace pocketmine\entity\projectile;

use pocketmine\anticheat\AntiCheatManager;
use pocketmine\block\Block;
use pocketmine\block\Water;
use pocketmine\entity\animation\FishingHookBiteAnimation;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\FishingHookEntityEvent;
use pocketmine\event\player\PlayerFishEvent;
use pocketmine\item\FishingRod;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\ServerProperties;
use pocketmine\world\sound\WaterSplashSound;
use function max;
use function mt_rand;

class FishingHook extends Projectile implements NeverSavedWithChunkEntity{
	private ?Vector3 $waterBlock = null;
	private int $waitTicks = 0;
	private int $biteTicks = 0;
	private bool $reeled = false;
	private int $rodSlot;
	private Item $rod;

	public function __construct(Location $location, Player $owner, private FishingSettings $settings){
		$this->rodSlot = $owner->getInventory()->getHeldItemIndex();
		$this->rod = $owner->getInventory()->getItemInHand();
		parent::__construct($location, $owner);
		$this->setCanSaveWithChunk(false);
	}

	public static function getNetworkTypeId() : string{
		return EntityIds::FISHING_HOOK;
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.25, 0.25);
	}

	protected function getInitialDragMultiplier() : float{
		return 0.01;
	}

	protected function getInitialGravity() : float{
		return 0.03;
	}

	public function canCollideWith(Entity $entity) : bool{
		return $entity instanceof Player && $entity->getId() !== $this->getOwningEntityId() && parent::canCollideWith($entity);
	}

	public function hasMovementUpdate() : bool{
		return $this->waterBlock === null && $this->getTargetEntityId() === null && parent::hasMovementUpdate();
	}

	/** Also checked after plugin callbacks, which can teleport players or change their equipment. */
	public function isValidFor(Player $owner) : bool{
		return !$this->isClosed() && !$this->isFlaggedForDespawn()
			&& $owner->getId() === $this->getOwningEntityId()
			&& !$owner->isClosed() && $owner->isConnected() && $owner->isAlive() && !$owner->isSpectator()
			&& $owner->getWorld() === $this->getWorld()
			&& $owner->getPosition()->distanceSquared($this->location) <= $this->settings->range ** 2
			&& $owner->getInventory()->getHeldItemIndex() === $this->rodSlot
			&& $owner->getInventory()->getItemInHand() instanceof FishingRod
			&& $this->rod->equalsExact($owner->getInventory()->getItemInHand());
	}

	private function canHook(Player $owner, Player $target) : bool{
		return $this->settings->pvpEnabled
			&& $this->server->getConfigGroup()->getConfigBool(ServerProperties::PVP)
			&& $this->isValidFor($owner)
			&& $target !== $owner && !$target->isClosed() && $target->isConnected() && $target->isAlive()
			&& !$target->isCreative() && !$target->isSpectator() && $target->noDamageTicks === 0
			&& $target->getWorld() === $owner->getWorld()
			&& $owner->getPosition()->distanceSquared($target->getPosition()) <= $this->settings->range ** 2
			&& $this->hasClearLine($owner->getEyePos(), $target->getPosition()->add(0, $target->getSize()->getHeight() / 2, 0));
	}

	private function hasClearLine(Vector3 $start, Vector3 $end) : bool{
		foreach(VoxelRayTrace::betweenPoints($start, $end) as $pos){
			if(!$this->getWorld()->isInLoadedTerrain($pos) || $this->getWorld()->getBlock($pos)->calculateIntercept($start, $end) !== null){
				return false;
			}
		}
		return true;
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		$owner = $this->getOwningEntity();
		if($owner instanceof Player && $entityHit instanceof Player && $this->canHook($owner, $entityHit)){
			$event = new FishingHookEntityEvent($owner, $this, $entityHit, FishingHookEntityEvent::ACTION_HOOK);
			$event->call();
			if(!$event->isCancelled() && $this->canHook($owner, $entityHit)){
				$this->setTargetEntity($entityHit);
				return;
			}
		}
		$this->flagForDespawn();
	}

	private static function waterSurface(Water $water) : float{
		return $water->getPosition()->y + 1 - ($water->getFluidHeightPercent() - 1 / 9);
	}

	protected function calculateInterceptWithBlock(Block $block, Vector3 $start, Vector3 $end) : ?RayTraceResult{
		if($block instanceof Water){
			$pos = $block->getPosition();
			return (new AxisAlignedBB($pos->x, $pos->y, $pos->z, $pos->x + 1, self::waterSurface($block), $pos->z + 1))->calculateIntercept($start, $end);
		}
		return parent::calculateInterceptWithBlock($block, $start, $end);
	}

	protected function onHitBlock(Block $blockHit, RayTraceResult $hitResult) : void{
		if($blockHit instanceof Water){
			$this->waterBlock = $blockHit->getPosition()->asVector3();
			$this->waitTicks = mt_rand($this->settings->biteWaitMin, $this->settings->biteWaitMax);
			$this->setPosition($this->location->withComponents(null, self::waterSurface($blockHit), null));
			$this->broadcastSound(new WaterSplashSound(0.25));
		}else{
			parent::onHitBlock($blockHit, $hitResult);
		}
	}

	public function onUpdate(int $currentTick) : bool{
		if($this->isClosed()){
			return false;
		}
		$owner = $this->getOwningEntity();
		if($this->reeled || !$owner instanceof Player || !$this->isValidFor($owner)
			|| $this->ticksLived >= $this->settings->lifetimeTicks
			|| !$this->getWorld()->isInLoadedTerrain($this->location->addVector($this->motion))){
			$this->flagForDespawn();
			return true;
		}
		if($this->getTargetEntityId() !== null){
			$target = $this->getTargetEntity();
			if(!$target instanceof Player || !$this->canHook($owner, $target)){
				$this->flagForDespawn();
				return true;
			}
			$this->setPosition($target->getPosition()->add(0, $target->getSize()->getHeight() / 2, 0));
		}
		return parent::onUpdate($currentTick);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		parent::entityBaseTick($tickDiff);
		if($this->waterBlock !== null){
			$water = $this->getWorld()->getBlock($this->waterBlock);
			if(!$water instanceof Water){
				$this->waterBlock = null;
				$this->biteTicks = 0;
				$this->onGround = false;
			}else{
				$this->setPosition($this->location->withComponents(null, self::waterSurface($water) - ($this->biteTicks > 0 ? 0.15 : 0), null));
				// Only exposed water can yield a catch; water under a solid ceiling cannot.
				$above = $this->getWorld()->getBlock($this->waterBlock->add(0, 1, 0));
				if($above instanceof Water || $above->isSolid()){
					$this->biteTicks = 0;
					$this->waitTicks = $this->settings->biteWaitMin;
				}elseif($this->biteTicks > 0){
					$this->biteTicks = max(0, $this->biteTicks - $tickDiff);
					if($this->biteTicks === 0){
						$this->waitTicks = mt_rand($this->settings->biteWaitMin, $this->settings->biteWaitMax);
					}
				}else{
					$this->waitTicks -= $tickDiff;
					if($this->waitTicks <= 0){
						$this->biteTicks = $this->settings->biteWindow;
						$this->broadcastAnimation(new FishingHookBiteAnimation($this));
						$this->broadcastSound(new WaterSplashSound(0.6));
					}
				}
			}
		}
		return true; //Keep stationary hooks ticking for bites, expiry and owner checks.
	}

	/** Reel exactly once. Returns the durability cost; the held item applies Unbreaking. */
	public function reelIn(Player $owner) : int{
		if($this->reeled || !$this->isValidFor($owner)){
			return 0;
		}
		$this->reeled = true; //Set before events to prevent recursive rewards or pulls.
		try{
			$target = $this->getTargetEntity();
			if($target instanceof Player){
				if($this->canHook($owner, $target)){
					$event = new FishingHookEntityEvent($owner, $this, $target, FishingHookEntityEvent::ACTION_PULL);
					$event->call();
					if(!$event->isCancelled() && $this->canHook($owner, $target)){
						$impulses = AntiCheatManager::getInstance()->getSession($target)->getImpulseTracker();
						$impulses->pushSource("fishing_pull");
						try{
							if($target->setMotion($this->settings->getPullMotion($owner->getPosition()->subtractVector($target->getPosition())))){
								return 5;
							}
						}finally{
							$impulses->popSource();
						}
					}
				}
				return 0;
			}
			if($this->waterBlock !== null && $this->biteTicks > 0 && $this->getWorld()->getBlock($this->waterBlock) instanceof Water){
				$roll = mt_rand(1, 100);
				$catch = match(true){
					$roll <= 60 => VanillaItems::RAW_FISH(),
					$roll <= 85 => VanillaItems::RAW_SALMON(),
					$roll <= 98 => VanillaItems::PUFFERFISH(),
					default => VanillaItems::CLOWNFISH()
				};
				$event = new PlayerFishEvent($owner, $this, $catch, mt_rand(1, 6));
				$event->call();
				if(!$event->isCancelled() && $this->isValidFor($owner)){
					$motion = $owner->getEyePos()->subtractVector($this->location)->normalize()->multiply(0.4);
					$this->getWorld()->dropItem($this->location, $event->getCatch(), $motion->add(0, 0.2, 0));
					$this->getWorld()->dropExperience($owner->getPosition(), $event->getExperience());
					return 1;
				}
				return 0;
			}
			return $this->blockHit !== null ? 2 : 0;
		}finally{
			$this->flagForDespawn();
		}
	}

	protected function onDispose() : void{
		$owner = $this->getOwningEntity();
		if($owner instanceof Player && $owner->getFishingHook() === $this){
			$owner->setFishingHook(null);
		}
		parent::onDispose();
	}
}
