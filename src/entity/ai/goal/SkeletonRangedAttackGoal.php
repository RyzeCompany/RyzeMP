<?php
declare(strict_types=1);

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\Skeleton;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\math\VoxelRayTrace;
use pocketmine\player\Player;
use pocketmine\world\sound\BowShootSound;
use pocketmine\world\World;

/**
 * Conservative ranged AI: only fires at living survival players with clear
 * line of sight, respects projectile launch cancellation and world difficulty.
 */
final class SkeletonRangedAttackGoal extends Goal{
	private ?Player $target = null;
	private int $cooldown = 0;
	private int $repathCooldown = 0;

	public function __construct(private Skeleton $skeleton){}
	public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }

	public function canStart() : bool{
		if($this->skeleton->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){return false;}
		$nearest = null;
		$best = 16.0 ** 2;
		foreach($this->skeleton->getWorld()->getPlayers() as $player){
			if($player->isCreative() || $player->isSpectator() || !$player->isAlive()){continue;}
			$distance = $this->skeleton->getPosition()->distanceSquared($player->getPosition());
			if($distance < $best){$best = $distance; $nearest = $player;}
		}
		$this->target = $nearest;
		return $nearest !== null;
	}

	public function canContinue() : bool{
		return $this->target !== null && !$this->target->isClosed() &&
			$this->target->isAlive() && !$this->target->isCreative() && !$this->target->isSpectator() &&
			$this->target->getWorld() === $this->skeleton->getWorld() &&
			$this->target->getPosition()->distanceSquared($this->skeleton->getPosition()) < 20.0 ** 2 &&
			$this->skeleton->getWorld()->getDifficulty() !== World::DIFFICULTY_PEACEFUL;
	}

	public function start() : void{
		$this->cooldown = 60; // 3 seconds on normal, 2 seconds on hard after the first shot
		$this->repathCooldown = 0;
		$this->skeleton->setTargetEntity($this->target);
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null){return;}
		$this->skeleton->lookAt($target->getEyePos());
		if($this->cooldown > 0){--$this->cooldown;}
		if($this->repathCooldown > 0){--$this->repathCooldown;}
		$distance = $target->getPosition()->distanceSquared($this->skeleton->getPosition());
		if($distance <= 15.0 ** 2 && $this->hasLineOfSight($target)){
			$this->skeleton->getNavigation()->stop();
			if($this->cooldown <= 0){
				$this->shoot($target);
				$this->cooldown = $this->skeleton->getWorld()->getDifficulty() === World::DIFFICULTY_HARD ? 40 : 60;
			}
		}elseif($this->repathCooldown <= 0){
			$this->repathCooldown = 16;
			$this->skeleton->getNavigation()->moveTo($target->getPosition(), 0.17);
		}
	}

	private function hasLineOfSight(Player $target) : bool{
		$world = $this->skeleton->getWorld();
		$from = $this->skeleton->getEyePos();
		$to = $target->getEyePos();
		foreach(VoxelRayTrace::betweenPoints($from, $to) as $position){
			if(!$world->isInLoadedTerrain($position) ||
				$world->getBlock($position)->calculateIntercept($from, $to) !== null){
				return false;
			}
		}
		return true;
	}

	private function shoot(Player $target) : void{
		$from = $this->skeleton->getEyePos();
		$to = $target->getEyePos();
		$distance = sqrt(($to->x - $from->x) ** 2 + ($to->z - $from->z) ** 2);
		$motion = $to->subtractVector($from)->add(0.0, $distance * 0.06, 0.0)->normalize()->multiply(1.6);
		$arrow = new Arrow(Location::fromObject($from, $this->skeleton->getWorld()), $this->skeleton, false);
		$arrow->setPickupMode(Arrow::PICKUP_NONE);
		$arrow->setMotion($motion);
		$event = new ProjectileLaunchEvent($arrow);
		$event->call();
		if($event->isCancelled()){
			$arrow->flagForDespawn();
			return;
		}
		$arrow->spawnToAll();
		$this->skeleton->getWorld()->addSound($from, new BowShootSound());
	}

	public function stop() : void{
		$this->target = null;
		$this->skeleton->setTargetEntity(null);
		$this->skeleton->getNavigation()->stop();
	}
}
