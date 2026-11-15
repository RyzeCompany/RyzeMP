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

namespace pocketmine\world\dimension;

use pocketmine\block\tile\EndGateway as TileEndGateway;
use pocketmine\entity\Entity;
use pocketmine\entity\Mob;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\promise\Promise;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use function abs;
use function floor;
use function max;
use function min;

/**
 * Takes entities through End gateways (and the owners of ender pearls thrown into one) to the gateway's exit. Players
 * wait 2 seconds before using a gateway again; other entities (items, mobs, minecarts... see
 * {@link PortalTravel::canTravel()}) share the portal cooldown of {@link PortalTravel}, at least as long.
 *
 * A gateway without an exit in the End (the ones the dragon fight spawns) finds one the first time it is used, see
 * {@link EndGateways}; the exit gateway built there leads back. A gateway without block entity data (the return
 * gateways the vanilla generator places on the outer islands) leads to the End spawn platform, like vanilla's
 * end_gateway_return.
 */
final class EndGatewayTravel{
	use SingletonTrait;

	/** Ticks an entity must wait before using a gateway again (vanilla's gateway cooldown). */
	public const COOLDOWN_TICKS = 40;
	/** Gateways without block entity data at least this far from 0,0 lead to the End spawn platform. */
	public const RETURN_GATEWAY_MIN_DISTANCE = 256;
	/** Exit searches give up after populating this many chunks. */
	private const MAX_SEARCH_CHUNKS = 64;

	/** @phpstan-var \WeakMap<Entity, int> entity => server tick it may use a gateway again */
	private \WeakMap $cooldowns;
	/** @phpstan-var array<string, true> world ID:block hash => gateways looking for their exit */
	private array $searching = [];

	public function __construct(){
		$this->cooldowns = new \WeakMap();
	}

	/**
	 * Called every tick an entity is inside a gateway block.
	 */
	public function onEntityInsideGateway(Entity $entity, Vector3 $gatewayPos) : void{
		$world = $entity->getWorld();
		$traveller = $entity;
		if($entity instanceof EnderPearl){
			$owner = $entity->getOwningEntity();
			if(!$owner instanceof Player || $owner->getWorld() !== $world){
				return;
			}
			$entity->flagForDespawn();
			$traveller = $owner;
		}
		$tick = $world->getServer()->getTick();
		if($traveller instanceof Player){
			if(!$traveller->isAlive() || !$traveller->isConnected() || ($this->cooldowns[$traveller] ?? 0) > $tick){
				return;
			}
			$this->cooldowns[$traveller] = $tick + self::COOLDOWN_TICKS;
		}elseif(!PortalTravel::getInstance()->mayUseGateway($traveller)){
			//other entities share the portal cooldown, see PortalTravel
			return;
		}else{
			//vanilla Entity.handlePortal() starts the cooldown before the trip
			PortalTravel::getInstance()->setPortalCooldown($traveller, self::getEntityCooldownTicks($traveller));
		}

		$gatewayPos = $gatewayPos->floor();
		$tile = $world->getTile($gatewayPos);
		if($tile instanceof TileEndGateway){
			$exit = $tile->getExitPortal();
			$exact = $tile->isExactTeleport();
		}elseif(max(abs($gatewayPos->x), abs($gatewayPos->z)) >= self::RETURN_GATEWAY_MIN_DISTANCE){
			$exit = new Vector3(EndSpawnPlatform::X, EndSpawnPlatform::Y + 1, EndSpawnPlatform::Z);
			$exact = true;
		}else{
			$exit = null;
			$exact = false;
		}

		if($exit !== null){
			$this->teleport($traveller, $world, $exit, $exact);
		}elseif($world->getDimension() === Dimension::END){
			$key = $world->getId() . ":" . World::blockHash($gatewayPos->getFloorX(), $gatewayPos->getFloorY(), $gatewayPos->getFloorZ());
			if(isset($this->searching[$key])){
				return; //someone else's search will give it an exit
			}
			$this->searching[$key] = true;
			if($traveller instanceof Player){
				$this->cooldowns[$traveller] = $tick + 20 * 60; //until the search is over
			}
			$this->searchExit($world, $gatewayPos, function(?Vector3 $exit) use ($traveller, $world, $key) : void{
				unset($this->searching[$key]);
				if($traveller instanceof Player){
					$this->cooldowns[$traveller] = $world->getServer()->getTick() + self::COOLDOWN_TICKS;
				}
				if($exit !== null && self::canStillTravel($traveller, $world)){
					$this->teleport($traveller, $world, $exit, false);
				}
			});
		}
	}

	private static function canStillTravel(Entity $entity, World $world) : bool{
		if($entity instanceof Player){
			return $entity->isConnected() && $entity->isAlive() && $entity->getWorld() === $world;
		}
		return !$entity->isClosed() && $entity->getWorld() === $world && PortalTravel::canTravel($entity);
	}

	/**
	 * Portal cooldown of a non-player entity which used a gateway: its own (see {@link PortalTravel}), at least the
	 * gateway's.
	 */
	private static function getEntityCooldownTicks(Entity $entity) : int{
		return max(self::COOLDOWN_TICKS, PortalTravel::getCooldownTicks($entity));
	}

	/**
	 * Teleports an entity to a gateway exit, loading the chunks around it first. Entities other than players keep their
	 * motion (vanilla's gateway transition keeps it) and start their portal cooldown again on arrival.
	 */
	private function teleport(Entity $entity, World $world, Vector3 $exit, bool $exact) : void{
		$r = EndGateways::ARRIVAL_RADIUS;
		$this->withChunks($world, $exit->getFloorX() - $r, $exit->getFloorZ() - $r, $exit->getFloorX() + $r, $exit->getFloorZ() + $r, function() use ($entity, $world, $exit, $exact) : void{
			if(!self::canStillTravel($entity, $world)){
				return;
			}
			$arrival = $exact ? new Vector3($exit->getFloorX() + 0.5, $exit->getFloorY(), $exit->getFloorZ() + 0.5) : EndGateways::getArrivalPosition($world, $exit);
			if($entity instanceof Player){
				$entity->teleport($arrival);
				$this->cooldowns[$entity] = $world->getServer()->getTick() + self::COOLDOWN_TICKS;
				return;
			}
			$motion = $entity->getMotion();
			if($entity->teleport($arrival)){
				$entity->setMotion($motion);
				if($entity instanceof Mob){
					//its path led somewhere around the gateway it came from
					$entity->getNavigation()->stop();
				}
			}
			PortalTravel::getInstance()->setPortalCooldown($entity, self::getEntityCooldownTicks($entity));
		});
	}

	/**
	 * Finds (and builds) the exit of a gateway without one, then stores it in the gateway and calls $onDone with it
	 * (null if the search failed). Chunks the search needs are generated as it goes.
	 *
	 * @phpstan-param \Closure(?Vector3) : void $onDone
	 */
	private function searchExit(World $world, Vector3 $gateway, \Closure $onDone) : void{
		$loader = new class implements ChunkLoader{};
		/** @phpstan-var list<array{int, int}> $held chunks kept loaded for the search */
		$held = [];
		$release = function() use ($world, $loader, &$held) : void{
			if($world->isLoaded()){
				foreach($held as [$cx, $cz]){
					$world->unregisterChunkLoader($loader, $cx, $cz);
				}
			}
			$held = [];
		};
		$step = function(int $populated) use ($world, $gateway, $loader, $release, $onDone, &$held, &$step) : void{
			if(!$world->isLoaded()){
				$onDone(null);
				return;
			}
			[$found, $a, $b] = EndGateways::findExitXZ($gateway, function(int $cx, int $cz) use ($world) : ?bool{
				if(!$world->isChunkPopulated($cx, $cz)){
					return null;
				}
				$chunk = $world->loadChunk($cx, $cz);
				return $chunk === null ? null : EndGateways::isChunkEmpty($chunk);
			});
			if(!$found){
				if($populated >= self::MAX_SEARCH_CHUNKS){
					$release();
					$onDone(null);
					return;
				}
				$world->registerChunkLoader($loader, (int) $a, (int) $b);
				$held[] = [(int) $a, (int) $b];
				$world->requestChunkPopulation((int) $a, (int) $b, $loader)->onCompletion(
					fn() => $step($populated + 1),
					function() use ($release, $onDone) : void{
						$release();
						$onDone(null);
					}
				);
				return;
			}
			$x = (float) $a;
			$z = (float) $b;
			$chunkX = (int) floor($x / 16.0);
			$chunkZ = (int) floor($z / 16.0);
			$spawn = EndGateways::findValidSpawnInChunk($world, $chunkX, $chunkZ);
			$island = $spawn === null;
			$spawn ??= new Vector3((int) floor($x + 0.5), 75, (int) floor($z + 0.5));
			$r = EndGateways::EXIT_TALLEST_RADIUS + 1;
			$this->withChunks($world, $spawn->getFloorX() - $r, $spawn->getFloorZ() - $r, $spawn->getFloorX() + $r, $spawn->getFloorZ() + $r, function() use ($world, $gateway, $spawn, $island, $release, $onDone) : void{
				$release();
				if($island){
					EndGateways::placeIsland($world, $spawn);
				}
				$exit = EndGateways::findTallestBlock($world, $spawn, EndGateways::EXIT_TALLEST_RADIUS, true)->add(0, 10, 0);
				$exit = new Vector3($exit->x, min($exit->y, $world->getMaxY() - 3), $exit->z);
				EndGateways::build($world, $exit, $gateway, false);
				$tile = $world->getTile($gateway);
				if($tile instanceof TileEndGateway){
					$tile->setExitPortal($exit, false);
				}
				\GlobalLogger::get()->debug("End gateway at $gateway leads to $exit");
				$onDone($exit);
			}, function() use ($release, $onDone) : void{
				$release();
				$onDone(null);
			});
		};
		$step(0);
	}

	/**
	 * Loads (generating if needed) the chunks covering the given block area, runs $then while they are loaded and
	 * releases them.
	 *
	 * @phpstan-param \Closure() : void $then
	 * @phpstan-param (\Closure() : void)|null $onFailure
	 */
	private function withChunks(World $world, int $minX, int $minZ, int $maxX, int $maxZ, \Closure $then, ?\Closure $onFailure = null) : void{
		$loader = new class implements ChunkLoader{};
		$promises = [];
		for($cx = $minX >> Chunk::COORD_BIT_SIZE; $cx <= $maxX >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = $minZ >> Chunk::COORD_BIT_SIZE; $cz <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$cz){
				$world->registerChunkLoader($loader, $cx, $cz);
				$promises[] = $world->requestChunkPopulation($cx, $cz, $loader);
			}
		}
		$release = function() use ($world, $loader, $minX, $minZ, $maxX, $maxZ) : void{
			if(!$world->isLoaded()){
				return;
			}
			for($cx = $minX >> Chunk::COORD_BIT_SIZE; $cx <= $maxX >> Chunk::COORD_BIT_SIZE; ++$cx){
				for($cz = $minZ >> Chunk::COORD_BIT_SIZE; $cz <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$cz){
					$world->unregisterChunkLoader($loader, $cx, $cz);
				}
			}
		};
		Promise::all($promises)->onCompletion(
			function() use ($world, $then, $release) : void{
				try{
					if($world->isLoaded()){
						$then();
					}
				}finally{
					$release();
				}
			},
			function() use ($release, $onFailure) : void{
				$release();
				if($onFailure !== null){
					$onFailure();
				}
			}
		);
	}
}
