<?php
declare(strict_types=1);
namespace pocketmine\entity\ai\navigation;

use pocketmine\math\Vector3;
use pocketmine\world\World;
use pocketmine\utils\Utils;

/** Bounded, no-chunk-load walking A*; never treats unloaded terrain as air. */
final class GroundPathFinder{
	private const DIRECTIONS = [[1,0],[-1,0],[0,1],[0,-1]];
	private function __construct(){}
	/** @return list<Vector3> */
	public static function find(World $w,Vector3 $from,Vector3 $to,int $maxNodes=160,int $range=20) : array{
		$sx=$from->getFloorX();$sy=$from->getFloorY();$sz=$from->getFloorZ();
		$tx=$to->getFloorX();$ty=$to->getFloorY();$tz=$to->getFloorZ();
		if(abs($sx-$tx)>$range || abs($sz-$tz)>$range || abs($sy-$ty)>4){ return []; }
		if(!self::walkable($w,$sx,$sy,$sz) || !self::walkable($w,$tx,$ty,$tz)){ return []; }
		$start="$sx:$sy:$sz";$goal="$tx:$ty:$tz";
		if($start===$goal){ return []; }
		/** @var array<string,array{x:int,y:int,z:int,g:int,f:int}> $open */
		$open=[$start=>["x"=>$sx,"y"=>$sy,"z"=>$sz,"g"=>0,"f"=>0]];
		/** @var array<string,true> $closed */
		$closed=[];
		/** @var array<string,string> $parents */
		$parents=[];
		/** @var array<string,array{x:int,y:int,z:int}> $nodes */
		$nodes=[$start=>["x"=>$sx,"y"=>$sy,"z"=>$sz]];
		$visited=0;
		while($open!==[] && $visited++<max(16,min(1024,$maxNodes))){
			$best="";$score=PHP_INT_MAX;
			foreach(Utils::stringifyKeys($open) as $key=>$node){ if($node["f"]<$score){$best=$key;$score=$node["f"];} }
			if($best===""){break;}
			$current=$open[$best];unset($open[$best]);
			if($best===$goal){
				$result=[];$cursor=$goal;
				while($cursor!==$start){
					$p=$nodes[$cursor];
					$result[]=new Vector3($p["x"]+0.5,$p["y"],$p["z"]+0.5);
					$cursor=$parents[$cursor];
				}
				return array_reverse($result);
			}
			$closed[$best]=true;
			foreach(self::DIRECTIONS as [$dx,$dz]){
				$x=$current["x"]+$dx;$z=$current["z"]+$dz;
				if(abs($x-$sx)>$range || abs($z-$sz)>$range){continue;}
				foreach([0,1,-1] as $step){
					$y=$current["y"]+$step;
					if(!self::walkable($w,$x,$y,$z)){continue;}
					$key="$x:$y:$z";
					if(isset($closed[$key])){break;}
					$g=$current["g"]+10+($step!==0?4:0);
					if(isset($open[$key]) && $g>=$open[$key]["g"]){break;}
					$parents[$key]=$best;$nodes[$key]=["x"=>$x,"y"=>$y,"z"=>$z];
					$open[$key]=["x"=>$x,"y"=>$y,"z"=>$z,"g"=>$g,"f"=>$g+10*(abs($x-$tx)+abs($z-$tz)+abs($y-$ty))];
					break;
				}
			}
		}
		return [];
	}
	public static function walkable(World $w,int $x,int $y,int $z) : bool{
		if($y<=$w->getMinY() || $y+1>=$w->getMaxY() || !$w->isChunkLoaded($x>>4,$z>>4)){return false;}
		return $w->getBlockAt($x,$y-1,$z)->isSolid()
			&& !$w->getBlockAt($x,$y,$z)->isSolid()
			&& !$w->getBlockAt($x,$y+1,$z)->isSolid();
	}
}
