<?php
declare(strict_types=1);

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\Spider;
use pocketmine\math\VoxelRayTrace;
use pocketmine\player\Player;
use pocketmine\world\World;

/** The spider hunts in darkness and remains hostile for a while after being provoked. */
final class SpiderAttackGoal extends Goal{
	private ?Player $target = null;
	private int $attackCooldown = 0;
	private int $repathCooldown = 0;

	public function __construct(private Spider $spider){}
	public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }

	public function canStart() : bool{
		if($this->spider->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL ||
			($this->spider->isInBrightLight() && !$this->spider->isAngry())){
			return false;
		}
		$best = 16.0 ** 2;
		$target = null;
		foreach($this->spider->getWorld()->getPlayers() as $player){
			if($player->isCreative() || $player->isSpectator() || !$player->isAlive()){continue;}
			$distance = $player->getPosition()->distanceSquared($this->spider->getPosition());
			if($distance < $best){$best = $distance; $target = $player;}
		}
		$this->target = $target;
		return $target !== null;
	}

	public function canContinue() : bool{
		$target = $this->target;
		return $target !== null && !$target->isClosed() && $target->isAlive() &&
			!$target->isCreative() && !$target->isSpectator() &&
			$target->getWorld() === $this->spider->getWorld() &&
			$target->getPosition()->distanceSquared($this->spider->getPosition()) < 20.0 ** 2 &&
			$this->spider->getWorld()->getDifficulty() !== World::DIFFICULTY_PEACEFUL &&
			(!$this->spider->isInBrightLight() || $this->spider->isAngry());
	}

	public function start() : void{
		$this->spider->becomeAngry();
		$this->spider->setTargetEntity($this->target);
		$this->attackCooldown = $this->repathCooldown = 0;
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null){return;}
		if(!$this->spider->isAngry() && !$this->spider->isInBrightLight()){
			$this->spider->becomeAngry();
		}
		$this->spider->lookAt($target->getEyePos());
		if($this->attackCooldown > 0){--$this->attackCooldown;}
		if($this->repathCooldown > 0){--$this->repathCooldown;}
		$distance = $target->getPosition()->distanceSquared($this->spider->getPosition());
		if($distance < 3.0){
			$this->spider->getNavigation()->stop();
			if($this->attackCooldown === 0 && $this->hasLineOfSight($target)){
				$this->attackCooldown = 20;
				$this->spider->attackMelee($target);
			}
		}elseif($this->repathCooldown === 0){
			$this->repathCooldown = 16;
			$this->spider->getNavigation()->moveTo($target->getPosition(), 0.2);
		}
	}

	private function hasLineOfSight(Player $target) : bool{
		$from = $this->spider->getEyePos();
		$to = $target->getEyePos();
		$world = $this->spider->getWorld();
		foreach(VoxelRayTrace::betweenPoints($from, $to) as $position){
			if(!$world->isInLoadedTerrain($position) || $world->getBlock($position)->calculateIntercept($from, $to) !== null){
				return false;
			}
		}
		return true;
	}

	public function stop() : void{
		$this->target = null;
		$this->spider->setTargetEntity(null);
		$this->spider->getNavigation()->stop();
	}
}
