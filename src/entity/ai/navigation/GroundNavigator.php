<?php
declare(strict_types=1);
namespace pocketmine\entity\ai\navigation;

use pocketmine\entity\Mob;
use pocketmine\entity\ai\AiBudget;
use pocketmine\math\Vector3;

/** Waypoint movement, chunk-boundary guard, obstacle recovery, and fairness. */
final class GroundNavigator{
	/** @var list<Vector3> */
	private array $path=[];
	private int $index=0;
	private int $waitingSince=-1;
	private float $speed=0.12;
	private int $stuckTicks=0;
	private ?Vector3 $previous=null;
	public function __construct(private Mob $mob){}
	public function stop() : void{
		$this->path=[];$this->index=0;$this->stuckTicks=0;
		$this->mob->setMotion($this->mob->getMotion()->withComponents(0.0,null,0.0));
	}
	public function isNavigating() : bool{return isset($this->path[$this->index]);}
	public function moveTo(Vector3 $target,float $speed=0.12) : bool{
		$w=$this->mob->getWorld();
		if(!$w->isInLoadedTerrain($target) || !AiBudget::takePathSearch($w->getServer()->getTick(),$this->waitingSince)){return false;}
		$path=GroundPathFinder::find($w,$this->mob->getPosition(),$target,AiBudget::getPathNodeLimit());
		if($path===[]){return false;}
		$this->path=$path;$this->index=0;$this->previous=null;$this->stuckTicks=0;$this->speed=$speed;
		return true;
	}
	public function tick() : void{
		if(!$this->isNavigating()){return;}
		$at=$this->mob->getPosition();$next=$this->path[$this->index];
		if(!$this->mob->getWorld()->isInLoadedTerrain($next)){ $this->stop(); return; }
		$dx=$next->x-$at->x;$dz=$next->z-$at->z;$dist=sqrt($dx*$dx+$dz*$dz);
		if($dist<0.5){++$this->index;if(!$this->isNavigating()){$this->stop();}return;}
		if($this->previous!==null && $this->previous->distanceSquared($at)<0.0025){++$this->stuckTicks;}
		else{$this->stuckTicks=0;}
		if($this->stuckTicks>=40){$this->stop();return;}
		$this->previous=$at->asVector3();
		$vy=$this->mob->getMotion()->y;
		if($next->y>$at->y+0.35 && $this->mob->isOnGround()){
			$this->mob->jump();$vy=$this->mob->getMotion()->y;
		}
		$this->mob->setMotion(new Vector3($dx/$dist*$this->speed,$vy,$dz/$dist*$this->speed));
	}
}
