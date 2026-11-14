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

use pmmp\encoding\ByteBufferWriter;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;
use pocketmine\timings\Timings;
use function array_intersect_key;
use function count;
use function implode;
use function method_exists;
use function spl_object_id;
use function strlen;

final class NetworkBroadcastUtils{
	/**
	 * Whether the native send buffers have selectMovement() (chunkutils2 >= 0.3.10); null = not checked yet. See
	 * {@link NetworkBroadcastUtils::getNativeMovementSelector()}.
	 */
	private static ?bool $nativeMovementSelection = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * @param Player[]            $recipients
	 * @param ClientboundPacket[] $packets
	 * @param int[]|null          $sendIds    native send buffer ids of the recipients (NetworkSession::getSendId()), when
	 *                                        the caller has them at hand (entities keep their viewers'): then the packets
	 *                                        reach every recipient without a single call per recipient
	 * @phpstan-param array<int, int>|null $sendIds
	 */
	public static function broadcastPackets(array $recipients, array $packets, ?array $sendIds = null) : bool{
		return self::broadcast($recipients, [], $sendIds, $packets);
	}

	/**
	 * Whether {@link NetworkBroadcastUtils::broadcastPackets()} appends packets below the compression threshold
	 * straight to the recipients' native send buffers. Callers which pick the recipients themselves (entity movement,
	 * by distance) then only need to collect the recipients' send ids for
	 * {@link NetworkBroadcastUtils::broadcastPacketsToSendIds()}.
	 *
	 * @internal
	 */
	public static function canAppendToNativeSendBuffers() : bool{
		return NetworkSession::getNativeSendBuffers() !== null && NetworkSession::hasOnlyStandardSessions() && !DataPacketSendEvent::hasHandlers();
	}

	/**
	 * The native send buffers when {@link NetworkBroadcastUtils::canAppendToNativeSendBuffers()} holds and they can
	 * also pick the recipients of an entity's movement themselves, null otherwise. chunkutils2 >= 0.3.10 has
	 * NativeSendBuffers::selectMovement(): the viewer loop of Player::broadcastMovement() and Mob::broadcastMovement()
	 * in C++, giving the send ids that loop collects for
	 * {@link NetworkBroadcastUtils::broadcastPacketsToSendIds()} (or -1 when the loop must run: e.g. a viewer class
	 * overriding getId()).
	 *
	 * @internal
	 */
	public static function getNativeMovementSelector() : ?NativeSendBuffers{
		$native = NetworkSession::getNativeSendBuffers();
		if($native === null || !NetworkSession::hasOnlyStandardSessions() || DataPacketSendEvent::hasHandlers()){
			return null;
		}
		//older chunkutils2 versions lack it (the PHPStan stub always has it)
		/** @phpstan-ignore function.alreadyNarrowedType */
		return (self::$nativeMovementSelection ??= method_exists($native, "selectMovement")) ? $native : null;
	}

	/**
	 * @internal for tests: false makes {@link NetworkBroadcastUtils::getNativeMovementSelector()} return null, so that
	 * the entities' PHP viewer loops run with the native send buffers too; true uses selectMovement() again when the
	 * native send buffers have it.
	 */
	public static function setNativeMovementSelectionEnabled(bool $enabled) : void{
		self::$nativeMovementSelection = $enabled ? null : false;
	}

	/**
	 * Same as {@link NetworkBroadcastUtils::broadcastPackets()} for recipients picked from an entity's viewers and
	 * given only as their send ids, keyed like the viewers (Entity::$hasSpawned and Entity::$viewerSendIds) and in
	 * their order. The list of players is only made when the packets can't simply be appended to the native send
	 * buffers (they are big enough to be compressed once for everyone, or the conditions of
	 * {@link NetworkBroadcastUtils::canAppendToNativeSendBuffers()} changed meanwhile).
	 *
	 * @param Player[]            $viewers
	 * @param int[]               $sendIds
	 * @param ClientboundPacket[] $packets
	 * @phpstan-param array<int, Player> $viewers
	 * @phpstan-param array<int, int>    $sendIds
	 *
	 * @internal
	 */
	public static function broadcastPacketsToSendIds(array $viewers, array $sendIds, array $packets) : bool{
		return self::broadcast(null, $viewers, $sendIds, $packets);
	}

	/**
	 * @param Player[]|null       $recipients null: the viewers whose keys are in $sendIds
	 * @param Player[]            $viewers
	 * @param int[]|null          $sendIds
	 * @param ClientboundPacket[] $packets
	 * @phpstan-param array<int, Player>|null $recipients
	 * @phpstan-param array<int, Player>      $viewers
	 * @phpstan-param array<int, int>|null    $sendIds
	 */
	private static function broadcast(?array $recipients, array $viewers, ?array $sendIds, array $packets) : bool{
		if(count($packets) === 0){
			throw new \InvalidArgumentException("Cannot broadcast empty list of packets");
		}

		//called for every entity movement, to every viewer: one pass over the recipients, no closure, and no grouping
		//unless the recipients really use different broadcasters (a plugin replacing it for some sessions)
		Timings::$broadcastPackets->startTiming();
		try{
			$native = NetworkSession::getNativeSendBuffers();
			if($native !== null && NetworkSession::hasOnlyStandardSessions() && !DataPacketSendEvent::hasHandlers()){
				//the send buffers live in C++: the packets are encoded once and appended to all the recipients' buffers
				//in one call (what StandardPacketBroadcaster does for batches below the compression threshold)
				$totalLength = 0;
				$packetBuffers = [];
				$writer = new ByteBufferWriter();
				foreach($packets as $packet){
					$writer->clear();
					$buffer = NetworkSession::prefixPacketBuffer(NetworkSession::encodePacketTimed($writer, $packet));
					$totalLength += strlen($buffer);
					$packetBuffers[] = $buffer;
				}
				$threshold = ZlibCompressor::getInstance()->getCompressionThreshold();
				if($threshold === null || $totalLength < $threshold || count($recipients ?? $sendIds ?? []) <= 1){
					if($sendIds === null){
						$sendIds = [];
						foreach($recipients ?? [] as $player){
							$session = $player->getConnectedNetworkSession();
							if($session !== null){
								$sendIds[] = $session->getSendId();
							}
						}
					}
					if(count($sendIds) === 0){
						return false;
					}
					$native->appendMany($sendIds, count($packetBuffers) === 1 ? $packetBuffers[0] : implode("", $packetBuffers));
					return true;
				}
				//big enough to be compressed once for everyone: the standard path below does that
			}

			//recipients given as send ids: the players, in the viewers' order
			$recipients ??= array_intersect_key($viewers, $sendIds ?? []);

			if(NetworkSession::hasOnlyStandardSessions()){
				//every session has the same kind of broadcaster: one call per recipient
				/**
				 * @var NetworkSession[] $targets
				 * @phpstan-var array<int, NetworkSession> $targets
				 */
				$targets = [];
				$first = null;
				foreach($recipients as $player){
					$session = $player->getConnectedNetworkSession();
					if($session !== null){
						$targets[spl_object_id($session)] = $session;
						$first ??= $session;
					}
				}
				if($first === null){
					return false;
				}
				$first->getBroadcaster()->broadcastPackets($targets, $packets);
				return true;
			}

			$broadcaster = null;
			$mixed = false;
			/**
			 * @var NetworkSession[] $targets
			 * @phpstan-var array<int, NetworkSession> $targets
			 */
			$targets = [];
			foreach($recipients as $player){
				if($player->isConnected()){
					$recipient = $player->getNetworkSession();
					$recipientBroadcaster = $recipient->getBroadcaster();
					if($broadcaster === null){
						$broadcaster = $recipientBroadcaster;
					}elseif($recipientBroadcaster !== $broadcaster){
						$mixed = true;
					}
					$targets[spl_object_id($recipient)] = $recipient;
				}
			}
			if($broadcaster === null){
				return false;
			}
			if(!$mixed){
				$broadcaster->broadcastPackets($targets, $packets);
				return true;
			}

			/**
			 * @var PacketBroadcaster[] $uniqueBroadcasters
			 * @phpstan-var array<int, PacketBroadcaster> $uniqueBroadcasters
			 */
			$uniqueBroadcasters = [];
			/**
			 * @var NetworkSession[][] $broadcasterTargets
			 * @phpstan-var array<int, array<int, NetworkSession>> $broadcasterTargets
			 */
			$broadcasterTargets = [];
			foreach($targets as $recipientId => $recipient){
				$recipientBroadcaster = $recipient->getBroadcaster();
				$broadcasterId = spl_object_id($recipientBroadcaster);
				$uniqueBroadcasters[$broadcasterId] = $recipientBroadcaster;
				$broadcasterTargets[$broadcasterId][$recipientId] = $recipient;
			}
			foreach($uniqueBroadcasters as $broadcasterId => $uniqueBroadcaster){
				$uniqueBroadcaster->broadcastPackets($broadcasterTargets[$broadcasterId], $packets);
			}

			return true;
		}finally{
			Timings::$broadcastPackets->stopTiming();
		}
	}

	/**
	 * @param Player[] $recipients
	 * @phpstan-param \Closure(EntityEventBroadcaster, array<int, NetworkSession>) : void $callback
	 */
	public static function broadcastEntityEvent(array $recipients, \Closure $callback) : void{
		$uniqueBroadcasters = [];
		$broadcasterTargets = [];

		foreach($recipients as $recipient){
			$session = $recipient->getNetworkSession();
			$broadcaster = $session->getEntityEventBroadcaster();
			$uniqueBroadcasters[spl_object_id($broadcaster)] = $broadcaster;
			$broadcasterTargets[spl_object_id($broadcaster)][spl_object_id($session)] = $session;
		}

		foreach($uniqueBroadcasters as $k => $broadcaster){
			$callback($broadcaster, $broadcasterTargets[$k]);
		}
	}
}
