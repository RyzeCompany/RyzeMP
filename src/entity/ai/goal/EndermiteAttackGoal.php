<?php
declare(strict_types=1);

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\Endermite;
use pocketmine\math\VoxelRayTrace;
use pocketmine\player\Player;
use pocketmine\world\World;

/** Pursues a visible survival player without generating chunks or targeting plugin NPCs. */
final class EndermiteAttackGoal extends Goal{
	private ?Player $target = null;
	private int $attackCooldown = 0;
	private int $repathCooldown = 0;

	public function __construct(private Endermite $endermite){}
	public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }

	public function canStart() : bool{
		if($this->endermite->getWorld()->getDifficulty() === World::DIFFICULTY_PEACEFUL){return false;}
		$best = 16.0 ** 2;
		$target = null;
		foreach($this->endermite->getWorld()->getPlayers() as $player){
			if($player->isCreative() || $player->isSpectator() || !$player->isAlive()){continue;}
			$distance = $player->getPosition()->distanceSquared($this->endermite->getPosition());
			if($distance < $best){$best = $distance; $target = $player;}
		}
		$this->target = $target;
		return $target !== null;
	}

	public function canContinue() : bool{
		$target = $this->target;
		return $target !== null && !$target->isClosed() && $target->isAlive() &&
			!$target->isCreative() && !$target->isSpectator() &&
			$target->getWorld() === $this->endermite->getWorld() &&
			$target->getPosition()->distanceSquared($this->endermite->getPosition()) < 20.0 ** 2 &&
			$this->endermite->getWorld()->getDifficulty() !== World::DIFFICULTY_PEACEFUL;
	}

	public function start() : void{
		$this->attackCooldown = $this->repathCooldown = 0;
		$this->endermite->setTargetEntity($this->target);
	}

	public function tick() : void{
		$target = $this->target;
		if($target === null){return;}
		$this->endermite->lookAt($target->getEyePos());
		if($this->attackCooldown > 0){--$this->attackCooldown;}
		if($this->repathCooldown > 0){--$this->repathCooldown;}
		$distance = $target->getPosition()->distanceSquared($this->endermite->getPosition());
		if($distance < 3.0){
			$this->endermite->getNavigation()->stop();
			if($this->attackCooldown === 0 && $this->hasLineOfSight($target)){
				$this->attackCooldown = 20;
				$this->endermite->attackMelee($target);
			}
		}elseif($this->repathCooldown === 0){
			$this->repathCooldown = 16;
			$this->endermite->getNavigation()->moveTo($target->getPosition(), 0.17);
		}
	}

	private function hasLineOfSight(Player $target) : bool{
		$from = $this->endermite->getEyePos();
		$to = $target->getEyePos();
		$world = $this->endermite->getWorld();
		foreach(VoxelRayTrace::betweenPoints($from, $to) as $position){
			if(!$world->isInLoadedTerrain($position) || $world->getBlock($position)->calculateIntercept($from, $to) !== null){
				return false;
			}
		}
		return true;
	}

	public function stop() : void{
		$this->target = null;
		$this->endermite->setTargetEntity(null);
		$this->endermite->getNavigation()->stop();
	}
}
