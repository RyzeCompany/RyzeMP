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

namespace pocketmine\entity\ai\goal;

use pocketmine\block\DryGrass;
use pocketmine\block\Grass;
use pocketmine\block\TallGrass;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\Mob;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\world\GameRules;
use pocketmine\world\particle\BlockBreakParticle;
use function floor;
use function intdiv;
use function mt_rand;

/**
 * Sheep eat the grass they stand on (vanilla behavior.eat_block): short grass becomes air, a grass block becomes dirt.
 * Eating regrows their wool and makes babies grow faster.
 */
class EatGrassGoal extends Goal{
	/** Vanilla: 1.8 seconds. */
	private const EAT_TICKS = 36;

	private int $ticksLeft = 0;

	public function __construct(
		protected Sheep $sheep
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK | self::FLAG_JUMP;
	}

	public function canStart() : bool{
		//vanilla success chance per tick: 0.02 for babies, 0.001 for adults
		if(mt_rand(0, intdiv($this->sheep->isBaby() ? 50 : 1000, Mob::GOAL_EVALUATION_INTERVAL) - 1) !== 0){
			return false;
		}
		return $this->canEat();
	}

	private function canEat() : bool{
		$loc = $this->sheep->getLiveLocation();
		$world = $this->sheep->getWorld();
		$x = (int) floor($loc->x);
		$y = (int) floor($loc->y + 0.01);
		$z = (int) floor($loc->z);
		$feet = $world->getBlockAt($x, $y, $z);
		return $feet instanceof TallGrass || $feet instanceof DryGrass || $world->getBlockAt($x, $y - 1, $z) instanceof Grass;
	}

	public function canContinue() : bool{
		return $this->ticksLeft > 0;
	}

	public function start() : void{
		$this->ticksLeft = self::EAT_TICKS;
		$this->sheep->getNavigation()->stop();
		$this->sheep->broadcastActorEvent(ActorEvent::EAT_GRASS_ANIMATION);
	}

	public function stop() : void{
		$this->ticksLeft = 0;
	}

	public function tick() : void{
		if(--$this->ticksLeft !== 4){
			return;
		}
		$loc = $this->sheep->getLiveLocation();
		$world = $this->sheep->getWorld();
		$x = (int) floor($loc->x);
		$y = (int) floor($loc->y + 0.01);
		$z = (int) floor($loc->z);
		$feet = $world->getBlockAt($x, $y, $z);
		//mobgriefing off: the sheep still eats (and regrows its wool) but the block stays, like Java's EatBlockGoal
		$griefing = GameRules::allowsMobGriefing($world);
		if($feet instanceof TallGrass || $feet instanceof DryGrass){
			if($griefing){
				$world->addParticle($feet->getPosition()->add(0.5, 0.5, 0.5), new BlockBreakParticle($feet));
				$world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
			}
			$this->sheep->onEatGrass();
			return;
		}
		$below = $world->getBlockAt($x, $y - 1, $z);
		if($below instanceof Grass){
			if($griefing){
				$world->addParticle($below->getPosition()->add(0.5, 0.5, 0.5), new BlockBreakParticle($below));
				$world->setBlockAt($x, $y - 1, $z, VanillaBlocks::DIRT());
			}
			$this->sheep->onEatGrass();
		}
	}
}
