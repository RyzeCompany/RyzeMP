<?php

/*
 * Adapted from VanillaMine's LightningBolt (LGPL-3.0-or-later).
 * Copyright (c) PocketMine Team and VanillaMine contributors.
 */
declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Door;
use pocketmine\block\LightningRod;
use pocketmine\block\SoulFire;
use pocketmine\block\utils\BlockEventHelper;
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
use pocketmine\player\Player;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\sound\ThunderSound;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function floor;
use function mt_rand;

/**
 * Short-lived Bedrock lightning actor. Does not persist or use custom protocol data.
 * Extended block conversions (including copper and rod power) are not enabled yet.
 */
class LightningBolt extends Entity implements NeverSavedWithChunkEntity{
	private int $life = 2;
	private int $flashes;
	/** @var array<int, true> */
	private array $struck = [];

	public function __construct(Location $location, private bool $visualOnly = false, ?CompoundTag $nbt = null){
		$this->flashes = mt_rand(1, 3) - 1;
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

	/** Target solid block, one infinitesimal unit below the visible bolt origin. */
	public function getStrikePosition() : Vector3{
		return new Vector3((int) floor($this->location->x), (int) floor($this->location->y - 1.0E-6), (int) floor($this->location->z));
	}

	/** Is lightning fire enabled at this difficulty and gamerule value? */
	public static function mayIgnite(bool $fireTickEnabled, int $difficulty) : bool{
		return $fireTickEnabled && $difficulty >= World::DIFFICULTY_NORMAL;
	}

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
			if(!$this->visualOnly){
				$this->strikeBlock();
				$this->spawnFire(4);
			}
		}
		$this->life -= $tickDiff;
		if($this->life < 0){
			if($this->flashes <= 0){
				$this->flagForDespawn();
				return true;
			}
			if($this->life < -mt_rand(0, 9)){
				--$this->flashes;
				$this->life = 1;
				if(!$this->visualOnly){
					$this->spawnFire(0);
				}
			}
		}
		if($this->life >= 0 && !$this->visualOnly){
			$this->strikeEntities();
		}
		return true;
	}


	/** Vanilla fire rules: normal/hard, doFireTick enabled, loaded terrain, and suitable support. */
	private function spawnFire(int $extra) : void{
		$world = $this->getWorld();
		if(!self::mayIgnite($world->getGameRules()->getBool(GameRules::DO_FIRE_TICK), $world->getDifficulty())){
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
			BlockEventHelper::form($block, VanillaBlocks::SOUL_FIRE(), $down);
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
			BlockEventHelper::form($block, VanillaBlocks::FIRE(), $down);
		}
	}

	/** Powers a rod and removes copper oxidation without changing protected waxed blocks. */
	private function strikeBlock() : void{
		$world = $this->getWorld();
		$pos = $this->getStrikePosition();
		if(!$world->isInWorld($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) ||
			!$world->isChunkLoaded($pos->getFloorX() >> 4, $pos->getFloorZ() >> 4)
		){
			return;
		}
		$block = $world->getBlock($pos);
		if($block instanceof LightningRod){
			$block->onLightningStrike();
			$target = $block->getSide(Facing::opposite($block->getFacing()));
		}else{
			$target = $block;
		}
		if(!self::isCleanableCopper($target)){
			return;
		}
		/** @var CopperMaterial&Block $target */
		BlockEventHelper::form($target, $target->setOxidation(CopperOxidation::NONE), $target);
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

	public static function isCleanableCopper(Block $block) : bool{
		return $block instanceof CopperMaterial && !$block->isWaxed() && !($block instanceof Door);
	}

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
			/** @var CopperMaterial&Block $block */
			$previous = $block->getOxidation()->getPrevious();
			if($previous !== null){
				BlockEventHelper::form($block, $block->setOxidation($previous), $block);
			}
			return new Vector3($x, $y, $z);
		}
		return null;
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
