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

namespace pocketmine\world\mobs;

use pocketmine\block\Leaves;
use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\animal\TraderLlama;
use pocketmine\entity\Location;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\entity\SpawnReason;
use pocketmine\event\entity\EntityNaturalSpawnEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;
use pocketmine\world\raid\PoiType;
use pocketmine\world\World;
use function count;
use function min;
use function mt_rand;

/**
 * Spawns wandering traders (https://minecraft.wiki/w/Wandering_Trader#Spawning):
 * - every 24000 ticks (one day) an attempt is made, which goes ahead 25% of the time, then 50%, then 75% after
 *   failures (back to 25% after a trader spawns);
 * - a random player is picked, and only 1 attempt in 10 goes on from there;
 * - the trader spawns on the top block of a random column within 48 blocks of the bell nearest to the player (within
 *   48 blocks), or of the player if there is none, with two trader llamas on its leads (Bedrock, see
 *   {@link WanderingTrader::spawnTraderLlamas()});
 * - it despawns after 2 or 3 days once nobody is within 24 blocks (see {@link WanderingTrader::setDespawnDelay()}).
 *
 * Overworld only. The countdown is not saved: it starts again when the world is loaded.
 */
final class WanderingTraderSpawner{
	public const DELAY = 24000;
	public const MIN_CHANCE = 25;
	public const MAX_CHANCE = 75;
	/** 1 in this many attempts that went ahead spawn a trader. */
	public const TRADER_ROLL = 10;
	public const BELL_RANGE = 48;
	public const SPAWN_RANGE = 48;
	public const DESPAWN_DELAYS = [48000, 72000];
	private const POSITION_ATTEMPTS = 10;

	private int $delay = self::DELAY;
	private int $chance = self::MIN_CHANCE;

	public function __construct(
		private World $world
	){}

	public function getDelay() : int{
		return $this->delay;
	}

	public function getChance() : int{
		return $this->chance;
	}

	public function tick() : void{
		if(--$this->delay > 0){
			return;
		}
		$this->delay = self::DELAY;
		if($this->world->getDimension() !== Dimension::OVERWORLD){
			return;
		}
		$chance = $this->chance;
		$this->chance = min(self::MAX_CHANCE, $this->chance + self::MIN_CHANCE);
		if(mt_rand(0, 99) >= $chance){
			return;
		}
		$players = [];
		foreach($this->world->getPlayers() as $player){
			if($player->isAlive() && !$player->isSpectator()){
				$players[] = $player;
			}
		}
		if(count($players) === 0 || mt_rand(0, self::TRADER_ROLL - 1) !== 0){
			return;
		}
		if($this->spawnNear($players[mt_rand(0, count($players) - 1)]) !== null){
			$this->chance = self::MIN_CHANCE;
		}
	}

	/**
	 * Tries to spawn a trader and its llamas near the player (or the nearest bell within 48 blocks). Returns the
	 * trader, or null if no spot was found.
	 */
	public function spawnNear(Player $player) : ?WanderingTrader{
		$target = $this->findBell($player->getPosition()) ?? $player->getPosition();
		for($i = 0; $i < self::POSITION_ATTEMPTS; ++$i){
			$x = (int) $target->x + mt_rand(-self::SPAWN_RANGE, self::SPAWN_RANGE);
			$z = (int) $target->z + mt_rand(-self::SPAWN_RANGE, self::SPAWN_RANGE);
			$y = $this->findSurface($x, $z);
			if($y === null){
				continue;
			}
			$location = new Location($x + 0.5, $y, $z + 0.5, $this->world, mt_rand(0, 359), 0);
			$trader = new WanderingTrader($location);
			$trader->setDespawnDelay(self::DESPAWN_DELAYS[mt_rand(0, count(self::DESPAWN_DELAYS) - 1)]);
			$trader->setSpawnReason(SpawnReason::WANDERING_TRADER);
			$ev = new EntityNaturalSpawnEvent($trader, EntityNaturalSpawnEvent::CAUSE_WANDERING_TRADER, true);
			$ev->call();
			if($ev->isCancelled() || $trader->isClosed()){
				$trader->close();
				return null;
			}
			$trader->spawnToAll();
			$trader->spawnTraderLlamas(function(TraderLlama $llama) : bool{
				$ev = new EntityNaturalSpawnEvent($llama, EntityNaturalSpawnEvent::CAUSE_WANDERING_TRADER, true);
				$ev->call();
				return !$ev->isCancelled();
			});
			return $trader;
		}
		return null;
	}

	private function findBell(Vector3 $pos) : ?Vector3{
		$x = (int) $pos->x;
		$y = (int) $pos->y;
		$z = (int) $pos->z;
		$best = null;
		$bestDistance = 0.0;
		$range = self::BELL_RANGE;
		foreach($this->world->getPoiManager()->getPois($x - $range, $y - $range, $z - $range, $x + $range, $y + $range, $z + $range) as [$type, $poiPos]){
			if($type !== PoiType::BELL){
				continue;
			}
			$d = $poiPos->distanceSquared($pos);
			if($d <= $range ** 2 && ($best === null || $d < $bestDistance)){
				$best = $poiPos;
				$bestDistance = $d;
			}
		}
		return $best;
	}

	private function findSurface(int $x, int $z) : ?int{
		$chunk = $this->world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if($chunk === null || $chunk->isLightPopulated() !== true){
			return null;
		}
		$top = $this->world->getHighestBlockAt($x, $z);
		if($top === null || $top + 2 >= $this->world->getMaxY() || $this->world->getBlockAt($x, $top, $z) instanceof Leaves){
			return null;
		}
		return PathBlockTypes::isStandable($this->world, $x, $top + 1, $z, 1, false) ? $top + 1 : null;
	}
}
