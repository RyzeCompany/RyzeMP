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

namespace pocketmine\entity\ai\goal;

use pocketmine\entity\ai\navigation\PathBlockTypes;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\utils\Utils;
use pocketmine\world\generator\structure\AsyncStructureLocator;
use pocketmine\world\generator\structure\StructureLocators;
use pocketmine\world\World;
use function array_key_exists;
use function array_key_first;
use function atan2;
use function cos;
use function count;
use function floor;
use function in_array;
use function min;
use function mt_rand;
use function sin;
use function sqrt;
use const M_PI;

/**
 * A dolphin that was fed fish leads the way to the nearest treasure (Bedrock behavior.find_underwater_treasure, Java
 * DolphinSwimToTreasureGoal): the nearest ocean ruin, shipwreck or buried treasure within
 * {@link FindTreasureGoal::SEARCH_RADIUS_CHUNKS} chunks (Java DOLPHIN_LOCATED structures, the /locate structure
 * locator of the world). It swims there in hops of up to 16 blocks through the water, shows its treasure hunt particles
 * now and then, and stops within 4 blocks of it (horizontally), when no hop through the water is found (stuck) or when
 * it runs low on air; then the fish is used up.
 *
 * The structure search runs once per feeding, off the main thread when the locator supports it, and its results are
 * cached per world and 128-block area: the goal never searches on its own ticks.
 */
class FindTreasureGoal extends Goal{
	/** Structures dolphins lead to (Java structure tag dolphin_located), as /locate structure names. */
	public const STRUCTURES = ["ocean_ruin", "shipwreck", "buried_treasure"];
	/** Search radius (Java findNearestMapStructure(DOLPHIN_LOCATED, pos, 50, false)). */
	public const SEARCH_RADIUS_CHUNKS = 50;
	/** Horizontal distance from the treasure at which the dolphin stops. */
	public const ARRIVE_DISTANCE = 4.0;
	/** Air ticks the dolphin needs to lead (Java). */
	public const MIN_AIR_TICKS = 100;
	private const SEARCH_TIMEOUT_SECONDS = 10;
	/** Length of a hop towards the treasure, and of the shorter one tried when it fails. */
	private const HOP = 16;
	private const SHORT_HOP = 8;
	/** Ticks between two treasure hunt particle events, on average (Java 1 in 80 per tick). */
	private const PARTICLE_CHANCE = 80;
	/** Size of the cached search areas (blocks, as a shift). */
	private const CACHE_AREA_SHIFT = 7;
	private const CACHE_SIZE = 64;

	/**
	 * @var \WeakMap|null world => area key => treasure position (null: none found)
	 * @phpstan-var \WeakMap<World, array<string, Vector3|null>>|null
	 */
	private static ?\WeakMap $cache = null;
	/**
	 * @var \WeakMap|null world => area key => callbacks waiting for the running search of that area
	 * @phpstan-var \WeakMap<World, array<string, list<\Closure(?Vector3) : void>>>|null
	 */
	private static ?\WeakMap $pending = null;

	private ?Vector3 $treasure = null;
	private ?Vector3 $hop = null;
	private bool $searching = false;
	private bool $stuck = false;
	/** Increased with each start, so that a search result arriving after the goal stopped is ignored. */
	private int $run = 0;

	public function __construct(
		protected Dolphin $dolphin,
		protected float $speedMultiplier = 2.0
	){}

	public function getFlags() : int{
		return self::FLAG_MOVE | self::FLAG_LOOK;
	}

	public function isInterruptible() : bool{
		return false;
	}

	/**
	 * Returns the treasure the dolphin swims to, null while it isn't known (yet).
	 */
	public function getTreasure() : ?Vector3{
		return $this->treasure;
	}

	public function canStart() : bool{
		return $this->dolphin->hasGotFish() && $this->dolphin->isInWater() && $this->dolphin->getAirSupplyTicks() >= self::MIN_AIR_TICKS;
	}

	public function canContinue() : bool{
		if($this->stuck || !$this->dolphin->hasGotFish() || $this->dolphin->getAirSupplyTicks() < self::MIN_AIR_TICKS){
			return false;
		}
		return $this->searching || ($this->treasure !== null && !$this->isNearTreasure());
	}

	private function isNearTreasure() : bool{
		$treasure = $this->treasure;
		if($treasure === null){
			return false;
		}
		$loc = $this->dolphin->getLiveLocation();
		return ($treasure->x - $loc->x) ** 2 + ($treasure->z - $loc->z) ** 2 < self::ARRIVE_DISTANCE ** 2;
	}

	public function start() : void{
		$this->stuck = false;
		$this->treasure = null;
		$this->hop = null;
		$this->searching = true;
		$run = ++$this->run;
		$this->dolphin->getNavigation()->stop();
		self::locateTreasure($this->dolphin->getWorld(), $this->dolphin->getLiveLocation(), function(?Vector3 $treasure) use ($run) : void{
			if($run !== $this->run || !$this->searching){
				return; //stopped meanwhile
			}
			$this->searching = false;
			if($treasure === null){
				$this->stuck = true;
				return;
			}
			$this->treasure = $treasure;
			$this->broadcastTreasureHunt();
		});
	}

	public function stop() : void{
		if($this->stuck || $this->isNearTreasure()){
			$this->dolphin->setGotFish(false);
		}
		$this->searching = false;
		$this->treasure = null;
		$this->hop = null;
		$this->dolphin->getNavigation()->stop();
	}

	public function tick() : void{
		$treasure = $this->treasure;
		if($treasure === null){
			return;
		}
		$hop = $this->hop;
		$loc = $this->dolphin->getLiveLocation();
		if($hop === null || $this->dolphin->getNavigation()->isDone() || $hop->distanceSquared($loc) < 4){
			$hop = $this->findHop($treasure);
			if($hop === null){
				$this->stuck = true;
				return;
			}
			$this->hop = $hop;
			$this->dolphin->getNavigation()->moveTo($hop->x, $hop->y, $hop->z, $this->speedMultiplier);
		}
		$this->dolphin->getLookControl()->setLookAt($hop->x, $hop->y, $hop->z);
		if(mt_rand(0, self::PARTICLE_CHANCE - 1) === 0){
			$this->broadcastTreasureHunt();
		}
	}

	/**
	 * A water block up to 16 blocks towards the treasure (within 22.5 degrees of its direction, 1 block up or down),
	 * else up to 8 blocks within 90 degrees and 4 blocks up or down (Java DefaultRandomPos.getPosTowards).
	 */
	private function findHop(Vector3 $treasure) : ?Vector3{
		$loc = $this->dolphin->getLiveLocation();
		$dx = $treasure->x - $loc->x;
		$dz = $treasure->z - $loc->z;
		$distance = sqrt($dx * $dx + $dz * $dz);
		$direction = atan2($dz, $dx);
		return $this->findWaterTowards($direction, min(self::HOP, $distance), M_PI / 8, 1) ??
			$this->findWaterTowards($direction, min(self::SHORT_HOP, $distance), M_PI / 2, 4);
	}

	private function findWaterTowards(float $direction, float $maxDistance, float $spread, int $vertical) : ?Vector3{
		$loc = $this->dolphin->getLiveLocation();
		$world = $this->dolphin->getWorld();
		$baseY = (int) floor($loc->y + 0.01);
		for($try = 0; $try < 10; ++$try){
			$angle = $direction + (Utils::getRandomFloat() * 2 - 1) * $spread;
			$length = $maxDistance * (0.5 + Utils::getRandomFloat() * 0.5);
			$x = (int) floor($loc->x + cos($angle) * $length);
			$z = (int) floor($loc->z + sin($angle) * $length);
			$y = $baseY + mt_rand(-$vertical, $vertical);
			$flags = PathBlockTypes::at($world, $x, $y, $z);
			if(($flags & PathBlockTypes::WATER) !== 0 && ($flags & PathBlockTypes::PASSABLE) !== 0){
				return new Vector3($x + 0.5, $y + 0.1, $z + 0.5);
			}
		}
		return null;
	}

	private function broadcastTreasureHunt() : void{
		NetworkBroadcastUtils::broadcastPackets($this->dolphin->getViewers(), [ActorEventPacket::create($this->dolphin->getId(), ActorEvent::TREASURE_HUNT, 0, null)]);
	}

	/**
	 * Finds the nearest treasure structure ({@link FindTreasureGoal::STRUCTURES}) around the position with the world's
	 * structure locator and passes it to $onResult (null if there is none, or the world has no locator). The result
	 * comes later, on the main thread, when the locator searches off it ({@link AsyncStructureLocator}); results are
	 * cached per 128-block area, and searches of the same area are shared.
	 *
	 * @phpstan-param \Closure(?Vector3) : void $onResult
	 */
	public static function locateTreasure(World $world, Vector3 $from, \Closure $onResult) : void{
		$key = ((int) floor($from->x) >> self::CACHE_AREA_SHIFT) . ":" . ((int) floor($from->z) >> self::CACHE_AREA_SHIFT);
		self::$cache ??= new \WeakMap();
		self::$pending ??= new \WeakMap();
		$cached = self::$cache[$world] ?? [];
		if(array_key_exists($key, $cached)){
			$onResult($cached[$key] ?? null);
			return;
		}
		$waiting = self::$pending[$world] ?? [];
		if(isset($waiting[$key])){
			$waiting[$key][] = $onResult;
			self::$pending[$world] = $waiting;
			return;
		}
		$waiting[$key] = [$onResult];
		self::$pending[$world] = $waiting;

		$finish = static function(?Vector3 $treasure) use ($world, $key) : void{
			$cache = self::$cache ??= new \WeakMap();
			$entries = $cache[$world] ?? [];
			if(count($entries) >= self::CACHE_SIZE){
				unset($entries[array_key_first($entries)]);
			}
			$entries[$key] = $treasure;
			$cache[$world] = $entries;
			$pending = self::$pending ??= new \WeakMap();
			$waiting = $pending[$world] ?? [];
			$callbacks = $waiting[$key] ?? [];
			unset($waiting[$key]);
			$pending[$world] = $waiting;
			foreach($callbacks as $callback){
				$callback($treasure);
			}
		};

		$locator = StructureLocators::get($world);
		$ids = [];
		if($locator !== null){
			$known = $locator->getStructureIds();
			foreach(self::STRUCTURES as $id){
				if(in_array($id, $known, true)){
					$ids[] = $id;
				}
			}
		}
		if($locator === null || count($ids) === 0){
			$finish(null);
			return;
		}
		$origin = $from->floor();
		if(!$locator instanceof AsyncStructureLocator){
			$best = null;
			foreach($ids as $id){
				$best = self::nearer($origin, $best, $locator->findNearest($world, $id, $origin, self::SEARCH_RADIUS_CHUNKS, false));
			}
			$finish($best);
			return;
		}
		$remaining = count($ids);
		$best = null;
		foreach($ids as $id){
			$locator->findNearestAsync($world, $id, $origin, self::SEARCH_RADIUS_CHUNKS, false, self::SEARCH_TIMEOUT_SECONDS,
				static function(?Vector3 $position) use (&$remaining, &$best, $origin, $finish, $world) : void{
					$best = self::nearer($origin, $best, $position);
					if(--$remaining === 0){
						if(!$world->isLoaded()){
							return;
						}
						$finish($best);
					}
				}
			);
		}
	}

	private static function nearer(Vector3 $origin, ?Vector3 $a, ?Vector3 $b) : ?Vector3{
		if($a === null || $b === null){
			return $a ?? $b;
		}
		return ($b->x - $origin->x) ** 2 + ($b->z - $origin->z) ** 2 < ($a->x - $origin->x) ** 2 + ($a->z - $origin->z) ** 2 ? $b : $a;
	}

	/**
	 * Forgets the cached treasure searches (tests, plugins replacing a world's locator).
	 * @internal
	 */
	public static function clearCache() : void{
		self::$cache = null;
	}
}
