<?php
declare(strict_types=1);

namespace pocketmine\world\mobs;

use pocketmine\block\Water;
use pocketmine\data\bedrock\LegacyBiomeIdToStringIdMap;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\Chicken;
use pocketmine\entity\animal\Cow;
use pocketmine\entity\animal\Pig;
use pocketmine\entity\animal\Sheep;
use pocketmine\entity\ai\navigation\GroundPathFinder;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\SpawnerEntityRegistry;
use pocketmine\entity\Skeleton;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\Squid;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityNaturalSpawnEvent;
use pocketmine\event\entity\MobPreSpawnEvent;
use pocketmine\player\Player;
use pocketmine\world\GameRules;
use pocketmine\world\mobs\spawn\NaturalSpawnPolicy as Policy;
use pocketmine\world\World;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\math\Vector3;

/**
 * Conservative Bedrock-style natural population around loaded players.
 *
 * No async tasks, chunk loads or terrain population are triggered. Work, player
 * distance and group populations are bounded. All spawned mobs may be vetoed
 * by PM5 plugins BEFORE and AFTER entity creation.
 *
 * This initial slice supports cows, sheep, pigs, chickens, zombies and squid.
 * It does not pretend to provide all VanillaMine biome/structure spawn rules.
 */
final class NaturalMobSpawner{
	/** @var \WeakMap<Living, int> Naturally created mob => ticks spent far from players */
	private \WeakMap $natural;
	private int $attempted = 0;
	private int $spawned = 0;

	public function __construct(
		private World $world,
		private bool $enabled,
		private int $maxTotal = Policy::DEFAULT_TOTAL_CAP,
		private int $maxMonsters = Policy::DEFAULT_MONSTER_CAP,
		private int $maxAnimals = Policy::DEFAULT_ANIMAL_CAP,
		private int $maxWater = Policy::DEFAULT_WATER_CAP,
		private int $interval = 20,
		private int $maxAttempts = 6
	){
		$this->natural = new \WeakMap();
	}

	public function getAttemptCount() : int{ return $this->attempted; }
	public function getSpawnCount() : int{ return $this->spawned; }

	public function tick(int $currentTick) : void{
		if(!$this->enabled || $currentTick % max(1,$this->interval)!==0){return;}
		$this->despawnFar();
		$w = $this->world;
		if(!$w->getGameRules()->getBool(GameRules::DO_MOB_SPAWNING)){
			return;
		}
		// Nether and End entity lists are not yet ported; do not spawn
		// Overworld animals in non-Overworld dimensions.
		if($w->getServer()->getDimensionManager()->getDimension($w) !== DimensionIds::OVERWORLD){
			return;
		}
		$players = [];
		foreach($w->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator() && !$player->isCreative()){
				$players[] = $player;
			}
		}
		if($players === []){return;}

		$counts = ["total"=>0,"monster"=>0,"animal"=>0,"water"=>0];
		/** @var array<string, int> $populationByChunk */
		$populationByChunk = [];
		foreach($w->getEntities() as $entity){
			if($entity->isClosed() || $entity->isFlaggedForDespawn()){continue;}
			if($entity instanceof Zombie || $entity instanceof Skeleton){ ++$counts["monster"]; }
			elseif($entity instanceof Animal){ ++$counts["animal"]; }
			elseif($entity instanceof Squid){ ++$counts["water"]; }
			else{ continue; }
			++$counts["total"];
			$entityPosition = $entity->getPosition();
			$key = ($entityPosition->getFloorX() >> 4) . ":" . ($entityPosition->getFloorZ() >> 4);
			$populationByChunk[$key] = ($populationByChunk[$key] ?? 0) + 1;
		}
		if(!Policy::underCap($counts["total"],$this->maxTotal)){return;}
		$created = 0;
		for($i=0;$i<max(0,min(32,$this->maxAttempts)) && $created<2;++$i){
			++$this->attempted;
			$player = $players[mt_rand(0,count($players)-1)];
			$p=$player->getPosition();
			$x=$p->getFloorX()+mt_rand(-48,48);
			$z=$p->getFloorZ()+mt_rand(-48,48);
			$distance=($x+0.5-$p->x)**2+($z+0.5-$p->z)**2;
			if(!Policy::withinPlayerRange($distance)){continue;}
			$chunk=$w->getChunk($x>>4,$z>>4);
			if($chunk===null || !$chunk->isPopulated() || $chunk->isLightPopulated()!==true ||
				$w->isChunkLocked($x>>4,$z>>4)){
				continue;
			}
			$spawn=$w->getSpawnLocation();
			if(($x-$spawn->x)**2+($z-$spawn->z)**2<16**2){continue;}
			// Keep the 9x9 chunk neighbourhood from becoming saturated even
			// when several players gather in the same area.
			if(Policy::populationInChunkRadius($populationByChunk, $x >> 4, $z >> 4) >= 32){
				continue;
			}
			$height=$w->getHighestBlockAt($x,$z);
			if($height===null || $height<$w->getMinY()+2 || $height>$w->getMaxY()-3){continue;}

			$isWater=$w->getBlockAt($x,$height,$z) instanceof Water;
			if($isWater){
				if(!Policy::underCap($counts["water"],$this->maxWater)){continue;}
				$y=$height-2;
				if(!($w->getBlockAt($x,$y,$z) instanceof Water)){continue;}
				$biome = LegacyBiomeIdToStringIdMap::getInstance()->legacyToString(
					$chunk->getBiomeId($x & 15, $y, $z & 15)
				);
				if(!Policy::canSpawnSquidBiome($biome)){ continue; }
				$identifier=Squid::getNetworkTypeId();
				$surface=true;$group="water";
			}else{
				$underground=mt_rand(0,3)===0;
				if($underground){
					$maxY=$height-3;
					if($maxY<=$w->getMinY()+2){continue;}
					$y=mt_rand($w->getMinY()+2,$maxY);
				}else{
					$y=$height+1;
				}
				// Share the pathfinder's two-block clearance and hazard checks:
				// no spawn inside lava, fire, berries or on unsafe footing.
				if(!$w->isInWorld($x,$y,$z) || !GroundPathFinder::walkable($w,$x,$y,$z)){
					continue;
				}
				$light=$w->getFullLightAt($x,$y,$z);
				if(Policy::canSpawnZombie($w->getDifficulty(),$light) &&
					Policy::underCap($counts["monster"],$this->maxMonsters)){
					$identifier = mt_rand(0, 3) === 0 ? Skeleton::getNetworkTypeId() : Zombie::getNetworkTypeId();
					$surface=!$underground;$group="monster";
				}elseif(!$underground && Policy::canSpawnAnimal($light) &&
					Policy::underCap($counts["animal"],$this->maxAnimals) &&
					Policy::canSpawnFarmAnimalBiome(LegacyBiomeIdToStringIdMap::getInstance()->legacyToString(
						$chunk->getBiomeId($x & 15, $y, $z & 15)
					))){
					$class=match(mt_rand(0,3)){
						0=>Cow::class,1=>Pig::class,2=>Sheep::class,default=>Chicken::class
					};
					$identifier=$class::getNetworkTypeId();$surface=true;$group="animal";
				}else{continue;}
			}

			// The range is measured in 3D against every eligible player. Never
			// spawn inside another player's safe radius in a mixed session.
			$canSpawnNearPlayer = false;
			$spawnPosition = new Vector3($x + 0.5, $y, $z + 0.5);
			foreach($players as $viewer){
				$actualDistance = $viewer->getPosition()->distanceSquared($spawnPosition);
				if($actualDistance < Policy::MIN_PLAYER_DISTANCE ** 2){
					$canSpawnNearPlayer = false;
					break;
				}
				if(Policy::withinPlayerRange($actualDistance)){
					$canSpawnNearPlayer = true;
				}
			}
			if(!$canSpawnNearPlayer){ continue; }
			$location=new Location($x+0.5,$y,$z+0.5,$w,0.0,0.0);
			if(MobPreSpawnEvent::hasHandlers()){
				$pre=new MobPreSpawnEvent($identifier,$location,SpawnReason::NATURAL);
				$pre->call();
				if($pre->isCancelled()){continue;}
			}
			$mob=SpawnerEntityRegistry::getInstance()->spawn($identifier,$location);
			if(!$mob instanceof Living){
				$mob?->close();
				continue;
			}
			$mob->setCanSaveWithChunk(false);
			if(EntityNaturalSpawnEvent::hasHandlers()){
				$event=new EntityNaturalSpawnEvent($mob,EntityNaturalSpawnEvent::CAUSE_NATURAL,$surface);
				$event->call();
				if($event->isCancelled()){
					$mob->close();
					continue;
				}
			}
			$mob->spawnToAll();
			$this->natural[$mob]=0;
			++$counts["total"];++$counts[$group];
			$key = ($x >> 4) . ":" . ($z >> 4);
			$populationByChunk[$key] = ($populationByChunk[$key] ?? 0) + 1;
			++$created;++$this->spawned;
		}
	}

	private function despawnFar() : void{
		$players=$this->world->getPlayers();
		foreach($this->natural as $mob=>$farTicks){
			if($mob->isClosed() || $mob->isFlaggedForDespawn()){
				unset($this->natural[$mob]);continue;
			}
			$nearest=PHP_INT_MAX;
			foreach($players as $player){
				if($player->isSpectator()){continue;}
				$nearest=min($nearest,$mob->getPosition()->distanceSquared($player->getPosition()));
			}
			$instant=$this->world->getChunkTickRadius()<=4?48:128;
			if($nearest>$instant*$instant){
				$mob->flagForDespawn();
				unset($this->natural[$mob]);
				continue;
			}
			$farTicks=$nearest>32*32?$farTicks+max(1,$this->interval):0;
			if($farTicks>=600 && mt_rand(0,39)===0){
				$mob->flagForDespawn();
				unset($this->natural[$mob]);
			}else{
				$this->natural[$mob]=$farTicks;
			}
		}
	}
}
