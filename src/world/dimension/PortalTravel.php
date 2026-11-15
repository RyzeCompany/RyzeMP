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

use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\boss\EnderDragon;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Human;
use pocketmine\entity\Leashable;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creaking;
use pocketmine\entity\object\AreaEffectCloud;
use pocketmine\entity\object\EndCrystal;
use pocketmine\entity\object\LeashKnot;
use pocketmine\entity\object\Painting;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\entity\projectile\FishingHook;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\vehicle\AbstractMinecart;
use pocketmine\entity\vehicle\Boat;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\ShowCreditsPacket;
use pocketmine\player\Player;
use pocketmine\promise\Promise;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\ChunkLoader;
use pocketmine\world\format\Chunk;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use function abs;
use function count;
use function floor;
use function max;
use function min;

/**
 * Takes entities through Nether and End portals.
 *
 * Nether portal: a player who stays in the portal for 4 seconds (80 ticks; 1 tick in creative) goes to the linked
 * world of the other dimension (Overworld <-> Nether, see WorldManager::getLinkedWorld()). The arrival point is the
 * player's position scaled 8:1, and they come out of the closest known portal within 128 blocks (Overworld) or 16
 * blocks (Nether) of it, or a new 2x3 portal built nearby.
 * End portal: instant; to the End spawn platform, or from the End to the player's spawn point in the Overworld. The
 * first time a player leaves the End this way, the End Poem and credits play first (saved in the player data, vanilla
 * hasSeenCredits): the player leaves once the client closes them.
 * After travelling, a player must step out of the portal before it works again, and portals do nothing for 1 second.
 *
 * Other entities (items, mobs, minecarts, boats, projectiles, falling blocks, primed TNT, experience orbs...) travel
 * at once, like vanilla, through the same portals:
 * - they come out of the linked portal at the same place relative to the portal, keeping their motion (turned with
 *   them by 90 degrees when the portals stand on different axes); only players build a portal on the other side
 *   (Bedrock), so without a known portal there the entity stays;
 * - through an End portal they arrive on the End spawn platform, or at the Overworld spawn when leaving the End;
 * - afterwards they can't use a portal for 15 seconds (minecarts and boats 0.5 s, projectiles 0.1 s, vanilla
 *   Entity.getDimensionChangingDelay()), and the cooldown starts again as long as they stay in a portal;
 * - they are moved by making a copy from their saved data in the other world and removing them (vanilla
 *   Entity.teleportCrossDimension()); a lead holding a mob breaks;
 * - a minecart or boat takes its passengers along, back on their seats (but not a player: a vehicle carrying one
 *   stays), and passengers don't travel by themselves;
 * - the chunks of the exit portal are loaded before the trip, and stay loaded for 15 seconds afterwards;
 * - the ender dragon, the wither, fishing bobbers, ender pearls and plugin NPCs don't travel, and they only go to
 *   worlds that are already loaded.
 */
final class PortalTravel{
	use SingletonTrait;

	public const NETHER_PORTAL_DELAY_TICKS = 80;
	/** Ticks without portal contact after which an entity counts as having left the portal. */
	public const CONTACT_GRACE_TICKS = 4;
	public const COOLDOWN_TICKS = 20;
	/** Ticks to wait before trying again when the destination doesn't exist. */
	public const RETRY_TICKS = 100;
	public const OVERWORLD_SEARCH_RADIUS = 128;
	public const NETHER_SEARCH_RADIUS = 16;
	/** Portal cooldown of non-player entities after travelling (vanilla Entity.getDimensionChangingDelay()). */
	public const ENTITY_COOLDOWN_TICKS = 300;
	/** Portal cooldown of minecarts and boats (vanilla VehicleEntity). */
	public const VEHICLE_COOLDOWN_TICKS = 10;
	/** Portal cooldown of projectiles (vanilla Projectile). */
	public const PROJECTILE_COOLDOWN_TICKS = 2;

	/**
	 * Ticks the chunks of a Nether portal stay loaded after a non-player entity came out of it (vanilla
	 * TeleportTransition.PLACE_PORTAL_TICKET), so that a stream of items or mobs doesn't load them from disk again.
	 */
	public const PORTAL_TICKET_TICKS = 300;

	/**
	 * The part of canTravel() which only depends on the class, by class.
	 * @var bool[]
	 * @phpstan-var array<class-string<Entity>, bool>
	 */
	private static array $travellingClasses = [];

	/**
	 * Chunks kept loaded around exit portals, by world ID: the loader holding them, and the tick at which each one
	 * (by chunk hash) is released.
	 * @phpstan-var array<int, array{ChunkLoader, array<int, int>}>
	 */
	private array $portalTickets = [];

	/** @phpstan-var \WeakMap<Entity, PortalContact> */
	private \WeakMap $contacts;
	/** The non-player entity whose portal contact is being handled, during its own tick (see moveToWorld()). */
	private ?Entity $handling = null;

	public function __construct(){
		$this->contacts = new \WeakMap();
	}

	private function getContact(Entity $entity) : PortalContact{
		return $this->contacts[$entity] ??= new PortalContact();
	}

	/**
	 * Called every tick an entity is inside a portal block (possibly several times per tick when it touches several).
	 *
	 * @param Dimension $portal Dimension::NETHER for a Nether portal, Dimension::END for an End portal
	 */
	public function onEntityInsidePortal(Entity $entity, Dimension $portal, Vector3 $portalPos) : void{
		if(!$entity instanceof Player){
			$this->onOtherEntityInsidePortal($entity, $portal, $portalPos);
			return;
		}
		if(!$entity->isAlive() || !$entity->isConnected()){
			return;
		}
		$tick = $entity->getWorld()->getServer()->getTick();
		$contact = $this->getContact($entity);
		if($contact->lastTick === $tick){
			return;
		}
		//entities are not always updated every tick (e.g. right after changing world), so allow short gaps
		$continuous = $contact->lastTick >= $tick - self::CONTACT_GRACE_TICKS;
		$contact->lastTick = $tick;
		if(!$continuous){
			$contact->ticksInside = 0;
			$contact->needsExit = false;
			$contact->watchingCredits = false;
		}
		if($contact->needsExit || $contact->travelling || $contact->watchingCredits || $tick < $contact->cooldownUntil || $entity->getNetworkSession()->isChangingDimension()){
			return;
		}

		$required = $portal === Dimension::END || $entity->isCreative() ? 1 : self::NETHER_PORTAL_DELAY_TICKS;
		if(++$contact->ticksInside < $required){
			return;
		}
		$contact->ticksInside = 0;
		if($portal === Dimension::END){
			if($entity->getWorld()->getDimension() === Dimension::END && !$entity->hasSeenCredits()){
				$this->showCredits($entity, $contact);
				return;
			}
			$this->travelThroughEndPortal($entity, $contact);
		}else{
			$this->travelThroughNetherPortal($entity, $portalPos, $contact);
		}
	}

	/**
	 * The first trip through the exit portal of the End plays the End Poem and the credits (vanilla
	 * EndPortalBlock.entityInside(): ServerPlayer.showEndCredits()). The player is marked as having seen them at once,
	 * and leaves the End when the client says they are over (see onCreditsClosed()). A player who disconnects meanwhile
	 * finds themselves in the portal when they come back, and leaves then.
	 */
	private function showCredits(Player $player, PortalContact $contact) : void{
		$player->setSeenCredits(true);
		$contact->watchingCredits = true;
		$player->getNetworkSession()->sendDataPacket(ShowCreditsPacket::create($player->getId(), ShowCreditsPacket::STATUS_START_CREDITS));
	}

	/**
	 * Called when the client of a player says the End credits are over (ShowCreditsPacket END_CREDITS): a player who
	 * was sent them by the exit portal of the End now leaves the End.
	 *
	 * @internal
	 */
	public function onCreditsClosed(Player $player) : void{
		$contact = $this->contacts[$player] ?? null;
		if($contact === null || !$contact->watchingCredits){
			return;
		}
		$contact->watchingCredits = false;
		if($player->isAlive() && $player->isConnected() && !$contact->travelling && $player->getWorld()->getDimension() === Dimension::END){
			$this->travelThroughEndPortal($player, $contact);
		}
	}

	/**
	 * Keeps the given chunks loaded (generating them if needed) and returns a promise resolved when they are all ready.
	 * The returned loader must be unregistered with releaseChunks() when done.
	 *
	 * @return Promise[]
	 * @phpstan-return list<Promise<Chunk>>
	 */
	private static function requestChunks(World $world, ChunkLoader $loader, int $minX, int $minZ, int $maxX, int $maxZ) : array{
		$promises = [];
		for($cx = $minX >> Chunk::COORD_BIT_SIZE; $cx <= $maxX >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = $minZ >> Chunk::COORD_BIT_SIZE; $cz <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$cz){
				$world->registerChunkLoader($loader, $cx, $cz);
				$promises[] = $world->requestChunkPopulation($cx, $cz, $loader);
			}
		}
		return $promises;
	}

	private static function releaseChunks(World $world, ChunkLoader $loader, int $minX, int $minZ, int $maxX, int $maxZ) : void{
		if(!$world->isLoaded()){
			return;
		}
		for($cx = $minX >> Chunk::COORD_BIT_SIZE; $cx <= $maxX >> Chunk::COORD_BIT_SIZE; ++$cx){
			for($cz = $minZ >> Chunk::COORD_BIT_SIZE; $cz <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$cz){
				$world->unregisterChunkLoader($loader, $cx, $cz);
			}
		}
	}

	/**
	 * Returns where an entity at the given position in a world of the source dimension arrives in the target dimension
	 * before looking for a portal: X and Z scaled 8:1 between the Overworld and the Nether, Y clamped to where a portal
	 * can be built.
	 */
	public static function getScaledArrival(Vector3 $pos, Dimension $source, World $target) : Vector3{
		$scale = $source->getCoordinateScale() / $target->getDimension()->getCoordinateScale();
		return new Vector3(
			(int) floor($pos->x * $scale),
			NetherPortalBuilder::clampY($target, $pos->getFloorY()),
			(int) floor($pos->z * $scale)
		);
	}

	private function travelThroughNetherPortal(Player $player, Vector3 $portalPos, PortalContact $contact) : void{
		$source = $player->getWorld();
		$sourceDimension = $source->getDimension();
		if($sourceDimension === Dimension::END){
			return;
		}
		$targetDimension = $sourceDimension === Dimension::NETHER ? Dimension::OVERWORLD : Dimension::NETHER;
		$target = $source->getServer()->getWorldManager()->getLinkedWorld($source, $targetDimension);
		if($target === null){
			$contact->cooldownUntil = $source->getServer()->getTick() + self::RETRY_TICKS;
			return;
		}

		//remember the portal used, so the trip back comes out of it (needed for portals lit before the registry existed)
		$used = NetherPortalShape::findLit($source, $portalPos);
		if($used !== null){
			NetherPortalRegistry::getInstance()->add($source, $used);
		}

		$arrival = self::getScaledArrival($player->getPosition(), $sourceDimension, $target);
		$searchRadius = $targetDimension === Dimension::NETHER ? self::NETHER_SEARCH_RADIUS : self::OVERWORLD_SEARCH_RADIUS;
		$r = NetherPortalBuilder::SEARCH_RADIUS + 3; //the frame and its platform reach 2 blocks past the chosen spot
		[$minX, $minZ, $maxX, $maxZ] = [$arrival->getFloorX() - $r, $arrival->getFloorZ() - $r, $arrival->getFloorX() + $r, $arrival->getFloorZ() + $r];

		$contact->travelling = true;
		$loader = new class implements ChunkLoader{};
		Promise::all(self::requestChunks($target, $loader, $minX, $minZ, $maxX, $maxZ))->onCompletion(
			function() use ($player, $source, $target, $arrival, $searchRadius, $contact, $loader, $minX, $minZ, $maxX, $maxZ) : void{
				$contact->travelling = false;
				try{
					if(!$player->isConnected() || !$player->isAlive() || $player->getWorld() !== $source || !$target->isLoaded()){
						return;
					}
					$portal = NetherPortalRegistry::getInstance()->findNearest($target, $arrival, $searchRadius) ?? NetherPortalBuilder::create($target, $arrival);
					$this->teleport($player, $contact, Position::fromObject($portal->getArrivalPosition(), $target));
				}finally{
					self::releaseChunks($target, $loader, $minX, $minZ, $maxX, $maxZ);
				}
			},
			function() use ($target, $contact, $loader, $minX, $minZ, $maxX, $maxZ) : void{
				$contact->travelling = false;
				$contact->cooldownUntil = $target->getServer()->getTick() + self::RETRY_TICKS;
				self::releaseChunks($target, $loader, $minX, $minZ, $maxX, $maxZ);
			}
		);
	}

	private function travelThroughEndPortal(Player $player, PortalContact $contact) : void{
		$source = $player->getWorld();
		$server = $source->getServer();
		$targetDimension = $source->getDimension() === Dimension::END ? Dimension::OVERWORLD : Dimension::END;
		$target = $server->getWorldManager()->getLinkedWorld($source, $targetDimension);
		if($target === null){
			$contact->cooldownUntil = $server->getTick() + self::RETRY_TICKS;
			return;
		}

		$contact->travelling = true;
		if($targetDimension === Dimension::END){
			$this->withEndSpawnPlatform($target, function() use ($player, $source, $target, $contact) : void{
				$contact->travelling = false;
				if(!$player->isConnected() || !$player->isAlive() || $player->getWorld() !== $source){
					return;
				}
				$this->teleport($player, $contact, Position::fromObject(EndSpawnPlatform::getArrivalPosition(), $target));
				DragonFight::getInstance()->onPlayerArrived($target);
			}, function() use ($target, $contact) : void{
				$contact->travelling = false;
				$contact->cooldownUntil = $target->getServer()->getTick() + self::RETRY_TICKS;
			});
		}else{
			//leaving the End: to the player's spawn point if it is in that world, otherwise the world spawn
			$spawn = $player->hasValidCustomSpawn() && $player->getSpawn()->getWorld() === $target ? $player->getSpawn() : $target->getSpawnLocation();
			$target->requestSafeSpawn($spawn)->onCompletion(
				function(Position $safeSpawn) use ($player, $source, $target, $contact) : void{
					$contact->travelling = false;
					if(!$player->isConnected() || !$player->isAlive() || $player->getWorld() !== $source || !$target->isLoaded()){
						return;
					}
					$this->teleport($player, $contact, $safeSpawn);
				},
				function() use ($target, $contact) : void{
					$contact->travelling = false;
					$contact->cooldownUntil = $target->getServer()->getTick() + self::RETRY_TICKS;
				}
			);
		}
	}

	/**
	 * Loads (generating if needed) the chunks around the End spawn platform, builds the platform and runs $then, or
	 * $onFailure if the chunks can't be loaded.
	 *
	 * @phpstan-param \Closure() : void $then
	 * @phpstan-param \Closure() : void $onFailure
	 */
	private function withEndSpawnPlatform(World $end, \Closure $then, \Closure $onFailure) : void{
		$loader = new class implements ChunkLoader{};
		$x = EndSpawnPlatform::X;
		$z = EndSpawnPlatform::Z;
		Promise::all(self::requestChunks($end, $loader, $x - 3, $z - 3, $x + 3, $z + 3))->onCompletion(
			function() use ($end, $then, $onFailure, $loader, $x, $z) : void{
				try{
					if(!$end->isLoaded()){
						$onFailure();
						return;
					}
					EndSpawnPlatform::build($end);
					$then();
				}finally{
					self::releaseChunks($end, $loader, $x - 3, $z - 3, $x + 3, $z + 3);
				}
			},
			function() use ($end, $onFailure, $loader, $x, $z) : void{
				self::releaseChunks($end, $loader, $x - 3, $z - 3, $x + 3, $z + 3);
				$onFailure();
			}
		);
	}

	private function teleport(Player $player, PortalContact $contact, Position $destination) : void{
		$location = $player->getLocation();
		if($player->teleport(Location::fromObject($destination, $destination->getWorld(), $location->yaw, $location->pitch))){
			//the player usually arrives inside a portal: it must step out before going back
			$contact->needsExit = true;
			$contact->ticksInside = 0;
			$contact->cooldownUntil = $destination->getWorld()->getServer()->getTick() + self::COOLDOWN_TICKS;
		}
	}

	/**
	 * Whether a non-player entity goes through portals and End gateways (vanilla Entity.canUsePortal() and its
	 * overrides): not the ender dragon or the wither, not passengers (their vehicle takes them), not fishing bobbers or
	 * ender pearls, nothing that never moves (paintings, knots, crystals, effect clouds), not a creaking bound to its
	 * heart, and nothing that isn't saved with the world or looks like a plugin's NPC (other humans, named or frozen
	 * living entities of plugin classes).
	 *
	 * A vehicle carrying passengers only travels with $withPassengers (portals, which take them along like vanilla
	 * Entity.teleportCrossDimension(); End gateways teleport within the world, which ends rides), and only if all of
	 * them could travel themselves: a vehicle carrying a player stays.
	 *
	 * @internal
	 */
	public static function canTravel(Entity $entity, bool $withPassengers = false) : bool{
		if($entity->isRiding() || !self::canTravelAlone($entity)){
			return false;
		}
		foreach($entity->getPassengers() as $passenger){
			if(!$withPassengers || count($passenger->getPassengers()) > 0 || !self::canTravelAlone($passenger)){
				return false;
			}
		}
		return true;
	}

	private static function canTravelAlone(Entity $entity) : bool{
		if($entity->isClosed() || $entity->isFlaggedForDespawn() || !$entity->isAlive() || !$entity->canSaveWithChunk()){
			return false;
		}
		$class = $entity::class;
		if(!(self::$travellingClasses[$class] ??= self::isTravellingClass($entity)) || ($entity instanceof Creaking && $entity->isHeartBound())){
			return false;
		}
		return !($entity instanceof Living && Sensing::looksLikePluginNpc($entity)) && EntityFactory::getInstance()->isRegistered($class);
	}

	/**
	 * The part of canTravel() which only depends on the class of the entity (cached).
	 */
	private static function isTravellingClass(Entity $entity) : bool{
		return !(
			$entity instanceof Player || $entity instanceof EnderDragon || $entity instanceof Wither || $entity instanceof Human ||
			$entity instanceof FishingHook || $entity instanceof EnderPearl ||
			$entity instanceof Painting || $entity instanceof LeashKnot || $entity instanceof EndCrystal || $entity instanceof AreaEffectCloud
		);
	}

	/**
	 * Returns the portal cooldown of a non-player entity, in ticks (vanilla Entity.getDimensionChangingDelay()).
	 */
	public static function getCooldownTicks(Entity $entity) : int{
		return match(true){
			$entity instanceof AbstractMinecart, $entity instanceof Boat => self::VEHICLE_COOLDOWN_TICKS,
			$entity instanceof Projectile => self::PROJECTILE_COOLDOWN_TICKS,
			default => self::ENTITY_COOLDOWN_TICKS
		};
	}

	/**
	 * Starts the portal cooldown of a non-player entity (vanilla Entity.setPortalCooldown()): it can't use a portal or
	 * an End gateway for the given number of ticks (its own cooldown by default), and the cooldown starts again while
	 * it stays in one. Used on entities which just travelled, and on the zombified piglins portals spawn.
	 *
	 * @internal
	 */
	public function setPortalCooldown(Entity $entity, ?int $ticks = null) : void{
		$contact = $this->getContact($entity);
		$contact->cooldownUntil = $entity->getWorld()->getServer()->getTick() + ($ticks ?? self::getCooldownTicks($entity));
		$contact->renewCooldown = true;
	}

	/**
	 * Returns whether a non-player entity can't use portals for now (see setPortalCooldown()).
	 *
	 * @internal
	 */
	public function isOnPortalCooldown(Entity $entity) : bool{
		$contact = $this->contacts[$entity] ?? null;
		return $contact !== null && $entity->getWorld()->getServer()->getTick() < $contact->cooldownUntil;
	}

	/**
	 * Returns whether the portal cooldown of a non-player entity in a portal is over; while it isn't, it starts again
	 * (vanilla Entity.setAsInsidePortal()).
	 */
	private static function checkCooldown(PortalContact $contact, int $tick, int $cooldown) : bool{
		if($tick >= $contact->cooldownUntil){
			return true;
		}
		if($contact->renewCooldown){
			$contact->cooldownUntil = $tick + $cooldown;
		}
		return false;
	}

	/**
	 * Called every tick a non-player entity is inside an End gateway: returns whether it may use it now, see
	 * {@link EndGatewayTravel}. Shares the portal cooldown, at least the gateway's.
	 *
	 * @internal
	 */
	public function mayUseGateway(Entity $entity) : bool{
		//entities touching several gateway or portal blocks get here several times per tick: only the first counts
		$tick = $entity->getWorld()->getServer()->getTick();
		$contact = $this->getContact($entity);
		if($contact->lastTick === $tick || $contact->travelling){
			return false;
		}
		$contact->lastTick = $tick;
		if(!self::canTravel($entity)){
			return false;
		}
		return self::checkCooldown($contact, $tick, max(EndGatewayTravel::COOLDOWN_TICKS, self::getCooldownTicks($entity)));
	}

	private function onOtherEntityInsidePortal(Entity $entity, Dimension $portal, Vector3 $portalPos) : void{
		//entities touching several portal blocks get here several times per tick: only the first counts
		$tick = $entity->getWorld()->getServer()->getTick();
		$contact = $this->getContact($entity);
		if($contact->lastTick === $tick){
			return;
		}
		$contact->lastTick = $tick;
		if(!self::canTravel($entity, true)){
			return;
		}
		if($contact->travelling || !self::checkCooldown($contact, $tick, self::getCooldownTicks($entity))){
			return;
		}
		$this->handling = $entity;
		try{
			if($portal === Dimension::END){
				$this->sendThroughEndPortal($entity, $contact);
			}else{
				$this->sendThroughNetherPortal($entity, $portalPos, $contact);
			}
		}finally{
			$this->handling = null;
		}
	}

	private static function retryLater(PortalContact $contact, World $world) : void{
		$contact->cooldownUntil = $world->getServer()->getTick() + self::RETRY_TICKS;
		$contact->renewCooldown = false;
	}

	/**
	 * Returns the loaded world of the given dimension linked to the given world: entities other than players don't
	 * make the server load or generate a world.
	 */
	private static function getLoadedLinkedWorld(World $world, Dimension $target) : ?World{
		$linked = $world->getServer()->getWorldManager()->getWorldByName(WorldManager::getLinkedWorldName($world->getFolderName(), $world->getDimension(), $target));
		return $linked !== null && $linked->getDimension() === $target ? $linked : null;
	}

	private static function isStillTravelling(Entity $entity, World $source) : bool{
		return !$entity->isClosed() && $entity->getWorld() === $source && self::canTravel($entity, true);
	}

	/**
	 * A non-player entity in a Nether portal goes to the linked portal of the other dimension at once, if it is known
	 * (see {@link NetherPortalRegistry}): unlike players, it never builds one there.
	 *
	 * Only the chunks of the known portals in range are loaded (none at all without any), through chunk requests like
	 * for players, and they stay loaded for a while (see holdPortalChunks()).
	 */
	private function sendThroughNetherPortal(Entity $entity, Vector3 $portalPos, PortalContact $contact) : void{
		$source = $entity->getWorld();
		$sourceDimension = $source->getDimension();
		if($sourceDimension === Dimension::END){
			return;
		}
		$targetDimension = $sourceDimension === Dimension::NETHER ? Dimension::OVERWORLD : Dimension::NETHER;
		$target = self::getLoadedLinkedWorld($source, $targetDimension);
		$entry = NetherPortalShape::findLit($source, $portalPos);
		if($target === null || $entry === null){
			self::retryLater($contact, $source);
			return;
		}
		$registry = NetherPortalRegistry::getInstance();
		$registry->add($source, $entry);
		$arrival = self::getScaledArrival($entity->getPosition(), $sourceDimension, $target);
		$radius = $targetDimension === Dimension::NETHER ? self::NETHER_SEARCH_RADIUS : self::OVERWORLD_SEARCH_RADIUS;
		$chunks = self::getPortalChunks($registry->getAll($target), $arrival, $radius);
		if(count($chunks) === 0){
			self::retryLater($contact, $source);
			return;
		}

		$loader = $this->holdPortalChunks($target, $chunks, $source->getServer()->getTick() + self::PORTAL_TICKET_TICKS);
		$promises = [];
		foreach($chunks as [$chunkX, $chunkZ]){
			$promises[] = $target->requestChunkPopulation($chunkX, $chunkZ, $loader);
		}
		$contact->travelling = true;
		Promise::all($promises)->onCompletion(
			function() use ($entity, $source, $target, $entry, $arrival, $radius, $contact) : void{
				$contact->travelling = false;
				if(!self::isStillTravelling($entity, $source) || !$target->isLoaded()){
					return;
				}
				$exit = NetherPortalRegistry::getInstance()->findNearest($target, $arrival, $radius);
				if($exit === null){
					self::retryLater($contact, $source);
					return;
				}
				[$position, $yawChange] = self::getRelativeArrival($entity, $entry, $exit, $target);
				if($this->moveToWorld($entity, Position::fromObject($position, $target), $yawChange) === null){
					self::retryLater($contact, $source);
				}
			},
			function() use ($source, $contact) : void{
				$contact->travelling = false;
				self::retryLater($contact, $source);
			}
		);
	}

	/**
	 * Returns the chunks holding the recorded portals within $radius blocks of $pos (per axis, see
	 * {@link NetherPortalRegistry::findNearest()}), with the blocks around them where entities come out.
	 *
	 * @param int[][] $portals records of {@link NetherPortalRegistry::getAll()}
	 * @phpstan-param list<array{int, int, int, int, int, int}> $portals
	 *
	 * @return int[][] chunk hash => [chunkX, chunkZ]
	 * @phpstan-return array<int, array{int, int}>
	 */
	private static function getPortalChunks(array $portals, Vector3 $pos, int $radius) : array{
		$chunks = [];
		foreach($portals as [$x, , $z, $axis, $width]){
			if(max(abs($x - $pos->x), abs($z - $pos->z)) > $radius){
				continue;
			}
			[$maxX, $maxZ] = $axis === Axis::X ? [$x + $width, $z + 1] : [$x + 1, $z + $width];
			for($chunkX = ($x - 1) >> Chunk::COORD_BIT_SIZE; $chunkX <= $maxX >> Chunk::COORD_BIT_SIZE; ++$chunkX){
				for($chunkZ = ($z - 1) >> Chunk::COORD_BIT_SIZE; $chunkZ <= $maxZ >> Chunk::COORD_BIT_SIZE; ++$chunkZ){
					$chunks[World::chunkHash($chunkX, $chunkZ)] = [$chunkX, $chunkZ];
				}
			}
		}
		return $chunks;
	}

	/**
	 * Keeps the given chunks loaded until the given tick (or later if they were already held longer), and returns the
	 * loader holding them.
	 *
	 * @param int[][] $chunks chunk hash => [chunkX, chunkZ]
	 * @phpstan-param array<int, array{int, int}> $chunks
	 */
	private function holdPortalChunks(World $world, array $chunks, int $until) : ChunkLoader{
		$worldId = $world->getId();
		if(!isset($this->portalTickets[$worldId])){
			$this->portalTickets[$worldId] = [new class implements ChunkLoader{}, []];
			$world->addOnUnloadCallback(function() use ($worldId) : void{
				unset($this->portalTickets[$worldId]);
			});
		}
		$loader = $this->portalTickets[$worldId][0];
		foreach($chunks as $hash => [$chunkX, $chunkZ]){
			$held = $this->portalTickets[$worldId][1][$hash] ?? null;
			if($held === null){
				$world->registerChunkLoader($loader, $chunkX, $chunkZ);
			}
			$this->portalTickets[$worldId][1][$hash] = max($held ?? 0, $until);
		}
		return $loader;
	}

	/**
	 * Releases the held portal chunks of a world whose time is over (checked once per second).
	 *
	 * @internal Called by every world every tick.
	 */
	public static function tickWorld(World $world, int $currentTick) : void{
		$instance = self::$instance;
		if($instance === null || $currentTick % 20 !== 0 || !isset($instance->portalTickets[$worldId = $world->getId()])){
			return;
		}
		[$loader, $chunks] = $instance->portalTickets[$worldId];
		foreach($chunks as $hash => $until){
			if($currentTick >= $until){
				World::getXZ($hash, $chunkX, $chunkZ);
				$world->unregisterChunkLoader($loader, $chunkX, $chunkZ);
				unset($instance->portalTickets[$worldId][1][$hash]);
			}
		}
	}

	/**
	 * A non-player entity in an End portal goes to the End spawn platform (built like for players, vanilla
	 * EndPortalBlock.getPortalDestination()), or from the End to the world spawn of the Overworld.
	 */
	private function sendThroughEndPortal(Entity $entity, PortalContact $contact) : void{
		$source = $entity->getWorld();
		$toEnd = $source->getDimension() !== Dimension::END;
		$target = self::getLoadedLinkedWorld($source, $toEnd ? Dimension::END : Dimension::OVERWORLD);
		if($target === null){
			self::retryLater($contact, $source);
			return;
		}
		$contact->travelling = true;
		$arrive = function(Position $destination) use ($entity, $source, $contact) : void{
			$contact->travelling = false;
			if(self::isStillTravelling($entity, $source) && $destination->getWorld()->isLoaded() && $this->moveToWorld($entity, $destination, 0.0) === null){
				self::retryLater($contact, $source);
			}
		};
		$fail = function() use ($source, $contact) : void{
			$contact->travelling = false;
			self::retryLater($contact, $source);
		};
		if($toEnd){
			$this->withEndSpawnPlatform($target, fn() => $arrive(Position::fromObject(EndSpawnPlatform::getArrivalPosition(), $target)), $fail);
		}else{
			$target->requestSafeSpawn($target->getSpawnLocation())->onCompletion($arrive, $fail);
		}
	}

	/**
	 * Where an entity going through the portal $entry comes out of the portal $exit in $world, and by how many degrees
	 * it turns: at the same place relative to the portal (vanilla PortalShape.getRelativePosition() and
	 * NetherPortalBlock.createDimensionTransition()), turned by 90 degrees when the portals stand on different axes. An
	 * entity which wouldn't fit beside the exit portal comes out in its middle.
	 *
	 * @return array{Vector3, float}
	 *
	 * @internal
	 */
	public static function getRelativeArrival(Entity $entity, NetherPortalShape $entry, NetherPortalShape $exit, World $world) : array{
		$width = $entity->getSize()->getWidth();
		$height = $entity->getSize()->getHeight();
		$pos = $entity->getPosition();
		[$along, $across, $originAlong, $originAcross] = $entry->axis === Axis::X ?
			[$pos->x, $pos->z, $entry->origin->x, $entry->origin->z] :
			[$pos->z, $pos->x, $entry->origin->z, $entry->origin->x];

		$room = $entry->width - $width;
		$alongFraction = $room > 0 ? max(0.0, min(1.0, ($along - ($originAlong + $width / 2)) / $room)) : 0.5;
		$roomUp = $entry->height - $height;
		$upFraction = $roomUp > 0 ? max(0.0, min(1.0, ($pos->y - $entry->origin->y) / $roomUp)) : 0.0;
		$acrossOffset = $across - ($originAcross + 0.5);

		$right = $exit->width > $width ? $width / 2 + ($exit->width - $width) * $alongFraction : $exit->width / 2;
		$up = $exit->height > $height ? ($exit->height - $height) * $upFraction : 0.0;
		$arrival = self::exitPoint($exit, $right, $up, 0.5 + $acrossOffset);
		$halfWidth = $width / 2;
		$box = new AxisAlignedBB($arrival->x - $halfWidth, $arrival->y, $arrival->z - $halfWidth, $arrival->x + $halfWidth, $arrival->y + $height, $arrival->z + $halfWidth);
		if(count($world->getCollisionBlocks($box)) > 0){
			//vanilla moves it to the nearest free spot (PortalShape.findCollisionFreePosition()): the portal itself is free
			$arrival = self::exitPoint($exit, $right, $up, 0.5);
		}
		return [$arrival, $entry->axis === $exit->axis ? 0.0 : 90.0];
	}

	private static function exitPoint(NetherPortalShape $exit, float $right, float $up, float $forward) : Vector3{
		$o = $exit->origin;
		return $exit->axis === Axis::X ?
			new Vector3($o->x + $right, $o->y + $up, $o->z + $forward) :
			new Vector3($o->x + $forward, $o->y + $up, $o->z + $right);
	}

	/**
	 * Moves a non-player entity to another world like vanilla Entity.teleportCrossDimension(): a copy is made there from
	 * its saved data (so it keeps its name, owner, health, effects, inventory...) and the entity is removed. The copy
	 * keeps the entity's motion, turned with it by $yawChange degrees, and starts its portal cooldown. A lead holding
	 * the entity breaks (the lead drops where it was). The passengers of a vehicle are moved the same way and put back
	 * on their seats (a passenger which can't be copied gets off and stays).
	 *
	 * Plugins see the trip as an {@link EntityTeleportEvent} of the entity (the vehicle), which they may cancel or
	 * redirect. Returns the copy (or the entity itself if a plugin sent it elsewhere in its own world), or null if it
	 * stays.
	 */
	private function moveToWorld(Entity $entity, Position $destination, float $yawChange) : ?Entity{
		$ev = new EntityTeleportEvent($entity, $entity->getPosition(), $destination);
		$ev->call();
		$to = $ev->getTo();
		if($ev->isCancelled() || $entity->isClosed() || $entity->isFlaggedForDespawn() || !$to->isValid()){
			return null;
		}
		$world = $to->getWorld();
		if($world === $entity->getWorld()){
			return $entity->teleport($to) ? $entity : null;
		}

		foreach([$entity, ...$entity->getPassengers()] as $traveller){
			if($traveller instanceof Leashable && $traveller->isLeashed()){
				$traveller->unleash(true);
			}
		}
		$motion = $entity->getMotion();
		if($yawChange !== 0.0){
			//vanilla turns the motion with the entity (Relative.ROTATE_DELTA): +90 degrees of yaw maps (x, z) to (-z, x)
			$motion = new Vector3(-$motion->z, $motion->y, $motion->x);
		}
		$nbt = $entity->saveNBT();
		$nbt->removeTag(Entity::TAG_LINKS); //the passengers are put back on their seats below
		$copy = self::copyToWorld($entity, $nbt, $to, $motion, $yawChange);
		if($copy === null){
			return null;
		}
		$passengerCopies = [];
		foreach($entity->getPassengers() as $passenger){
			$passengerCopy = self::copyToWorld($passenger, $passenger->saveNBT(), $to, Vector3::zero(), $yawChange);
			if($passengerCopy !== null){
				$passengerCopies[] = $passengerCopy;
				//never both (the passenger isn't the entity being ticked, so it can go at once)
				$passenger->setCanSaveWithChunk(false);
				$passenger->close();
			}
		}

		//never both: the entity isn't saved any more, whatever happens before it is gone
		$entity->setCanSaveWithChunk(false);
		if($this->handling === $entity){
			//during its own tick: the world removes it once the tick is over
			$entity->flagForDespawn();
		}else{
			$entity->close();
		}
		$copy->spawnToAll();
		$this->setPortalCooldown($copy);
		foreach($passengerCopies as $passengerCopy){
			$passengerCopy->spawnToAll();
			$passengerCopy->mount($copy);
			$this->setPortalCooldown($passengerCopy);
		}
		return $copy;
	}

	/**
	 * Creates a copy of an entity from its saved data in another world, at the given position with the given motion,
	 * turned by $yawChange degrees, or returns null if it can't be.
	 */
	private static function copyToWorld(Entity $entity, CompoundTag $nbt, Position $to, Vector3 $motion, float $yawChange) : ?Entity{
		$world = $to->getWorld();
		$location = $entity->getLocation();
		$nbt->setTag(Entity::TAG_POS, new ListTag([new DoubleTag($to->x), new DoubleTag($to->y), new DoubleTag($to->z)]));
		$nbt->setTag(Entity::TAG_MOTION, new ListTag([new DoubleTag($motion->x), new DoubleTag($motion->y), new DoubleTag($motion->z)]));
		$nbt->setTag(Entity::TAG_ROTATION, new ListTag([new FloatTag($location->yaw + $yawChange), new FloatTag($location->pitch)]));
		try{
			$copy = EntityFactory::getInstance()->createFromData($world, $nbt);
		}catch(SavedDataLoadingException $e){
			\GlobalLogger::get()->debug("Cannot move " . $entity::class . " to " . $world->getFolderName() . ": " . $e->getMessage());
			$copy = null;
		}
		if($copy === null){
			return null;
		}
		$owner = $entity->getOwningEntity();
		if($owner !== null){
			$copy->setOwningEntity($owner);
		}
		return $copy;
	}
}
