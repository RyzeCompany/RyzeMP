<?php
declare(strict_types=1);
namespace pocketmine\entity\ai\goal;

use pocketmine\entity\Mob;
use pocketmine\math\Vector3;

/** Low-priority wandering only through already loaded and walkable terrain. */
final class RandomStrollGoal extends Goal{
	public function __construct(private Mob $mob){}
	public function getFlags() : int{ return self::FLAG_MOVE; }
	public function canStart() : bool{
		return !$this->mob->getNavigation()->isNavigating() && mt_rand(0,15)===0;
	}
	public function canContinue() : bool{ return $this->mob->getNavigation()->isNavigating(); }
	public function start() : void{
		$at=$this->mob->getPosition();$w=$this->mob->getWorld();
		for($i=0;$i<4;++$i){
			$x=$at->getFloorX()+mt_rand(-8,8);
			$z=$at->getFloorZ()+mt_rand(-8,8);
			if(!$w->isChunkLoaded($x>>4,$z>>4)){ continue; }
			$y=$at->getFloorY();
			if(!$w->getBlockAt($x,$y-1,$z)->isSolid()){
				$y=$w->getHighestBlockAt($x,$z) ?? $y;
				++$y;
			}
			if($this->mob->getNavigation()->moveTo(new Vector3($x+0.5,$y,$z+0.5),0.11)){return;}
		}
	}
	public function stop() : void{ $this->mob->getNavigation()->stop(); }
}
