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

namespace pocketmine\network\mcpe;

use pocketmine\world\dimension\Dimension;

/**
 * @internal
 * Keeps track of the dimension the client renders and decides which ChangeDimension packets a world switch needs.
 *
 * The client only drops its terrain when it changes dimension. A switch between two worlds of different dimensions is
 * a single ChangeDimension. A switch between two worlds of the SAME dimension (two Overworld worlds, e.g. lobby ->
 * survival) used to send nothing: the new world's chunks overwrote the old ones where they arrived, but every chunk the
 * new world didn't send (outside its view area, void worlds, chunks still generating) kept showing the previous world
 * ("ghost chunks").
 *
 * Chosen fix: a double dimension change, as done by proxies that move players between servers (WaterdogPE): first a
 * "detour" to another dimension (the Nether, or the Overworld when the target is the Nether/End) with a few empty chunks and
 * an immediate server ack, and once the client acknowledges it, the real change back to the target dimension, which then
 * follows the normal flow (world chunks, server ack after the first chunk, loading screen end). The client shows its
 * dimension loading screen while the new world's terrain arrives, like vanilla.
 * The alternative without a loading screen (sending an empty chunk for every old chunk the new world doesn't cover) was
 * rejected: the old terrain stays visible next to the new one until each new chunk arrives, the player can fall through
 * empty chunks under its feet, and the server would have to know exactly which chunks the client still keeps (its own
 * view distance and cache can differ from the server's).
 *
 * The detour never overlaps another change: the client could not tell which ChangeDimension it is acknowledging. A world
 * switch during the detour, or a same-dimension switch while the client is still loading a world whose chunks already
 * arrived, is remembered and handled once the change in progress ends (the latest world wins). While the detour or such
 * a deferred switch is pending, the session must not send world chunks ({@link ClientDimensionTracker::holdsChunks()}).
 * A switch to another dimension during an ordinary change is still sent right away, as it always was.
 */
final class ClientDimensionTracker{
	private ?Dimension $clientDimension = null;
	/** Whether chunks were sent to the client since it last changed dimension (or since StartGame). */
	private bool $hasTerrain = false;

	private bool $changing = false;
	private ?int $loadingScreenId = null;
	private bool $detour = false;
	private int $nextLoadingScreenId = 0;
	/**
	 * Set when a change finished on the loading screen end and the next change started right away: the client also
	 * sends a DIMENSION_CHANGE_ACK for the finished change, which must not be taken for the end of the new one.
	 */
	private bool $ignoreNextAck = false;

	/** Dimension of the world the player is in, when a world switch still has to be handled after the current change. */
	private ?Dimension $pendingTarget = null;

	public function __construct(
		private bool $clearSameDimensionTerrain = true
	){}

	/**
	 * Records the dimension the client was put in by StartGamePacket.
	 */
	public function onStartGame(Dimension $dimension) : void{
		$this->clientDimension = $dimension;
		$this->hasTerrain = false;
		$this->changing = false;
		$this->detour = false;
		$this->loadingScreenId = null;
		$this->pendingTarget = null;
		$this->ignoreNextAck = false;
	}

	public function getClientDimension() : ?Dimension{
		return $this->clientDimension;
	}

	public function isChanging() : bool{
		return $this->changing;
	}

	/**
	 * Whether the change in progress is the detour of a same-dimension world switch.
	 */
	public function isDetour() : bool{
		return $this->detour;
	}

	public function getLoadingScreenId() : ?int{
		return $this->loadingScreenId;
	}

	/**
	 * Whether world chunks must not be sent now: the client is going through the detour dimension, or the world it will
	 * end up in is not decided until the current change ends.
	 */
	public function holdsChunks() : bool{
		return $this->detour || $this->pendingTarget !== null;
	}

	public function setClearSameDimensionTerrain(bool $clear) : void{
		$this->clearSameDimensionTerrain = $clear;
	}

	public function getClearSameDimensionTerrain() : bool{
		return $this->clearSameDimensionTerrain;
	}

	/**
	 * Called when a world chunk was sent to the client.
	 */
	public function onTerrainSent() : void{
		$this->hasTerrain = true;
	}

	/**
	 * Called when the player enters a world of the given dimension.
	 *
	 * @param bool $respawn     whether the player is dead (the change happens on the respawn screen)
	 * @param bool $allowDetour whether a same-dimension switch may use the detour (false before the client spawned)
	 *
	 * @return ClientDimensionChange|null the ChangeDimension to send now, if any
	 */
	public function onEnterWorld(Dimension $target, bool $respawn, bool $allowDetour) : ?ClientDimensionChange{
		if($this->clientDimension === null){
			return null; //StartGame not sent yet: it will carry the right dimension
		}
		if($this->changing){
			if($this->detour || $this->pendingTarget !== null){
				//the client is on its way to the detour dimension, or a switch is already waiting: the latest world wins
				$this->pendingTarget = $target;
				return null;
			}
			if($this->clientDimension !== $target){
				//as before same-dimension switches were handled: a change to another dimension is sent right away
				return $this->start($target, $respawn, false);
			}
			if($this->hasTerrain && $this->clearSameDimensionTerrain && $allowDetour){
				//chunks of the world the client is loading already arrived: detour once this change ends
				$this->pendingTarget = $target;
			}
			return null;
		}
		return $this->plan($target, $respawn, $allowDetour);
	}

	/**
	 * Called when the client reports the end of the dimension change: its own DIMENSION_CHANGE_ACK (no loading screen
	 * ID) or the end of the loading screen.
	 *
	 * @param bool $respawn     whether the player is dead now, for the change that may follow
	 * @param bool $allowDetour see {@link ClientDimensionTracker::onEnterWorld()}
	 *
	 * @return ClientDimensionChange|null the next ChangeDimension to send now (second half of a same-dimension switch,
	 *                                    or a world switch made during the change)
	 */
	public function onClientChangeDone(?int $loadingScreenId, bool $respawn, bool $allowDetour) : ?ClientDimensionChange{
		if(!$this->changing){
			return null;
		}
		if($loadingScreenId !== null && $this->loadingScreenId !== null && $loadingScreenId !== $this->loadingScreenId){
			return null; //end of an older loading screen
		}
		if($loadingScreenId === null && $this->ignoreNextAck){
			$this->ignoreNextAck = false;
			return null; //late ack of the previous change
		}
		$next = $this->finishChange($respawn, $allowDetour);
		//the client sends both an ack and the loading screen end: if the change ended on the latter, the ack is still
		//coming. Only swallow it when the next change can also end on its own loading screen end.
		$this->ignoreNextAck = $next !== null && $loadingScreenId !== null && $next->loadingScreenId !== null;
		return $next;
	}

	/**
	 * Ends the change in progress without the client's confirmation (it never reported it).
	 */
	public function forceChangeDone(bool $respawn, bool $allowDetour) : ?ClientDimensionChange{
		if(!$this->changing){
			return null;
		}
		$this->ignoreNextAck = false;
		return $this->finishChange($respawn, $allowDetour);
	}

	private function finishChange(bool $respawn, bool $allowDetour) : ?ClientDimensionChange{
		$this->changing = false;
		$this->detour = false;
		$this->loadingScreenId = null;
		if($this->pendingTarget === null){
			return null;
		}
		$target = $this->pendingTarget;
		$this->pendingTarget = null;
		return $this->plan($target, $respawn, $allowDetour);
	}

	private function plan(Dimension $target, bool $respawn, bool $allowDetour) : ?ClientDimensionChange{
		if($this->clientDimension !== $target){
			return $this->start($target, $respawn, false);
		}
		if($this->hasTerrain && $this->clearSameDimensionTerrain && $allowDetour){
			$this->pendingTarget = $target;
			return $this->start(self::getDetourDimension($target), $respawn, true);
		}
		return null;
	}

	private function start(Dimension $dimension, bool $respawn, bool $detour) : ClientDimensionChange{
		$this->clientDimension = $dimension;
		$this->hasTerrain = false;
		$this->changing = true;
		$this->detour = $detour;
		//Mojang: the loading screen ID is only given for a change while the player is alive
		$this->loadingScreenId = $respawn ? null : ($this->nextLoadingScreenId = ($this->nextLoadingScreenId + 1) & 0xffffffff);
		return new ClientDimensionChange($dimension, $respawn, $this->loadingScreenId, $detour);
	}

	/**
	 * Dimension the client passes through to drop its terrain when switching between two worlds of the given dimension.
	 */
	public static function getDetourDimension(Dimension $target) : Dimension{
		return $target === Dimension::OVERWORLD ? Dimension::NETHER : Dimension::OVERWORLD;
	}
}
