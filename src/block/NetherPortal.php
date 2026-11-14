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

use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\BabySpawning;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\entity\monster\ZombifiedPiglin;
use pocketmine\entity\SpawnReason;
use pocketmine\event\entity\EntityNaturalSpawnEvent;
use pocketmine\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\dimension\NetherPortalShape;
use pocketmine\world\dimension\PortalTravel;
use pocketmine\world\GameRules;
use function mt_rand;

class NetherPortal extends Transparent{
	/** A random tick spawns a zombified piglin when a roll in [0, 2000) is below the difficulty id. */
	private const ZOMBIFIED_PIGLIN_SPAWN_ROLL = 2000;

	protected int $axis = Axis::X;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalAxis($this->axis);
	}

	public function getAxis() : int{
		return $this->axis;
	}

	/**
	 * @throws \InvalidArgumentException
	 * @return $this
	 */
	public function setAxis(int $axis) : self{
		if($axis !== Axis::X && $axis !== Axis::Z){
			throw new \InvalidArgumentException("Invalid axis");
		}
		$this->axis = $axis;
		return $this;
	}

	public function getLightLevel() : int{
		return 11;
	}

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function getDrops(Item $item) : array{
		return [];
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	/**
	 * Vanilla NetherPortalBlock.randomTick(): outside the Nether and the End, a portal block may spawn a zombified
	 * piglin (chance difficulty / 2000 per random tick, so never in Peaceful) standing on the block under the portal
	 * column, with a portal cooldown.
	 */
	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if(mt_rand(0, self::ZOMBIFIED_PIGLIN_SPAWN_ROLL - 1) >= $world->getDifficulty() ||
			$world->getDimension() !== Dimension::OVERWORLD || !$world->areMobsEnabled() || $world->getGameRule(GameRules::DO_MOB_SPAWNING) !== true
		){
			return;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$minY = $world->getMinY();
		while($y > $minY && $world->getBlockAt($x, $y, $z) instanceof NetherPortal){
			--$y;
		}
		if($world->getBlockAt($x, $y, $z)->getSupportType(Facing::UP) !== SupportType::FULL){
			return;
		}
		$piglin = new ZombifiedPiglin(new Location($x + 0.5, $y + 1, $z + 0.5, $world, mt_rand(0, 359), 0));
		$piglin->setPersistent(false);
		$piglin->setSpawnReason(SpawnReason::STRUCTURE);
		BabySpawning::rollSpawnBaby($piglin);
		$ev = new EntityNaturalSpawnEvent($piglin, EntityNaturalSpawnEvent::CAUSE_STRUCTURE, true);
		$ev->call();
		if($ev->isCancelled() || $piglin->isClosed()){
			$piglin->close();
			return;
		}
		//vanilla: a full portal cooldown, so that it doesn't go through the portal it came out of at once
		PortalTravel::getInstance()->setPortalCooldown($piglin);
		$piglin->spawnToAll();
	}

	public function onEntityInside(Entity $entity) : bool{
		PortalTravel::getInstance()->onEntityInsidePortal($entity, Dimension::NETHER, $this->position);
		return true;
	}

	/**
	 * A portal block needs portal blocks of the same axis or obsidian above, below and on both sides along its axis;
	 * otherwise it breaks, and the neighbour updates break the rest of the portal.
	 */
	public function onNearbyBlockChange() : void{
		$alongAxis = $this->axis === Axis::X ? [Facing::WEST, Facing::EAST] : [Facing::NORTH, Facing::SOUTH];
		foreach([Facing::UP, Facing::DOWN, ...$alongAxis] as $face){
			$side = $this->getSide($face);
			if(!($side instanceof NetherPortal && $side->axis === $this->axis) && !NetherPortalShape::isFrame($side)){
				$this->position->getWorld()->setBlock($this->position, VanillaBlocks::AIR());
				return;
			}
		}
	}
}
