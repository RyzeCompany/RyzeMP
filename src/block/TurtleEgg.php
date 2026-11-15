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

namespace pocketmine\block;

use pocketmine\block\utils\SteppedOnBlock;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\animal\Bat;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\BlockTransaction;
use pocketmine\world\GameRules;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\world\particle\TurtleEggCrackParticle;
use pocketmine\world\sound\MobSound;
use function mt_rand;

/**
 * Turtle eggs (https://minecraft.wiki/w/Turtle_Egg): 1-4 eggs in one block, added one at a time like sea pickles. Only
 * drop with silk touch. On sand they crack on random ticks (always around dawn, otherwise 1 in 500, like Java) and
 * hatch after the third crack into one baby turtle per egg, whose home is the egg's position. Living entities standing
 * or landing on them trample them one egg at a time (players always, other mobs with mobgriefing; never turtles or
 * bats).
 */
class TurtleEgg extends Transparent implements SteppedOnBlock{
	public const MIN_COUNT = 1;
	public const MAX_COUNT = 4;
	public const MAX_CRACKS = 2;

	/** 1 in this many ticks standing on the eggs breaks one (vanilla TurtleEggBlock.stepOn()). */
	private const STEP_BREAK_CHANCE = 100;
	/** 1 in this many landings on the eggs breaks one (vanilla TurtleEggBlock.fallOn()). */
	private const LAND_BREAK_CHANCE = 3;
	/**
	 * The eggs crack around dawn: Java's celestial time of day between 0.65 and 0.69 (TurtleEggBlock
	 * shouldUpdateHatchLevel()), which is this range of the day time (03:03 to 03:54); otherwise 1 in 500.
	 */
	private const HATCH_TIME_START = 21062;
	private const HATCH_TIME_END = 21904;

	protected int $count = self::MIN_COUNT;
	protected int $cracks = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::MIN_COUNT, self::MAX_COUNT, $this->count);
		$w->boundedIntAuto(0, self::MAX_CRACKS, $this->cracks);
	}

	public function getCount() : int{ return $this->count; }

	/** @return $this */
	public function setCount(int $count) : self{
		if($count < self::MIN_COUNT || $count > self::MAX_COUNT){
			throw new \InvalidArgumentException("Count must be in range " . self::MIN_COUNT . " ... " . self::MAX_COUNT);
		}
		$this->count = $count;
		return $this;
	}

	public function getCracks() : int{ return $this->cracks; }

	/** @return $this */
	public function setCracks(int $cracks) : self{
		if($cracks < 0 || $cracks > self::MAX_CRACKS){
			throw new \InvalidArgumentException("Cracks must be in range 0 ... " . self::MAX_CRACKS);
		}
		$this->cracks = $cracks;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(3 / 16, 0, 3 / 16)->trim(Facing::UP, 9 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return ($blockReplace instanceof TurtleEgg && $blockReplace->count < self::MAX_COUNT) || parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($blockReplace instanceof TurtleEgg && $blockReplace->count < self::MAX_COUNT){
			$this->count = $blockReplace->count + 1;
			$this->cracks = $blockReplace->cracks;
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if(!self::isSand($this->getSide(Facing::DOWN))){
			return;
		}
		if(!self::isHatchingTime($world->getTimeOfDay()) && mt_rand(0, 499) !== 0){
			return;
		}
		if($this->cracks < self::MAX_CRACKS){
			$cracked = (clone $this)->setCracks($this->cracks + 1);
			$world->setBlock($this->position, $cracked);
			//like BDS 1.26.52: only the level event, the client plays the crack sound with the particles
			$world->addParticle($this->position, new TurtleEggCrackParticle($cracked));
			return;
		}
		$world->setBlock($this->position, VanillaBlocks::AIR());
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new MobSound(LevelSoundEvent::BLOCK_TURTLE_EGG_HATCH, ""));
		for($i = 0; $i < $this->count; ++$i){
			$turtle = new Turtle(Location::fromObject($this->position->add(0.3 + Utils::getRandomFloat() * 0.4, 0, 0.3 + Utils::getRandomFloat() * 0.4), $world, Utils::getRandomFloat() * 360, 0));
			$turtle->setAge(Turtle::BABY_AGE);
			$turtle->setHomePosition($this->position->asVector3());
			$turtle->setPersistent(true);
			$turtle->spawnToAll();
		}
	}

	public function getSilkTouchDrops(Item $item) : array{
		return [$this->asItem()->setCount($this->count)];
	}

	/**
	 * The eggs hatch on sand, red sand and suspicious sand (Java block tag sand).
	 */
	private static function isSand(Block $block) : bool{
		return $block->hasTypeTag(BlockTypeTags::SAND) || $block->getTypeId() === BlockTypeIds::SUSPICIOUS_SAND;
	}

	/**
	 * Returns whether the eggs always crack at this time of day (otherwise they crack 1 in 500 random ticks).
	 */
	private static function isHatchingTime(int $timeOfDay) : bool{
		return $timeOfDay >= self::HATCH_TIME_START && $timeOfDay <= self::HATCH_TIME_END;
	}

	public function onEntityStepOn(Entity $entity) : void{
		if(!($entity instanceof Living && $entity->isSneaking())){
			$this->tryTrample($entity, self::STEP_BREAK_CHANCE);
		}
	}

	public function onEntityLand(Entity $entity) : ?float{
		//zombies jump on the eggs on purpose, and break them with their AI instead (vanilla)
		if(!$entity instanceof Zombie){
			$this->tryTrample($entity, self::LAND_BREAK_CHANCE);
		}
		return null;
	}

	private function tryTrample(Entity $entity, int $chance) : void{
		if(mt_rand(0, $chance - 1) === 0 && self::canTrample($entity)){
			$this->breakOneEgg();
		}
	}

	/**
	 * Returns whether the entity breaks eggs it stands or lands on (vanilla TurtleEggBlock.canDestroyEgg()).
	 */
	private static function canTrample(Entity $entity) : bool{
		if($entity instanceof Turtle || $entity instanceof Bat){
			return false;
		}
		return $entity instanceof Player || ($entity instanceof Living && GameRules::allowsMobGriefing($entity->getWorld()));
	}

	/**
	 * Breaks one of the eggs (the block goes away with the last one), with the break sound and particles, like
	 * trampling does. Used by mobs which break turtle eggs on purpose.
	 */
	public function breakOneEgg() : void{
		$world = $this->position->getWorld();
		$center = $this->position->add(0.5, 0.5, 0.5);
		$world->addSound($center, new MobSound(LevelSoundEvent::BLOCK_TURTLE_EGG_BREAK, ""));
		if($this->count > self::MIN_COUNT){
			$world->setBlock($this->position, (clone $this)->setCount($this->count - 1));
		}else{
			$world->setBlock($this->position, VanillaBlocks::AIR());
		}
		$world->addParticle($center, new BlockBreakParticle($this));
	}
}
