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

use pocketmine\block\Block;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\object\DragonBreathCloud;
use pocketmine\math\RayTraceResult;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\world\sound\MobSound;

/**
 * Fireball shot by the ender dragon (Bedrock behavior_pack/entities/dragon_fireball.json): flies straight at power
 * 1.3 without gravity and leaves a {@link DragonBreathCloud} where it hits. It deals no direct damage.
 */
class DragonFireball extends Projectile{
	public const SPEED = 1.3;
	private const MAX_LIFETIME_TICKS = 1200;

	public static function getNetworkTypeId() : string{ return EntityIds::DRAGON_FIREBALL; }

	public function __construct(Location $location, ?Entity $shootingEntity, ?CompoundTag $nbt = null){
		parent::__construct($location, $shootingEntity, $nbt);
		$this->setBaseDamage(0);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.31, 0.31); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	public function isFireProof() : bool{
		return true;
	}

	public function canCollideWith(Entity $entity) : bool{
		return !$entity instanceof EnderDragon && parent::canCollideWith($entity);
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->ticksLived >= self::MAX_LIFETIME_TICKS && !$this->isFlaggedForDespawn()){
			$this->flagForDespawn();
		}
		return $hasUpdate;
	}

	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		if($entityHit instanceof EnderDragon){
			return;
		}
		$this->burst();
	}

	protected function onHitBlock(Block $blockHit, RayTraceResult $hitResult) : void{
		parent::onHitBlock($blockHit, $hitResult);
		$this->burst();
	}

	private function burst() : void{
		if($this->isFlaggedForDespawn()){
			return;
		}
		$this->flagForDespawn();
		$world = $this->getWorld();
		$cloud = new DragonBreathCloud(Location::fromObject($this->location, $world));
		$cloud->setOwningEntity($this->getOwningEntity());
		$cloud->spawnToAll();
		$world->addSound($this->location, new MobSound(LevelSoundEvent::EXPLODE, EntityIds::DRAGON_FIREBALL));
	}
}
