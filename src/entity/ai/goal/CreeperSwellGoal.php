<?php
declare(strict_types=1);

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\Creeper;
use pocketmine\player\Player;
use pocketmine\world\World;

/** Starts a fuse within 2.5 blocks of a valid survival target, cancels beyond 6. */
final class CreeperSwellGoal extends Goal{
	private ?Player $target = null;
	private int $repathCooldown = 0;
	public function __construct(private Creeper $creeper){}
	public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }
	public function canStart() : bool{
		if($this->creeper->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){return false;}
		$nearest = null;
		$best = 16.0 ** 2;
		foreach($this->creeper->getWorld()->getPlayers() as $player){
			if($player->isCreative() || $player->isSpectator() || !$player->isAlive()){continue;}
			$distance = $this->creeper->getPosition()->distanceSquared($player->getPosition());
			if($distance < $best){$best = $distance; $nearest = $player;}
		}
		$this->target = $nearest;
		return $nearest !== null;
	}
	public function canContinue() : bool{
		return $this->target !== null && !$this->target->isClosed() && $this->target->isAlive() &&
			!$this->target->isCreative() && !$this->target->isSpectator() &&
			$this->target->getWorld() === $this->creeper->getWorld() &&
			$this->target->getPosition()->distanceSquared($this->creeper->getPosition()) < 20.0 ** 2 &&
			$this->creeper->getWorld()->getDifficulty() !== World::DIFFICULTY_PEACEFUL;
	}
	public function start() : void{
		$this->repathCooldown = 0;
		$this->creeper->setTargetEntity($this->target);
	}
	public function tick() : void{
		$target = $this->target;
		if($target === null){return;}
		$this->creeper->lookAt($target->getEyePos());
		$distance = $target->getPosition()->distanceSquared($this->creeper->getPosition());
		if($distance <= 2.5 ** 2){
			$this->creeper->getNavigation()->stop();
			$this->creeper->setSwellDirection(1);
		}elseif($distance > 6.0 ** 2){
			$this->creeper->setSwellDirection(-1);
		}
		if($distance > 2.5 ** 2 && --$this->repathCooldown <= 0){
			$this->repathCooldown = 16;
			$this->creeper->getNavigation()->moveTo($target->getPosition(), 0.18);
		}
	}
	public function stop() : void{
		$this->target = null;
		$this->creeper->setTargetEntity(null);
		if(!$this->creeper->isIgnited()){$this->creeper->setSwellDirection(-1);}
		$this->creeper->getNavigation()->stop();
	}
}
