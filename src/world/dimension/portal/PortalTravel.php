<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\portal;

use Closure;
use pocketmine\world\dimension\Dimensions;
use pocketmine\block\NetherPortal;
use pocketmine\math\Vector3;
use pocketmine\promise\Promise;
use pocketmine\world\dimension\DimensionSettings;
use pocketmine\world\dimension\portal\block\EndPortal;
use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\Position;
use pocketmine\world\World;
use function abs;
use function floor;
use function ceil;
use function max;
use function min;

/**
 * Moves entities standing in portal blocks between dimensions.
 *
 * Nether portals send an entity after it spent the portal delay inside them
 * (a single tick in creative) and do not send it again until it has left
 * every portal block. End portals send an entity as soon as it enters them.
 */
final class PortalTravel{

	public const NETHER_PORTAL_DELAY = 80;
	public const NETHER_SCALE = 8;
	public const WORLD_LIMIT = 29999872;

	public const END_SPAWN_X = 100;
	public const END_SPAWN_Y = 49;
	public const END_SPAWN_Z = 0;

	/** @var array<int, int> */
	private array $netherPortalTicks = [];
	/** @var array<int, true> */
	private array $inEndPortal = [];
	/** @var array<int, array{token: int, entity: Entity, version: int, source: World, nether: bool, deadline: int, link: ?PortalLink}> */
	private array $pending = [];
	private int $requestSequence = 0;
	private int $currentTick = 0;
	private DimensionSettings $settings;
	private PortalLinkManager $portalLinks;
	/** @var array<int, array{entity: Entity, outsideTicks: int}> */
	private array $linkedArrivals = [];
	/** @var array<int, Entity> */
	private array $netherTouches = [];
	/** @var array<int, Entity> */
	private array $endTouches = [];

	public function __construct(
		private Dimensions $dimensions,
		?DimensionSettings $settings = null,
		?PortalLinkManager $portalLinks = null
	){
		$this->settings = $settings ?? DimensionSettings::fromArray([]);
		$this->portalLinks = $portalLinks ?? new PortalLinkManager();
	}

	public function tick() : void{
		++$this->currentTick;
		foreach($this->linkedArrivals as $id => &$arrival){
			$entity = $arrival["entity"];
			if(!$this->canTravel($entity)){
				unset($this->linkedArrivals[$id]);
			}elseif($this->isTouchingPortal($entity, true) || $this->isTouchingPortal($entity, false)){
				$arrival["outsideTicks"] = 0;
			}elseif(++$arrival["outsideTicks"] >= 2){
				unset($this->linkedArrivals[$id]);
			}
		}
		unset($arrival);
		foreach($this->pending as $id => $request){
			if(!$this->requestIsValid($request)){
				unset($this->pending[$id]);
			}
		}
		foreach($this->netherTouches as $id => $entity){
			if(isset($this->linkedArrivals[$id])){
				continue;
			}
			if(!$this->canTravel($entity)){
				$this->forget($id);
				continue;
			}
			$ticks = $this->netherPortalTicks[$id] ?? 0;
			if($entity instanceof Player && $entity->isCreative() && $ticks < self::NETHER_PORTAL_DELAY){
				$ticks = self::NETHER_PORTAL_DELAY;
			}else{
				$ticks = min($ticks + 1, self::NETHER_PORTAL_DELAY + 1);
			}
			$this->netherPortalTicks[$id] = $ticks;
			if($ticks === self::NETHER_PORTAL_DELAY && !isset($this->pending[$id])){
				$this->travelThroughNetherPortal($entity);
			}
		}

		foreach(array_keys($this->netherPortalTicks) as $id){
			if(!isset($this->netherTouches[$id])){
				unset($this->netherPortalTicks[$id]);
			}
		}

		foreach($this->endTouches as $id => $entity){
			if(isset($this->linkedArrivals[$id])){
				continue;
			}
			if(!$this->canTravel($entity)){
				$this->forget($id);
				continue;
			}
			if(!isset($this->inEndPortal[$id])){
				$this->inEndPortal[$id] = true;
				if(!isset($this->pending[$id])){
					$this->travelThroughEndPortal($entity);
				}
			}
		}
		foreach(array_keys($this->inEndPortal) as $id){
			if(!isset($this->endTouches[$id])){
				unset($this->inEndPortal[$id]);
			}
		}

		$this->netherTouches = [];
		$this->endTouches = [];
	}

	public function touchNetherPortal(Entity $entity) : void{
		$this->netherTouches[$entity->getId()] = $entity;
	}

	public function touchEndPortal(Entity $entity) : void{
		$this->endTouches[$entity->getId()] = $entity;
	}

	public function cancelPending(int $entityId) : void{
		unset($this->pending[$entityId]);
	}

	public function forget(int $entityId) : void{
		unset(
			$this->netherPortalTicks[$entityId],
			$this->inEndPortal[$entityId],
			$this->pending[$entityId],
			$this->linkedArrivals[$entityId],
			$this->netherTouches[$entityId],
			$this->endTouches[$entityId]
		);
	}

	private function canTravel(Entity $entity) : bool{
		if($entity->isClosed() || $entity->isFlaggedForDespawn() || !$entity->isAlive()){
			return false;
		}
		if($entity instanceof Player){
			return $entity->isConnected() && $entity->spawned && !$entity->isSpectator();
		}
		return true;
	}


	private function travelThroughNetherPortal(Entity $entity) : void{
		if($this->tryLinkedTravel($entity, true)){
			return;
		}
		$from = $this->dimensions->getDimension($entity->getWorld());
		if($from === DimensionIds::OVERWORLD){
			$to = DimensionIds::NETHER;
		}elseif($from === DimensionIds::NETHER){
			$to = DimensionIds::OVERWORLD;
		}else{
			return;
		}
		if(!$this->dimensions->isEnabled($to)){
			return;
		}
		$target = $this->dimensions->getWorld($to);
		if($target === null || $target === $entity->getWorld()){
			return;
		}

		$position = $entity->getPosition();
		if($to === DimensionIds::NETHER){
			$x = (int) floor($position->x / self::NETHER_SCALE);
			$z = (int) floor($position->z / self::NETHER_SCALE);
		}else{
			$x = (int) floor($position->x) * self::NETHER_SCALE;
			$z = (int) floor($position->z) * self::NETHER_SCALE;
		}
		$x = max(-self::WORLD_LIMIT, min(self::WORLD_LIMIT, $x));
		$z = max(-self::WORLD_LIMIT, min(self::WORLD_LIMIT, $z));
		$this->populate($entity, $target, $x, $z, true, 3, function() use($entity, $target, $to, $x, $z) : void{
			$y = NetherPortalLocator::findBaseY($target, $to, $x, $z);
			$portal = NetherPortalLocator::findNearest($target, $to, $x, $y, $z);
			if($portal !== null){
				$destination = $portal->add(0.5, 0, 0.5);
			}else{
				$destination = NetherPortalLocator::build($target, $x, $y, $z);
			}
			if($entity->teleport(Position::fromObject($destination, $target))){
				$this->netherPortalTicks[$entity->getId()] = self::NETHER_PORTAL_DELAY + 1;
			}
		});
	}

	private function travelThroughEndPortal(Entity $entity) : void{
		if($this->tryLinkedTravel($entity, false)){
			return;
		}
		$from = $this->dimensions->getDimension($entity->getWorld());
		if(!$this->dimensions->isEnabled(DimensionIds::THE_END)){
			return;
		}
		if($from === DimensionIds::OVERWORLD){
			$target = $this->dimensions->getWorld(DimensionIds::THE_END);
			if($target === null || $target === $entity->getWorld()){
				return;
			}
			$preferred = $this->settings->endUseWorldSpawn
				? $target->getSpawnLocation()
				: new Vector3($this->settings->endX, $this->settings->endY, $this->settings->endZ);
			if(abs($preferred->x) > self::WORLD_LIMIT || abs($preferred->z) > self::WORLD_LIMIT || $preferred->y < 1 || $preferred->y > 253){
				$target->getServer()->getLogger()->warning("End arrival is outside the supported dimension bounds; portal travel cancelled");
				return;
			}
			$this->populate($entity, $target, $preferred->getFloorX(), $preferred->getFloorZ(), false, EndArrival::SEARCH_RADIUS + 2, function() use($entity, $target, $preferred) : void{
				$destination = EndArrival::findOrCreate($target, $preferred, $this->settings->endCreatePlatform);
				if($destination === null){
					$target->getServer()->getLogger()->warning("No safe End arrival found; existing blocks were preserved and portal travel was cancelled");
					return;
				}
				if($entity->teleport(Position::fromObject($destination, $target), 90.0, 0.0)){
					$this->inEndPortal[$entity->getId()] = true;
				}
			});
		}elseif($from === DimensionIds::THE_END){
			$overworld = $this->dimensions->getWorld(DimensionIds::OVERWORLD);
			if($overworld === null){
				return;
			}
			$spawn = $entity instanceof Player ? $entity->getSpawn() : $overworld->getSpawnLocation();
			if(!$spawn instanceof Position || !$spawn->isValid() || $this->dimensions->getDimension($spawn->getWorld()) !== DimensionIds::OVERWORLD){
				$spawn = $overworld->getSpawnLocation();
			}
			$spawnWorld = $spawn->getWorld();
			$this->populate($entity, $spawnWorld, $spawn->getFloorX(), $spawn->getFloorZ(), false, 1, function() use($entity, $spawnWorld, $spawn) : void{
				$entity->teleport($spawnWorld->getSafeSpawn($spawn));
			});
		}
	}

	/** Returns true once a link matches, including when that linked trip is refused. */
	private function tryLinkedTravel(Entity $entity, bool $nether) : bool{
		$link = $this->findTouchedLink($entity, $nether);
		if($link === null){
			return false;
		}
		$destination = $link->destination;
		$world = $this->dimensions->getWorldByName($destination->worldName);
		if($world === null || !$world->isLoaded()){
			$entity->getWorld()->getServer()->getLogger()->warning("Portal link " . $link->id . ": destination world is not loaded; travel cancelled");
			return true;
		}
		$dimension = $this->dimensions->getDimension($world);
		if(!LinkedPortalArrival::isWithinBounds($entity, $world, $destination, $dimension)){
			$world->getServer()->getLogger()->warning("Portal link " . $link->id . ": destination is outside dimension bounds; travel cancelled");
			return true;
		}
		$box = LinkedPortalArrival::boundingBox($entity, $destination);
		$radius = (int) ceil(max($box->maxX - $box->minX, $box->maxZ - $box->minZ) / 2) + 1;
		$this->populate($entity, $world, (int) floor($destination->x), (int) floor($destination->z), $nether, $radius, function() use($entity, $world, $destination, $dimension, $link) : void{
			if(!LinkedPortalArrival::isSafe($entity, $world, $destination, $dimension)){
				$world->getServer()->getLogger()->warning("Portal link " . $link->id . ": arrival is obstructed or has no safe floor; travel cancelled");
				return;
			}
			if($entity->teleport(new Position($destination->x, $destination->y, $destination->z, $world), $destination->yaw, $destination->pitch)){
				// Also blocks cross-type links, such as Nether portal -> End portal.
				$this->linkedArrivals[$entity->getId()] = ["entity" => $entity, "outsideTicks" => 0];
			}
		}, $link);
		return true;
	}

	private function findTouchedLink(Entity $entity, bool $nether) : ?PortalLink{
		if($this->portalLinks->getLinks() === []){
			return null;
		}
		$worldName = $entity->getWorld()->getFolderName();
		$type = $nether ? PortalType::NETHER : PortalType::END;
		foreach($this->touchingPortalBlocks($entity, $nether) as [$x, $y, $z]){
			$link = $this->portalLinks->find($worldName, $type, $x, $y, $z);
			if($link !== null){
				return $link;
			}
		}
		return null;
	}

	/** @param array{token: int, entity: Entity, version: int, source: World, nether: bool, deadline: int, link: ?PortalLink} $request */
	private function requestIsValid(array $request) : bool{
		$entity = $request["entity"];
		if($this->currentTick >= $request["deadline"] || !$request["source"]->isLoaded() ||
			!$this->canTravel($entity) || $entity->getPortalTravelVersion() !== $request["version"] ||
			$entity->getWorld() !== $request["source"] || !$this->isTouchingPortal($entity, $request["nether"])
		){
			return false;
		}
		$link = $request["link"];
		if($link !== null && $this->portalLinks->get($link->id) !== $link){
			return false;
		}
		// Adding a link during an automatic trip must not complete the old 8:1 route.
		return $this->findTouchedLink($entity, $request["nether"]) === $link;
	}

	private function isTouchingPortal(Entity $entity, bool $nether) : bool{
		foreach($this->touchingPortalBlocks($entity, $nether) as $_){
			return true;
		}
		return false;
	}

	/** @return iterable<array{int, int, int}> */
	private function touchingPortalBlocks(Entity $entity, bool $nether) : iterable{
		$box = $entity->getBoundingBox();
		$world = $entity->getWorld();
		for($x = (int) floor($box->minX + 0.001); $x <= (int) floor($box->maxX - 0.001); ++$x){
			for($y = (int) floor($box->minY + 0.001); $y <= (int) floor($box->maxY - 0.001); ++$y){
				for($z = (int) floor($box->minZ + 0.001); $z <= (int) floor($box->maxZ - 0.001); ++$z){
					$block = $world->getBlockAt($x, $y, $z);
					if($nether ? $block instanceof NetherPortal : $block instanceof EndPortal){
						yield [$x, $y, $z];
					}
				}
			}
		}
	}

	/**
	 * Populates the complete arrival footprint. Every completion checks both
	 * the request identity and the source portal, including synchronous completions.
	 *
	 * @phpstan-param Closure() : void $callback
	 */
	private function populate(Entity $entity, World $world, int $x, int $z, bool $nether, int $radius, Closure $callback, ?PortalLink $link = null) : void{
		$id = $entity->getId();
		$token = ++$this->requestSequence;
		$this->pending[$id] = [
			"token" => $token,
			"entity" => $entity,
			"version" => $entity->getPortalTravelVersion(),
			"source" => $entity->getWorld(),
			"nether" => $nether,
			"deadline" => $this->currentTick + $this->settings->portalLoadTimeoutTicks,
			"link" => $link
		];
		try{
			$promises = [];
			for($cx = ($x - $radius) >> Chunk::COORD_BIT_SIZE; $cx <= ($x + $radius) >> Chunk::COORD_BIT_SIZE; ++$cx){
				for($cz = ($z - $radius) >> Chunk::COORD_BIT_SIZE; $cz <= ($z + $radius) >> Chunk::COORD_BIT_SIZE; ++$cz){
					$promises[] = $world->orderChunkPopulation($cx, $cz, null);
				}
			}
			Promise::all($promises)->onCompletion(
				function() use($id, $token, $world, $callback) : void{
					$request = $this->pending[$id] ?? null;
					if($request === null || $request["token"] !== $token){
						return;
					}
					unset($this->pending[$id]);
					if(!$world->isLoaded() || !$this->requestIsValid($request)){
						return;
					}
					try{
						$callback();
					}catch(\Throwable $e){
						$world->getServer()->getLogger()->logException($e);
					}
				},
				function() use($id, $token) : void{
					if(($this->pending[$id]["token"] ?? null) === $token){
						unset($this->pending[$id]);
					}
				}
			);
		}catch(\Throwable $e){
			if(($this->pending[$id]["token"] ?? null) === $token){
				unset($this->pending[$id]);
			}
			$world->getServer()->getLogger()->logException($e);
		}
	}
}
