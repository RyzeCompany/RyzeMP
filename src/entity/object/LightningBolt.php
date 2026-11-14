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

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Door;
use pocketmine\block\LightningRod;
use pocketmine\block\SoulFire;
use pocketmine\block\utils\CopperMaterial;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\utils\SupportType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\world\LightningStrikeEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\particle\ElectricSparkParticle;
use pocketmine\world\sound\MobSound;
use pocketmine\world\vibration\GameEvent;
use pocketmine\world\World;
use function floor;
use function mt_rand;

/**
 * Lightning bolt (minecraft:lightning_bolt), vanilla behaviour: thunder and impact sounds, then the bolt lives 2 ticks
 * plus 0-2 extra flashes. While it lives it strikes every entity within 3 blocks (up to 6 above), once each:
 * {@link Entity::onStruckByLightning()} (5 damage and 8 seconds of fire by default, conversions for some mobs). On
 * Normal and Hard it sets fire to the block it hits and up to 4 around it (one more on each flash). The block below is
 * struck: a lightning rod gets a redstone pulse, and the copper it is attached to (or copper struck directly) loses
 * its oxidation, along with a few random copper blocks around it.
 *
 * A visual-only bolt (skeleton trap) only shows and sounds. Never saved.
 */
class LightningBolt extends Entity implements NeverSavedWithChunkEntity{
	public const DAMAGE = 5.0;
	public const FIRE_SECONDS = 8;
	/** Entities this far away horizontally (and up to 6 more above) are struck. */
	public const STRIKE_RADIUS = 3.0;
	public const STRIKE_HEIGHT = 6.0;

	private int $life = 2;
	private int $flashes;
	private bool $visualOnly;
	/**
	 * @var true[] runtime IDs of the entities already struck
	 * @phpstan-var array<int, true>
	 */
	private array $struck = [];

	public static function getNetworkTypeId() : string{ return EntityIds::LIGHTNING_BOLT; }

	public function __construct(Location $location, bool $visualOnly = false, ?CompoundTag $nbt = null){
		$this->visualOnly = $visualOnly;
		$this->flashes = mt_rand(1, 3) - 1;
		parent::__construct($location, $nbt);
	}

	/**
	 * Spawns a lightning bolt at the given position, after a {@link LightningStrikeEvent}. Returns null if a plugin
	 * cancelled it.
	 *
	 * @phpstan-param LightningStrikeEvent::CAUSE_* $cause
	 */
	public static function strike(Location $location, bool $visualOnly = false, int $cause = LightningStrikeEvent::CAUSE_CUSTOM) : ?self{
		$bolt = new self($location, $visualOnly);
		$ev = new LightningStrikeEvent($bolt, $cause);
		$ev->call();
		if($ev->isCancelled()){
			$bolt->close();
			return null;
		}
		$bolt->spawnToAll();
		return $bolt;
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.0, 0.0); }

	protected function getInitialDragMultiplier() : float{ return 0.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	public function isVisualOnly() : bool{
		return $this->visualOnly;
	}

	public function isFireProof() : bool{
		return true;
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	public function canSaveWithChunk() : bool{
		return false;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
		}
	}

	public function hasMovementUpdate() : bool{
		return false;
	}

	/**
	 * The block the bolt strikes (the one below it).
	 */
	public function getStrikePosition() : Vector3{
		return new Vector3((int) floor($this->location->x), (int) floor($this->location->y - 1.0E-6), (int) floor($this->location->z));
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->closed || $this->isFlaggedForDespawn()){
			return $hasUpdate;
		}
		if($this->life === 2){
			$this->broadcastSound(new MobSound(LevelSoundEvent::THUNDER, self::getNetworkTypeId()));
			$this->broadcastSound(new MobSound(LevelSoundEvent::EXPLODE, self::getNetworkTypeId()));
			if(!$this->visualOnly){
				$difficulty = $this->getWorld()->getDifficulty();
				if($difficulty >= World::DIFFICULTY_NORMAL){
					$this->spawnFire(4);
				}
				$this->strikeBlock();
			}
		}

		$this->life -= $tickDiff;
		if($this->life < 0){
			if($this->flashes <= 0){
				$this->flagForDespawn();
				return false;
			}
			if($this->life < -mt_rand(0, 9)){
				--$this->flashes;
				$this->life = 1;
				if(!$this->visualOnly && $this->getWorld()->getDifficulty() >= World::DIFFICULTY_NORMAL){
					$this->spawnFire(0);
				}
			}
		}

		if($this->life >= 0 && !$this->visualOnly){
			$this->strikeEntities();
		}
		return true;
	}

	private function strikeEntities() : void{
		$pos = $this->location;
		$box = new AxisAlignedBB(
			$pos->x - self::STRIKE_RADIUS, $pos->y - self::STRIKE_RADIUS, $pos->z - self::STRIKE_RADIUS,
			$pos->x + self::STRIKE_RADIUS, $pos->y + self::STRIKE_HEIGHT + self::STRIKE_RADIUS, $pos->z + self::STRIKE_RADIUS
		);
		foreach($this->getWorld()->getNearbyEntities($box, $this) as $entity){
			if(isset($this->struck[$entity->getId()]) || !$entity->isAlive() || $entity->isClosed() || $entity->isFlaggedForDespawn() || $entity instanceof LightningBolt){
				continue;
			}
			$this->struck[$entity->getId()] = true;
			if($entity instanceof Player && !$entity->hasFiniteResources()){
				continue;
			}
			$entity->onStruckByLightning($this);
		}
	}

	/**
	 * Sets fire to the struck block, and to $extra random blocks around it (vanilla: where there is air that can hold
	 * fire).
	 */
	private function spawnFire(int $extra) : void{
		$world = $this->getWorld();
		if($world->getGameRule(GameRules::DO_FIRE_TICK) === false){
			//vanilla: no fire from lightning when fire doesn't spread
			return;
		}
		$x = (int) floor($this->location->x);
		$y = (int) floor($this->location->y);
		$z = (int) floor($this->location->z);
		$this->tryIgnite($world, $x, $y, $z);
		for($i = 0; $i < $extra; ++$i){
			$this->tryIgnite($world, $x + mt_rand(-1, 1), $y + mt_rand(-1, 1), $z + mt_rand(-1, 1));
		}
	}

	private function tryIgnite(World $world, int $x, int $y, int $z) : void{
		if(!$world->isInWorld($x, $y, $z) || !$world->isChunkLoaded($x >> 4, $z >> 4)){
			return;
		}
		$block = $world->getBlockAt($x, $y, $z);
		if($block->getTypeId() !== BlockTypeIds::AIR){
			return;
		}
		$down = $block->getSide(Facing::DOWN);
		if(SoulFire::canBeSupportedBy($down)){
			$world->setBlockAt($x, $y, $z, VanillaBlocks::SOUL_FIRE());
			return;
		}
		$canBurn = $down->getSupportType(Facing::UP) === SupportType::FULL;
		if(!$canBurn){
			foreach(Facing::ALL as $face){
				if($block->getSide($face)->isFlammable()){
					$canBurn = true;
					break;
				}
			}
		}
		if($canBurn){
			$world->setBlockAt($x, $y, $z, VanillaBlocks::FIRE());
		}
	}

	/**
	 * Powers the lightning rod below the bolt and cleans the copper it hits (vanilla clearCopperOnLightningStrike).
	 */
	private function strikeBlock() : void{
		$world = $this->getWorld();
		$pos = $this->getStrikePosition();
		if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())){
			return;
		}
		$block = $world->getBlock($pos);
		if($block instanceof LightningRod){
			$block->onLightningStrike();
			$target = $block->getSide(Facing::opposite($block->getFacing()));
		}else{
			$world->addGameEvent($this->location, GameEvent::LIGHTNING_STRIKE, $this);
			$target = $block;
		}
		if(!self::isCleanableCopper($target)){
			return;
		}
		/** @var CopperMaterial&Block $target */
		$world->setBlock($target->getPosition(), $target->setOxidation(CopperOxidation::NONE));
		//BDS sends only the electric sparks on the cleaned copper (no scrape sound)
		$world->addParticle($target->getPosition(), new ElectricSparkParticle());
		for($walks = mt_rand(3, 5); $walks > 0; --$walks){
			$current = $target->getPosition();
			for($steps = mt_rand(1, 8); $steps > 0; --$steps){
				$next = $this->stepCleaningCopper($world, $current);
				if($next === null){
					break;
				}
				$current = $next;
			}
		}
	}

	/**
	 * @phpstan-assert-if-true CopperMaterial $block
	 */
	private static function isCleanableCopper(Block $block) : bool{
		return $block instanceof CopperMaterial && !$block->isWaxed() && !$block instanceof Door;
	}

	/**
	 * One step of a copper cleaning walk: a random copper block next to $pos loses one oxidation level. Returns where
	 * the walk goes on, or null if no copper was found in 10 tries.
	 */
	private function stepCleaningCopper(World $world, Vector3 $pos) : ?Vector3{
		for($i = 0; $i < 10; ++$i){
			$x = $pos->getFloorX() + mt_rand(-1, 1);
			$y = $pos->getFloorY() + mt_rand(-1, 1);
			$z = $pos->getFloorZ() + mt_rand(-1, 1);
			if(!$world->isInWorld($x, $y, $z) || !$world->isChunkLoaded($x >> 4, $z >> 4)){
				continue;
			}
			$block = $world->getBlockAt($x, $y, $z);
			if(!self::isCleanableCopper($block)){
				continue;
			}
			$previous = $block->getOxidation()->getPrevious();
			if($previous !== null){
				$world->setBlockAt($x, $y, $z, $block->setOxidation($previous));
			}
			return new Vector3($x, $y, $z);
		}
		return null;
	}
}
