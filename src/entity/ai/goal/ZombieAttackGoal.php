<?php
declare(strict_types=1);
namespace pocketmine\entity\ai\goal;

use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\player\Player;
use pocketmine\math\VoxelRayTrace;
use pocketmine\world\World;

/** Zombie pursues and attacks an eligible survival player, never a plugin NPC. */
final class ZombieAttackGoal extends Goal{
	private ?Player $victim = null;
	private int $attackCooldown = 0;
	private int $repathCooldown = 0;

	public function __construct(private Zombie $zombie){}
	public function getFlags() : int{ return self::FLAG_MOVE | self::FLAG_LOOK; }
	public function canStart() : bool{
		if($this->zombie->getWorld()->getDifficulty()===World::DIFFICULTY_PEACEFUL){
			return false;
		}
		$nearest=null;$best=16.0*16.0;
		foreach($this->zombie->getWorld()->getPlayers() as $player){
			if($player->isSpectator() || $player->isCreative() || !$player->isAlive()){continue;}
			$d=$player->getPosition()->distanceSquared($this->zombie->getPosition());
			if($d<$best){$best=$d;$nearest=$player;}
		}
		$this->victim=$nearest;
		return $nearest!==null;
	}
	public function canContinue() : bool{
		return $this->victim!==null && !$this->victim->isClosed()
			&& $this->victim->isAlive()
			&& !$this->victim->isSpectator() && !$this->victim->isCreative()
			&& $this->victim->getWorld()===$this->zombie->getWorld()
			&& $this->victim->getPosition()->distanceSquared($this->zombie->getPosition())<20*20
			&& $this->zombie->getWorld()->getDifficulty()!==World::DIFFICULTY_PEACEFUL;
	}
	public function start() : void{
		$this->attackCooldown=0;$this->repathCooldown=0;
		$this->zombie->setTargetEntity($this->victim);
	}
	public function tick() : void{
		$player=$this->victim;
		if($player===null){return;}
		$this->zombie->lookAt($player->getEyePos());
		if($this->attackCooldown>0){--$this->attackCooldown;}
		if($this->repathCooldown>0){--$this->repathCooldown;}
		$distance=$player->getPosition()->distanceSquared($this->zombie->getPosition());
		if($distance<3.0){
			$this->zombie->getNavigation()->stop();
			if($this->attackCooldown===0 && $this->hasLineOfSight($player)){
				$this->attackCooldown=20;
				$player->attack(new EntityDamageByEntityEvent($this->zombie,$player,EntityDamageEvent::CAUSE_ENTITY_ATTACK,3.0));
			}
		}elseif($this->repathCooldown===0){
			$this->repathCooldown=16;
			$this->zombie->getNavigation()->moveTo($player->getPosition(),0.16);
		}
	}

	/** Avoid attacking through walls even when the bounding boxes are close. */
	private function hasLineOfSight(Player $player) : bool{
		$from = $this->zombie->getEyePos();
		$to = $player->getEyePos();
		$world = $this->zombie->getWorld();
		foreach(VoxelRayTrace::betweenPoints($from, $to) as $pos){
			if(!$world->isInLoadedTerrain($pos) ||
				$world->getBlock($pos)->calculateIntercept($from, $to) !== null){
				return false;
			}
		}
		return true;
	}
	public function stop() : void{
		$this->victim=null;$this->zombie->setTargetEntity(null);
		$this->zombie->getNavigation()->stop();
	}
}
