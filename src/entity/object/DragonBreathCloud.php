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

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\ParticleIds;
use function count;

/**
 * The purple cloud of dragon's breath left by a dragon fireball or the breath of a perched dragon: Instant Damage II
 * (like a lingering potion of Harming II, at the cloud's half strength) to the mobs inside, every second; the dragon
 * is immune to it (magic damage). Bedrock dragon_fireball.json: radius 6, dragonbreath particles, reapplied every
 * second. Not saved with the chunk.
 */
class DragonBreathCloud extends AreaEffectCloud implements NeverSavedWithChunkEntity{
	public const RADIUS = 6.0;
	/** Approximation: Bedrock's "duration": 120 unit is unclear; Java's cloud lasts 30 s. */
	public const DURATION = 600;

	public function __construct(Location $location, ?CompoundTag $nbt = null, float $radius = self::RADIUS, int $duration = self::DURATION){
		$nbt ??= CompoundTag::create()
			->setInt(self::TAG_DURATION, $duration)
			->setFloat(self::TAG_INITIAL_RADIUS, $radius)
			->setFloat(self::TAG_RADIUS, $radius)
			->setFloat(self::TAG_RADIUS_ON_USE, 0.0)
			->setFloat(self::TAG_RADIUS_PER_TICK, 0.0)
			->setInt(self::TAG_REAPPLICATION_DELAY, 20);
		parent::__construct($location, $nbt);
		if(count($this->effectCollection->all()) === 0){
			$this->effectCollection->add(new EffectInstance(VanillaEffects::INSTANT_DAMAGE(), 1, 1));
		}
	}

	/**
	 * Called when a player fills a glass bottle with the breath: the cloud shrinks (vanilla: by 0.5 blocks) and goes
	 * away once it is too small.
	 */
	public function onBottled() : void{
		++$this->pickupCount;
		$radius = $this->radius + $this->radiusChangeOnPickup;
		if($radius < 0.5){
			$this->flagForDespawn();
			return;
		}
		$this->setRadius($radius);
	}

	public function canSaveWithChunk() : bool{
		return false; //short-lived
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::AREA_EFFECT_CLOUD_PARTICLE_ID, ParticleIds::DRAGONS_BREATH);
	}
}
