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

namespace pocketmine\world\map;

use pocketmine\color\Color;
use pocketmine\inventory\PlayerInventory;
use pocketmine\item\FilledMap;
use pocketmine\network\mcpe\protocol\ClientboundMapItemDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\MapDecoration;
use pocketmine\network\mcpe\protocol\types\MapDecorationType;
use pocketmine\network\mcpe\protocol\types\MapImage;
use pocketmine\network\mcpe\protocol\types\MapTrackedObject;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Path;
use function abs;
use function count;
use function floor;
use function hrtime;
use function max;
use function min;
use function spl_object_id;

/**
 * Keeps the maps in use, sends them to the clients that ask for them (MapInfoRequestPacket, sent for maps in the
 * inventory and in item frames) and draws the terrain around players holding a map, a few columns per tick.
 *
 * Drawing is spread over ticks: each holder gets at most COLUMNS_PER_HOLDER map columns per tick (a full scale 0 map
 * is 128 columns), and all holders together stop when TICK_TIME_BUDGET_NS is used up, so maps never stall the tick.
 */
final class MapManager{
	use SingletonTrait;

	/** Map columns drawn per tick for each player holding a map (vanilla refreshes 1/16 of the columns per tick) */
	public const COLUMNS_PER_HOLDER = 4;
	/** Time all map drawing may take in one tick */
	public const TICK_TIME_BUDGET_NS = 2_000_000;
	/** How often player markers are refreshed */
	private const DECORATION_INTERVAL_TICKS = 5;
	/** Minimum delay before sending the whole map again to a client that keeps asking for it */
	private const FULL_RESEND_DELAY_TICKS = 40;
	/** How often changed maps are saved and unused maps unloaded */
	private const SAVE_INTERVAL_TICKS = 6000;
	/**
	 * Delay between the spawn of a player and the answer to the map requests its client sent while it was loading
	 * (100 ms, like Geyser: the client may ignore map data that arrives while it is still setting up)
	 */
	private const SPAWN_ANSWER_DELAY_TICKS = 2;
	/** Most map requests kept for a player that is still spawning (inventory, off hand and item frames around it) */
	private const MAX_PENDING_REQUESTS = 128;
	/** Distance (in map pixels from the centre) up to which a player off the map is shown at the edge */
	private const OFF_MAP_MARKER_RANGE = 320;

	private MapRenderer $renderer;
	private TimingsHandler $timings;

	/**
	 * @var MapData[]
	 * @phpstan-var array<int, MapData>
	 */
	private array $maps = [];
	/**
	 * Tick when each loaded map was last used
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $lastUsed = [];
	/**
	 * Players that were sent each map and get its updates
	 * @var Player[][]
	 * @phpstan-var array<int, array<int, Player>>
	 */
	private array $viewers = [];
	/**
	 * Tick of the last full send, by map ID and player
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private array $lastFullSend = [];
	/**
	 * Next column to draw for each player holding a map
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $drawCursors = [];
	/**
	 * Last decorations sent, by map ID
	 * @var MapDecoration[][]
	 * @phpstan-var array<int, list<MapDecoration>>
	 */
	private array $decorations = [];
	/**
	 * Players the decorations of each map belong to
	 * @var int[][]
	 * @phpstan-var array<int, list<int>>
	 */
	private array $decorationActors = [];
	/**
	 * Summary of the last decorations sent, to detect changes
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private array $decorationSignatures = [];

	/**
	 * @var Player[]
	 * @phpstan-var array<int, Player>
	 */
	private array $pendingInventorySyncs = [];

	/**
	 * Map requests sent by clients that are still spawning, answered once they have spawned: player, tick from which
	 * they may be answered (null until the player spawned) and the requested map IDs
	 * @var mixed[][]
	 * @phpstan-var array<int, array{Player, ?int, array<int, true>}>
	 */
	private array $pendingRequests = [];

	private int $currentTick = 0;
	private int $firstHolder = 0;

	public function __construct(
		private MapDataStore $store,
		?BlockMapColors $colors = null
	){
		$this->renderer = new MapRenderer($colors ?? BlockMapColors::getInstance());
		$this->timings = new TimingsHandler("Map updates");
	}

	private static function make() : self{
		return new self(new MapDataStore(Path::join(Server::getInstance()->getDataPath(), "maps")));
	}

	/**
	 * Saves the changed maps if the manager was used. Called when the server stops.
	 */
	public static function saveIfLoaded() : void{
		self::$instance?->saveAll();
	}

	public function getStore() : MapDataStore{ return $this->store; }

	public function getRenderer() : MapRenderer{ return $this->renderer; }

	/**
	 * Creates a new map of the given world around the given position, with a new ID.
	 */
	public function createMap(World $world, int $x, int $z, int $scale = 0) : MapData{
		[$centerX, $centerZ] = MapData::alignCenter($x, $z, $scale);
		return $this->addMap(new MapData($this->store->allocateId(), $world->getFolderName(), $centerX, $centerZ, $scale, dimension: $world->getDimension()->getNetworkId()));
	}

	/**
	 * Reserves a new map ID, for a map that will be created later (e.g. by a cartography table operation).
	 */
	public function allocateMapId() : int{
		return $this->store->allocateId();
	}

	private function addMap(MapData $map) : MapData{
		$this->maps[$map->getId()] = $map;
		$this->lastUsed[$map->getId()] = $this->currentTick;
		$this->store->save($map);
		return $map;
	}

	/**
	 * Creates the zoomed-out version of a map (cartography table or crafting with paper): a blank map with the next
	 * scale, on the vanilla grid around the centre of the source map.
	 */
	public function createScaledMap(MapData $source, int $newId) : MapData{
		$scale = min($source->getScale() + 1, MapData::MAX_SCALE);
		[$centerX, $centerZ] = MapData::alignCenter($source->getCenterX(), $source->getCenterZ(), $scale);
		return $this->addMap(new MapData($newId, $source->getWorldName(), $centerX, $centerZ, $scale, false, $source->getId(), dimension: $source->getDimension()));
	}

	/**
	 * Creates a locked copy of a map (cartography table with a glass pane), which is never drawn on again.
	 */
	public function createLockedCopy(MapData $source, int $newId) : MapData{
		$copy = new MapData($newId, $source->getWorldName(), $source->getCenterX(), $source->getCenterZ(), $source->getScale(), true, $source->getId(), $source->getPixels(), $source->getDimension());
		foreach($source->getDecorations() as [$type, $x, $z]){
			$copy->addDecoration($type, $x, $z);
		}
		return $this->addMap($copy);
	}

	/**
	 * Creates a map with an ID reserved earlier by {@link allocateMapId()} (e.g. an explorer map whose centre was
	 * searched for off the main thread), on the vanilla grid around the given position.
	 */
	public function createMapWithId(int $id, World $world, int $x, int $z, int $scale) : MapData{
		[$centerX, $centerZ] = MapData::alignCenter($x, $z, $scale);
		return $this->addMap(new MapData($id, $world->getFolderName(), $centerX, $centerZ, $scale, dimension: $world->getDimension()->getNetworkId()));
	}

	/**
	 * Sends the whole inventory of the player again on the next tick, for changes the client can't predict.
	 */
	public function scheduleInventorySync(Player $player) : void{
		$this->pendingInventorySyncs[spl_object_id($player)] = $player;
	}

	/**
	 * Returns the map with the given ID, loading it if needed, or null if it doesn't exist.
	 */
	public function getMap(int $id) : ?MapData{
		if(!isset($this->maps[$id])){
			if(!$this->store->wasAllocated($id)){
				return null;
			}
			$map = $this->store->load($id);
			if($map === null){
				return null;
			}
			$this->maps[$id] = $map;
		}
		$this->lastUsed[$id] = $this->currentTick;
		return $this->maps[$id];
	}

	/**
	 * Called when a client asks for the data of a map it doesn't have (map in its inventory or in an item frame).
	 */
	public function handleMapInfoRequest(Player $player, int $mapId) : void{
		if(!$player->spawned){
			//the client asks for the maps in its inventory while it loads (its inventory ticks before it spawns) and
			//doesn't ask again, so the request is kept and answered once the player has spawned
			$this->queueRequest($player, $mapId);
			return;
		}
		$map = $this->getMap($mapId);
		if($map === null){
			return;
		}
		$playerId = spl_object_id($player);
		$lastSend = $this->lastFullSend[$mapId][$playerId] ?? null;
		if($lastSend !== null && $this->currentTick - $lastSend < self::FULL_RESEND_DELAY_TICKS){
			return;
		}
		$this->lastFullSend[$mapId][$playerId] = $this->currentTick;
		$this->viewers[$mapId][$playerId] = $player;
		$this->sendFullMap($player, $map);
	}

	private function queueRequest(Player $player, int $mapId) : void{
		if(!$player->isConnected() || !$this->store->wasAllocated($mapId)){
			return;
		}
		$playerId = spl_object_id($player);
		$pending = $this->pendingRequests[$playerId] ?? [$player, null, []];
		if(count($pending[2]) < self::MAX_PENDING_REQUESTS){
			$pending[2][$mapId] = true;
		}
		$this->pendingRequests[$playerId] = $pending;
	}

	/**
	 * Answers the map requests of players that were spawning when they sent them.
	 */
	private function answerPendingRequests() : void{
		foreach($this->pendingRequests as $playerId => [$player, $readyTick, $mapIds]){
			if(!$player->isConnected()){
				unset($this->pendingRequests[$playerId]);
			}elseif($player->spawned){
				if($readyTick === null){
					$this->pendingRequests[$playerId][1] = $this->currentTick + self::SPAWN_ANSWER_DELAY_TICKS;
				}elseif($this->currentTick >= $readyTick){
					unset($this->pendingRequests[$playerId]);
					foreach($mapIds as $mapId => $_){
						$this->handleMapInfoRequest($player, $mapId);
					}
				}
			}
		}
	}

	private function createPacket(MapData $map) : ClientboundMapItemDataPacket{
		$packet = new ClientboundMapItemDataPacket();
		$packet->mapId = $map->getId();
		$packet->dimensionId = $map->getDimension();
		$packet->isLocked = $map->isLocked();
		$packet->origin = new BlockPosition($map->getCenterX(), 0, $map->getCenterZ());
		$packet->scale = $map->getScale();
		return $packet;
	}

	private function sendFullMap(Player $player, MapData $map) : void{
		//BDS sends the creation data and the map data as separate packets (MapInfoRequestPacket.json, 1.26.51)
		$creation = $this->createPacket($map);
		$creation->parentMapIds = [$map->getId()];
		$player->getNetworkSession()->sendDataPacket($creation);

		$data = $this->createPacket($map);
		$data->colors = MapImage::fromRGBABytes(MapData::SIZE, MapData::SIZE, $map->getPixels());
		$this->setDecorations($data, $map->getId());
		$player->getNetworkSession()->sendDataPacket($data);
	}

	private function setDecorations(ClientboundMapItemDataPacket $packet, int $mapId) : void{
		$packet->type |= ClientboundMapItemDataPacket::BITFLAG_DECORATION_UPDATE;
		$packet->decorations = $this->decorations[$mapId] ?? [];
		$map = $this->maps[$mapId] ?? null;
		if($map !== null){
			//fixed markers after the player markers, which match the tracked entities one to one
			foreach($map->getDecorations() as [$type, $x, $z]){
				$marker = self::createFixedMarker($map, $type, $x, $z);
				if($marker !== null){
					$packet->decorations[] = $marker;
				}
			}
		}
		$packet->trackedEntities = [];
		foreach($this->decorationActors[$mapId] ?? [] as $actorId){
			$object = new MapTrackedObject();
			$object->type = MapTrackedObject::TYPE_ENTITY;
			$object->actorUniqueId = $actorId;
			$packet->trackedEntities[] = $object;
		}
	}

	/**
	 * @return Player[]
	 * @phpstan-return array<int, Player>
	 */
	private function getViewers(int $mapId) : array{
		$viewers = $this->viewers[$mapId] ?? [];
		foreach($viewers as $playerId => $player){
			if(!$player->isConnected()){
				unset($this->viewers[$mapId][$playerId], $this->lastFullSend[$mapId][$playerId]);
				unset($viewers[$playerId]);
			}
		}
		return $viewers;
	}

	/**
	 * Returns the map pixel a world position is on (may be outside the map).
	 *
	 * @return int[]
	 * @phpstan-return array{int, int}
	 */
	public static function getPixelPosition(MapData $map, float $x, float $z) : array{
		$blocksPerPixel = 1 << $map->getScale();
		return [
			(int) floor(($x - $map->getCenterX()) / $blocksPerPixel) + (MapData::SIZE >> 1),
			(int) floor(($z - $map->getCenterZ()) / $blocksPerPixel) + (MapData::SIZE >> 1)
		];
	}

	/**
	 * Returns the marker of a player on a map, or null if the player is too far away to be shown.
	 */
	public static function createPlayerMarker(MapData $map, float $x, float $z, float $yaw) : ?MapDecoration{
		$blocksPerPixel = 1 << $map->getScale();
		//decoration positions are in half pixels from the centre of the map, as signed bytes
		$offsetX = ($x - $map->getCenterX()) / $blocksPerPixel;
		$offsetZ = ($z - $map->getCenterZ()) / $blocksPerPixel;
		$halfSize = MapData::SIZE >> 1;
		$rotation = ((int) floor($yaw * 16 / 360 + 0.5)) & 0x0f;
		if(abs($offsetX) <= $halfSize - 1 && abs($offsetZ) <= $halfSize - 1){
			$type = MapDecorationType::MARKER_WHITE;
		}elseif(abs($offsetX) <= self::OFF_MAP_MARKER_RANGE && abs($offsetZ) <= self::OFF_MAP_MARKER_RANGE){
			//off the map but near: small marker at the edge, without direction
			$type = MapDecorationType::SQUARE_WHITE;
			$rotation = 0;
			$offsetX = max(-$halfSize, min($halfSize - 1, $offsetX));
			$offsetZ = max(-$halfSize, min($halfSize - 1, $offsetZ));
		}else{
			return null;
		}
		$byteX = max(-128, min(127, (int) floor($offsetX * 2 + 0.5)));
		$byteZ = max(-128, min(127, (int) floor($offsetZ * 2 + 0.5)));
		return new MapDecoration($type, $rotation, $byteX & 0xff, $byteZ & 0xff, "", new Color(255, 255, 255));
	}

	/**
	 * Returns a fixed marker (structure of an explorer map...) at a world position, or null if it is off the map.
	 */
	public static function createFixedMarker(MapData $map, int $type, int $x, int $z) : ?MapDecoration{
		$blocksPerPixel = 1 << $map->getScale();
		$offsetX = ($x + 0.5 - $map->getCenterX()) / $blocksPerPixel;
		$offsetZ = ($z + 0.5 - $map->getCenterZ()) / $blocksPerPixel;
		$halfSize = MapData::SIZE >> 1;
		if(abs($offsetX) > $halfSize - 1 || abs($offsetZ) > $halfSize - 1){
			return null;
		}
		$byteX = max(-128, min(127, (int) floor($offsetX * 2 + 0.5)));
		$byteZ = max(-128, min(127, (int) floor($offsetZ * 2 + 0.5)));
		return new MapDecoration($type, 0, $byteX & 0xff, $byteZ & 0xff, "", new Color(255, 255, 255));
	}

	public function tick(int $currentTick) : void{
		$this->currentTick = $currentTick;
		$this->timings->startTiming();
		try{
			foreach($this->pendingInventorySyncs as $player){
				if($player->isConnected()){
					$player->getNetworkSession()->getInvManager()?->syncContents($player->getInventory());
				}
			}
			$this->pendingInventorySyncs = [];
			if(count($this->pendingRequests) > 0){
				$this->answerPendingRequests();
			}

			$holders = $this->drawForHolders();
			$this->sendTextureUpdates();
			if($currentTick % self::DECORATION_INTERVAL_TICKS === 0){
				$this->updateDecorations($holders);
			}
			if($currentTick % self::SAVE_INTERVAL_TICKS === 0){
				$this->saveAll();
				$this->unloadUnused();
			}
		}finally{
			$this->timings->stopTiming();
		}
	}

	/**
	 * Draws the terrain around players holding a map, and returns the players holding each map.
	 *
	 * @return Player[][]
	 * @phpstan-return array<int, list<array{Player, bool}>> map ID => [player, whether the held map shows players]
	 */
	private function drawForHolders() : array{
		$holders = [];
		$drawers = [];
		foreach(Server::getInstance()->getOnlinePlayers() as $player){
			if(!$player->isAlive() || $player->isSpectator()){
				continue;
			}
			//the held items are cloned only when they are maps (rarely): checking the type of all of them every tick
			//through getItemInHand() / getItem() would clone two items per player. A PlayerInventory subclass may
			//override getItem(), so its held item is fetched as before.
			$inventory = $player->getInventory();
			$hand = $inventory::class !== PlayerInventory::class || $inventory->isSlotInstanceOf($inventory->getHeldItemIndex(), FilledMap::class) ?
				$inventory->getItemInHand() : null;
			$offHandInventory = $player->getOffHandInventory(); //a final class: getItem() cannot be overridden
			$offHand = $offHandInventory->isSlotInstanceOf(0, FilledMap::class) ? $offHandInventory->getItem(0) : null;
			foreach([$hand, $offHand] as $item){
				if(!$item instanceof FilledMap || $item->getMapId() === 0){
					continue;
				}
				$map = $this->getMap($item->getMapId());
				if($map === null || $map->getWorldName() !== $player->getWorld()->getFolderName()){
					continue;
				}
				$holders[$map->getId()][] = [$player, $item->displaysPlayers()];
				if(!$map->isLocked()){
					$drawers[] = [$player, $map];
				}
				break;
			}
		}

		$count = count($drawers);
		if($count > 0){
			$deadline = hrtime(true) + self::TICK_TIME_BUDGET_NS;
			$this->firstHolder = ($this->firstHolder + 1) % $count;
			for($i = 0; $i < $count; ++$i){
				[$player, $map] = $drawers[($this->firstHolder + $i) % $count];
				if(!$this->drawAround($player, $map, $deadline)){
					break;
				}
			}
		}
		return $holders;
	}

	/**
	 * Draws the next columns of a map around a player. Returns false if the time budget ran out.
	 */
	private function drawAround(Player $player, MapData $map, int $deadline) : bool{
		$position = $player->getPosition();
		[$pixelX, $pixelY] = self::getPixelPosition($map, $position->x, $position->z);
		$radius = MapRenderer::getMapUpdateRadius($map);
		$minColumn = max(0, $pixelX - $radius + 1);
		$maxColumn = min(MapData::SIZE - 1, $pixelX + $radius - 1);
		if($minColumn > $maxColumn || $pixelY + $radius < 0 || $pixelY - $radius >= MapData::SIZE){
			return true; //too far from the map
		}

		$world = $player->getWorld();
		$getChunk = fn(int $chunkX, int $chunkZ) => $world->getChunk($chunkX, $chunkZ);
		$playerId = spl_object_id($player);
		$column = $this->drawCursors[$playerId] ?? $minColumn;
		for($i = 0; $i < self::COLUMNS_PER_HOLDER; ++$i){
			if($column < $minColumn || $column > $maxColumn){
				$column = $minColumn;
			}
			$this->renderer->renderColumn($map, $getChunk, $column, $pixelX, $pixelY);
			++$column;
			if(hrtime(true) >= $deadline){
				$this->drawCursors[$playerId] = $column;
				return false;
			}
		}
		$this->drawCursors[$playerId] = $column;
		return true;
	}

	private function sendTextureUpdates() : void{
		foreach($this->maps as $mapId => $map){
			$area = $map->takeDirtyArea();
			if($area === null){
				continue;
			}
			$viewers = $this->getViewers($mapId);
			if(count($viewers) === 0){
				continue;
			}
			[$x, $y, $width, $height] = $area;
			$packet = $this->createPacket($map);
			$packet->xOffset = $x;
			$packet->yOffset = $y;
			$packet->colors = MapImage::fromRGBABytes($width, $height, $map->getPixelArea($x, $y, $width, $height));
			foreach($viewers as $viewer){
				$viewer->getNetworkSession()->sendDataPacket($packet);
			}
		}
	}

	/**
	 * @phpstan-param array<int, list<array{Player, bool}>> $holders
	 */
	private function updateDecorations(array $holders) : void{
		foreach($this->viewers as $mapId => $_){
			$map = $this->maps[$mapId] ?? null;
			if($map === null){
				continue;
			}
			//also forgets the players that left, so that they aren't kept until the map changes
			$viewers = $this->getViewers($mapId);
			$decorations = [];
			$actors = [];
			$signature = "";
			foreach($holders[$mapId] ?? [] as [$player, $displayPlayers]){
				if(!$displayPlayers){
					continue;
				}
				$location = $player->getLocation();
				$marker = self::createPlayerMarker($map, $location->x, $location->z, $location->yaw);
				if($marker !== null){
					$decorations[] = $marker;
					$actors[] = $player->getId();
					$signature .= $player->getId() . ":" . $marker->getIcon() . ":" . $marker->getRotation() . ":" . $marker->getXOffset() . ":" . $marker->getYOffset() . ";";
				}
			}
			if($signature === ($this->decorationSignatures[$mapId] ?? "")){
				continue;
			}
			$this->decorationSignatures[$mapId] = $signature;
			$this->decorations[$mapId] = $decorations;
			$this->decorationActors[$mapId] = $actors;

			$packet = $this->createPacket($map);
			$this->setDecorations($packet, $mapId);
			foreach($viewers as $viewer){
				$viewer->getNetworkSession()->sendDataPacket($packet);
			}
		}
	}

	/**
	 * Saves the maps that changed since they were last saved.
	 */
	public function saveAll() : void{
		foreach($this->maps as $map){
			if($map->hasChanged()){
				$this->store->save($map);
			}
		}
	}

	private function unloadUnused() : void{
		foreach($this->maps as $mapId => $map){
			if(
				$this->currentTick - ($this->lastUsed[$mapId] ?? 0) >= self::SAVE_INTERVAL_TICKS &&
				count($this->getViewers($mapId)) === 0 &&
				!$map->hasChanged()
			){
				unset($this->maps[$mapId], $this->lastUsed[$mapId], $this->viewers[$mapId], $this->lastFullSend[$mapId], $this->decorations[$mapId], $this->decorationActors[$mapId], $this->decorationSignatures[$mapId]);
			}
		}
		foreach($this->drawCursors as $playerId => $_){
			//cursors are cheap; drop them now and then so that they don't pile up when players leave
			unset($this->drawCursors[$playerId]);
		}
	}
}
