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

namespace pocketmine\entity\monster;

use pocketmine\block\Lava;
use pocketmine\entity\ai\goal\HurtByTargetGoal;
use pocketmine\entity\ai\goal\NearestAttackableEntityGoal;
use pocketmine\entity\ai\hostile\goal\HopGoal;
use pocketmine\entity\ai\hostile\HopControl;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function floor;
use function intdiv;
use function max;
use function min;
use function mt_rand;

/**
 * Bedrock values (slime.json): sizes 1, 2 and 4 (VARIANT, which the client scales the model by), random on spawn.
 * Size 4: 16 health, 2.08 blocks, movement 0.6, 4 damage; size 2: 4 health, 1.04, 0.4, 2 damage; size 1: 1 health,
 * 0.52, 0.3, harmless. Other sizes (up to {@link Slime::MAX_SIZE}, only from saved data or plugins) follow the same
 * rules: size x size health, 0.2 + 0.1 x size movement, damage equal to the size. Hops every 0.5-1.5 seconds
 * (0.16-0.5 with a target) towards its target, hurts the players, iron golems and snow golems it touches (area_attack,
 * 0.15 blocks beyond its box, every 0.5 seconds) and targets the nearest of them, splits into 2-4 slimes of half its
 * size when it dies (keeping its name tag and persistence). Floats in water and lava. Takes fall damage (no damage
 * sensor, unlike magma cubes).
 * Experience: its size. Loot (size 1 only): 0-2 slimeballs (+0-1 per Looting level).
 */
class Slime extends Monster{
	public const TAG_SIZE = "Size"; //TAG_Int (PocketMine; Bedrock uses the variant)
	public const TAG_VARIANT = "Variant"; //TAG_Int

	/** Sizes of the slimes the world spawns. */
	public const SIZES = [1, 2, 4];
	/** Largest size kept from saved data (Java's limit). */
	public const MAX_SIZE = 127;
	private const CONTACT_COOLDOWN = 10;
	private const CONTACT_RANGE = 0.15;

	protected int $slimeSize = 1;
	protected HopControl $hop;
	private int $contactCooldown = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::SLIME; }

	public function __construct(Location $location, ?CompoundTag $nbt = null, ?int $size = null){
		$this->slimeSize = $size !== null ? max(1, min(self::MAX_SIZE, $size)) : self::SIZES[mt_rand(0, 2)];
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{
		$width = 0.52 * $this->slimeSize;
		return new EntitySizeInfo($width, $width, $width * 0.625);
	}

	public function getName() : string{
		return "Slime";
	}

	public function isImmuneToEffect(Effect $effect) : bool{
		//slime.json mob_effect_immunity (magma_cube.json has none)
		return ($effect === VanillaEffects::OOZING() && !$this instanceof MagmaCube) || parent::isImmuneToEffect($effect);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$size = $nbt->getInt(self::TAG_VARIANT, $nbt->getInt(self::TAG_SIZE, $this->slimeSize));
		if($size >= 1 && $size <= self::MAX_SIZE){
			$this->slimeSize = $size;
		}
		$this->setSize($this->getInitialSizeInfo());
		$this->setMaxHealth($this->getMaxHealthForSize());
		$this->setMovementSpeed($this->getSpeedForSize());
		$this->hop = new HopControl($this, $this->getCalmJumpDelay(), $this->getAggressiveJumpDelay());
		parent::initEntity($nbt);
		$this->setStepHeight(0.0);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setInt(self::TAG_VARIANT, $this->slimeSize);
		$nbt->setInt(self::TAG_SIZE, $this->slimeSize);
		return $nbt;
	}

	public function getSlimeSize() : int{
		return $this->slimeSize;
	}

	protected function getMaxHealthForSize() : int{
		//Bedrock 1, 4 and 16
		return $this->slimeSize * $this->slimeSize;
	}

	protected function getSpeedForSize() : float{
		//Bedrock 0.3, 0.4 and 0.6
		return 0.2 + 0.1 * $this->slimeSize;
	}

	/**
	 * Damage dealt to the players and golems it touches (0 for small slimes).
	 */
	public function getContactDamage() : float{
		return $this->slimeSize > 1 ? (float) $this->slimeSize : 0.0;
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	protected function getCalmJumpDelay() : array{
		return [10, 30];
	}

	/**
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	protected function getAggressiveJumpDelay() : array{
		return [3, 10];
	}

	protected function getJumpVelocityForHop() : float{
		return 0.42;
	}

	public function getHopControl() : HopControl{
		return $this->hop;
	}

	/**
	 * Whether the slime targets and hurts this mob besides players (vanilla entity filters: iron golems and snow
	 * golems; magma cubes leave snow golems alone).
	 */
	protected function isGolemTarget(Living $entity) : bool{
		return $entity instanceof IronGolem || $entity instanceof SnowGolem;
	}

	protected function registerGoals() : void{
		$this->goalSelector->addGoal(3, new HopGoal($this, $this->hop));

		$this->targetSelector->addGoal(1, new HurtByTargetGoal($this));
		//one nearest_attackable_target for players and golems: the nearest one wins, and it sticks to it (only players and
		//golems are looked at, and nothing while the world has none: the 32-block box holds every mob around)
		$this->targetSelector->addGoal(2, new NearestAttackableEntityGoal($this, fn(Living $e) => $e instanceof Player || $this->isGolemTarget($e), 16.0, true, 10, verticalRange: 16.0,
			candidateClasses: VanillaMethods::isDeclaredBy(static::class, "isGolemTarget", [self::class, MagmaCube::class]) ? [Player::class, IronGolem::class, SnowGolem::class] : null
		));
	}

	/**
	 * Whether the slime floats and hops in the liquid it is in (behavior.slime_float: water and lava).
	 */
	protected function isInFloatingLiquid() : bool{
		$types = PathBlockTypes::at($this->getWorld(), (int) floor($this->location->x), (int) floor($this->location->y + 0.1), (int) floor($this->location->z));
		return ($types & PathBlockTypes::WATER) !== 0 || (($types & PathBlockTypes::DANGER) !== 0 && $this->isInLava());
	}

	/**
	 * Whether the slime's feet are in lava.
	 */
	public function isInLava() : bool{
		return $this->getWorld()->getBlockAt((int) floor($this->location->x), (int) floor($this->location->y + 0.1), (int) floor($this->location->z)) instanceof Lava;
	}

	/**
	 * Vertical speed of a hop in a liquid, or null for the default (60% of a hop).
	 */
	protected function getLiquidJumpVelocity() : ?float{
		return null;
	}

	protected function tryChangeMovement() : void{
		if(!$this->isAlive()){
			parent::tryChangeMovement();
			return;
		}
		$floating = $this->isInFloatingLiquid();
		$this->motion = $this->hop->computeMotion($this->motion, $this->getMovementSpeed() * 0.5, $this->getJumpVelocityForHop(), $floating, $floating ? $this->getLiquidJumpVelocity() : null);
		if($this->hop->hasJumped()){
			//Bedrock "squish" sounds are the jump sounds (squish.big = mob.sulfur_cube.jump for sulfur cubes)
			$this->playMobSound($this->slimeSize > 1 ? LevelSoundEvent::SQUISH_BIG : LevelSoundEvent::SQUISH_SMALL);
		}elseif($this->hop->hasLanded()){
			$this->playMobSound(LevelSoundEvent::SLIME_LANDING);
		}
	}

	public function hasMovementUpdate() : bool{
		return $this->isAlive() || parent::hasMovementUpdate();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || !$this->isAlive()){
			return $hasUpdate;
		}
		if($this->contactCooldown > 0){
			$this->contactCooldown -= $tickDiff;
		}elseif($this->getContactDamage() > 0){
			$this->hurtTouchingPlayers();
		}
		return $hasUpdate;
	}

	/**
	 * Hurts the players, and the golems, touching the slime (vanilla area_attack: 0.15 blocks beyond its box, every
	 * 0.5 seconds).
	 */
	private function hurtTouchingPlayers() : void{
		$box = $this->boundingBox->expandedCopy(self::CONTACT_RANGE, self::CONTACT_RANGE, self::CONTACT_RANGE);
		$world = $this->getWorld();
		foreach($world->getPlayers() as $player){
			$liveBox = self::getLiveBoundingBoxOf($player);
			if($liveBox !== null ?
				//the live box, and the contact test before isTargetablePlayer() (no side effects): runs every tick over
				//every player of the world
				$liveBox->intersectsWith($box) && Sensing::isTargetablePlayer($player) :
				//a class overriding getBoundingBox(): the original order
				Sensing::isTargetablePlayer($player) && $player->getBoundingBox()->intersectsWith($box)
			){
				if($this->attackMelee($player, $this->getContactDamage())){
					$this->contactCooldown = self::CONTACT_COOLDOWN;
				}
			}
		}
		//golems: only looked for while the world has some (counted per class by the world)
		if($world->hasEntitiesOfClass(IronGolem::class) || $world->hasEntitiesOfClass(SnowGolem::class)){
			foreach($world->getNearbyEntities($box, $this) as $entity){
				if($entity instanceof Living && $entity->isAlive() && $this->isGolemTarget($entity) && $this->attackMelee($entity, $this->getContactDamage())){
					$this->contactCooldown = self::CONTACT_COOLDOWN;
				}
			}
		}
	}

	protected function endDeathAnimation() : void{
		//vanilla splits when the body is removed, where it slid to, not when it dies
		$this->split();
		parent::endDeathAnimation();
	}

	/**
	 * Splits the dead slime into 2-4 slimes of half its size, half a block above its centre (BDS 1.26.60 spawns them
	 * all there and lets them push each other apart; Java places them in the four corners).
	 *
	 * @return Slime[]
	 */
	public function split() : array{
		if($this->slimeSize <= 1){
			return [];
		}
		$children = [];
		$half = intdiv($this->slimeSize, 2);
		$count = mt_rand(2, 4);
		for($i = 0; $i < $count; ++$i){
			$child = $this->createChild(Location::fromObject($this->location->add(0, 0.5, 0), $this->getWorld(), mt_rand(0, 359), 0), $half);
			if($this->getNameTag() !== ""){
				$child->setNameTag($this->getNameTag());
			}
			$child->setPersistent($this->isPersistent());
			$child->spawnToAll();
			$children[] = $child;
		}
		return $children;
	}

	protected function createChild(Location $location, int $size) : Slime{
		return new Slime($location, null, $size);
	}

	public function getDrops() : array{
		if($this->slimeSize !== 1){
			return [];
		}
		return [VanillaItems::SLIMEBALL()->setCount(mt_rand(0, 2) + $this->lootingBonus())];
	}

	public function getXpDropAmount() : int{
		return $this->wasRecentlyHitByPlayer() ? $this->slimeSize : 0;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::SLIME_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->slimeSize);
	}

	protected function destroyCycles() : void{
		unset($this->hop);
		parent::destroyCycles();
	}
}
