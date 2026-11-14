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

namespace pocketmine\player;

use pocketmine\block\CarvedPumpkin;
use pocketmine\block\MobHead;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemBlockWallOrFloor;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\PlayerLocationPacket;
use pocketmine\world\format\Chunk;
use pocketmine\world\GameRules;
use pocketmine\world\World;
use function count;
use function spl_object_id;

/**
 * Feeds the locator bar (game rule locatorbar): the client shows the players it has spawned itself, and the server
 * sends PlayerLocationPacket coordinates for the other players of the same world who are outside the viewer's entity
 * view, and hides them again when they leave, become hidden or the rule is turned off.
 *
 * Runs every UPDATE_INTERVAL ticks: one coordinates packet per player who moved at least a block since the last one,
 * shared by all the viewers that need it, and nothing for players who stand still. Viewers farther than
 * FAR_DISTANCE_SQ only get it when the player has entered another chunk since they were last sent it. Players who
 * sneak, are invisible, are spectators or wear a carved pumpkin or a mob head are hidden, like vanilla, and players
 * hidden from a viewer with Player::hidePlayer() are hidden from that viewer.
 *
 * @internal
 */
final class LocatorBarUpdater{
	public const UPDATE_INTERVAL = 20;
	/** Squared distance a player has to move before its coordinates are sent again. */
	private const MIN_MOVE_DISTANCE_SQ = 1.0;

	/**
	 * Position sent last for each player, by entity ID.
	 * @var float[][]
	 * @phpstan-var array<int, array{float, float, float}>
	 */
	private array $lastPositions = [];
	/**
	 * Players farther than this from a viewer are only re-sent to it when they enter another chunk: at that distance
	 * a block makes no visible difference on the bar (vanilla Java switches from block to chunk and azimuth precision
	 * beyond the same distance).
	 */
	private const FAR_DISTANCE_SQ = 332 * 332;
	/**
	 * Chunk of the coordinates sent last to the far viewers of each player, by entity ID.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $farChunks = [];

	/** Not moved since its coordinates were last sent */
	private const STILL = 0;
	/** Moved, but within the chunk its far viewers have: only near viewers need it */
	private const MOVED_NEAR = 1;
	/** Moved into another chunk: everyone needs it */
	private const MOVED_FAR = 2;

	/**
	 * Players each viewer currently has coordinates for: viewer entity ID => player entity ID => true.
	 * @var true[][]
	 * @phpstan-var array<int, array<int, true>>
	 */
	private array $shown = [];
	/**
	 * World of the waypoints each viewer has, by viewer entity ID.
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $shownWorld = [];

	/**
	 * Whether a player has hidden anyone with Player::hidePlayer(), read without encoding UUIDs.
	 * @phpstan-var \Closure(Player) : bool
	 */
	private \Closure $hasHiddenPlayers;

	public function __construct(){
		$this->hasHiddenPlayers = \Closure::bind(static fn(Player $player) : bool => count($player->hiddenPlayers) > 0, null, Player::class);
	}

	/**
	 * @param World[] $worlds
	 * @phpstan-param iterable<World> $worlds
	 */
	public function update(iterable $worlds) : void{
		$seenPlayers = [];
		foreach($worlds as $world){
			$this->updateWorld($world, $seenPlayers);
		}
		//players who left the server: their state goes with them (the client drops the waypoints on disconnect)
		foreach($this->shown as $viewerId => $_){
			if(!isset($seenPlayers[$viewerId])){
				unset($this->shown[$viewerId], $this->shownWorld[$viewerId]);
			}
		}
		foreach($this->lastPositions as $playerId => $_){
			if(!isset($seenPlayers[$playerId])){
				unset($this->lastPositions[$playerId], $this->farChunks[$playerId]);
			}
		}
	}

	/**
	 * @phpstan-param array<int, true> $seenPlayers
	 */
	private function updateWorld(World $world, array &$seenPlayers) : void{
		$players = $world->getPlayers();
		if(count($players) === 0){
			return;
		}
		$worldId = $world->getId();
		$enabled = count($players) > 1 && $world->getGameRule(GameRules::LOCATOR_BAR) === true;

		/** @phpstan-var array<int, self::STILL|self::MOVED_NEAR|self::MOVED_FAR> $visible entity ID => how it moved since its coordinates were last sent */
		$visible = [];
		/** @phpstan-var array<int, float> $positionsX */
		$positionsX = [];
		/** @phpstan-var array<int, float> $positionsZ */
		$positionsZ = [];
		/** @phpstan-var array<int, array<int, Player>> $spawnedTo entity ID => the players it is spawned to, by spl_object_id */
		$spawnedTo = [];
		if($enabled){
			foreach($players as $id => $player){
				if(self::isHidden($player)){
					continue;
				}
				$spawnedTo[$id] = $player->getViewers();
				$pos = $player->getPosition();
				$last = $this->lastPositions[$id] ?? null;
				if($last === null || (($pos->x - $last[0]) ** 2 + ($pos->y - $last[1]) ** 2 + ($pos->z - $last[2]) ** 2) >= self::MIN_MOVE_DISTANCE_SQ){
					$this->lastPositions[$id] = [$pos->x, $pos->y, $pos->z];
					$chunkHash = World::chunkHash($pos->getFloorX() >> Chunk::COORD_BIT_SIZE, $pos->getFloorZ() >> Chunk::COORD_BIT_SIZE);
					if(($this->farChunks[$id] ?? null) !== $chunkHash){
						$this->farChunks[$id] = $chunkHash;
						$visible[$id] = self::MOVED_FAR;
					}else{
						$visible[$id] = self::MOVED_NEAR;
					}
				}else{
					$visible[$id] = self::STILL;
				}
				$positionsX[$id] = $pos->x;
				$positionsZ[$id] = $pos->z;
			}
		}

		/** @phpstan-var array<int, array<int, Player>> $recipients entity ID => viewers who need its coordinates */
		$recipients = [];
		/** @phpstan-var array<int, list<int>> $sendIds entity ID => send IDs of those viewers */
		$sendIds = [];
		/** @phpstan-var array<int, array<int, Player>> $hideRecipients entity ID => viewers who must drop it */
		$hideRecipients = [];
		/** @phpstan-var array<int, list<int>> $hideSendIds entity ID => send IDs of those viewers */
		$hideSendIds = [];
		foreach($players as $viewerId => $viewer){
			$seenPlayers[$viewerId] = true;
			if(!$viewer->isConnected()){
				continue;
			}
			$old = $this->shown[$viewerId] ?? [];
			if(count($old) === 0 && (count($visible) === 0 || (count($visible) === 1 && isset($visible[$viewerId])))){
				continue; //nothing shown and nobody else to show
			}
			$sendId = $viewer->getNetworkSession()->getSendId();
			if(($this->shownWorld[$viewerId] ?? $worldId) !== $worldId){
				//changed world: the waypoints of the old world are meaningless here
				foreach($old as $id => $_){
					$hideRecipients[$id][$viewerId] = $viewer;
					$hideSendIds[$id][] = $sendId;
				}
				$old = [];
			}
			$viewerKey = spl_object_id($viewer);
			//canSee() encodes the target's UUID on every call: only ask it for viewers who hid someone
			$viewerHides = ($this->hasHiddenPlayers)($viewer);
			$viewerPos = $viewer->getPosition();
			$viewerX = $viewerPos->x;
			$viewerZ = $viewerPos->z;

			$new = [];
			foreach($visible as $id => $moved){
				if($id === $viewerId){
					continue;
				}
				if(isset($spawnedTo[$id][$viewerKey])){
					continue; //spawned for the viewer: the client tracks it itself
				}
				if($viewerHides && !$viewer->canSee($players[$id])){
					continue; //hidden from this viewer with Player::hidePlayer()
				}
				$new[$id] = true;
				if($moved === self::MOVED_FAR || !isset($old[$id])){
					$recipients[$id][$viewerId] = $viewer;
					$sendIds[$id][] = $sendId;
				}elseif($moved === self::MOVED_NEAR){
					//far viewers keep the coordinates in the old chunk: no visible change on the bar
					$dx = $positionsX[$id] - $viewerX;
					$dz = $positionsZ[$id] - $viewerZ;
					if($dx * $dx + $dz * $dz <= self::FAR_DISTANCE_SQ){
						$recipients[$id][$viewerId] = $viewer;
						$sendIds[$id][] = $sendId;
					}
				}
			}
			foreach($old as $id => $_){
				if(!isset($new[$id]) && !isset($spawnedTo[$id][$viewerKey])){
					//gone, hidden or the rule was turned off (but not merely come into view)
					$hideRecipients[$id][$viewerId] = $viewer;
					$hideSendIds[$id][] = $sendId;
				}
			}

			if(count($new) > 0){
				$this->shown[$viewerId] = $new;
				$this->shownWorld[$viewerId] = $worldId;
			}else{
				unset($this->shown[$viewerId], $this->shownWorld[$viewerId]);
			}
		}

		//one packet per player, encoded once for all the viewers that need it
		foreach($hideRecipients as $id => $viewers){
			NetworkBroadcastUtils::broadcastPackets($viewers, [PlayerLocationPacket::createHide($id)], $hideSendIds[$id]);
		}
		foreach($recipients as $id => $viewers){
			NetworkBroadcastUtils::broadcastPackets($viewers, [PlayerLocationPacket::createCoordinates($id, $players[$id]->getPosition()->asVector3())], $sendIds[$id]);
		}
	}

	/**
	 * Whether the player is left out of other players' locator bars.
	 */
	public static function isHidden(Player $player) : bool{
		if(!$player->isAlive() || $player->isSpectator() || $player->isSneaking() || $player->isInvisible()){
			return true;
		}
		$armor = $player->getArmorInventory();
		if($armor->isSlotInstanceOf(ArmorInventory::SLOT_HEAD, ItemBlock::class) || $armor->isSlotInstanceOf(ArmorInventory::SLOT_HEAD, ItemBlockWallOrFloor::class)){
			$block = $armor->getHelmet()->getBlock();
			return $block instanceof CarvedPumpkin || $block instanceof MobHead;
		}
		return false;
	}
}
