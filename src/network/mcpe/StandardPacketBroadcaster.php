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
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\Server;
use pocketmine\timings\Timings;
use function count;
use function implode;
use function spl_object_id;
use function strlen;

final class StandardPacketBroadcaster implements PacketBroadcaster{
	public function __construct(
		private Server $server
	){}

	public function broadcastPackets(array $recipients, array $packets) : void{
		//TODO: this shouldn't really be called here, since the broadcaster might be replaced by an alternative
		//implementation that doesn't fire events
		if(DataPacketSendEvent::hasHandlers()){
			$ev = new DataPacketSendEvent($recipients, $packets);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}
			$packets = $ev->getPackets();
		}

		//each packet is encoded and length-prefixed once, whatever the number of recipients (this runs for every
		//movement of every entity, to every viewer)
		$totalLength = 0;
		$packetBuffers = [];
		$writer = new ByteBufferWriter();
		foreach($packets as $packet){
			$writer->clear(); //memory reuse let's gooooo
			$buffer = NetworkSession::prefixPacketBuffer(NetworkSession::encodePacketTimed($writer, $packet));
			$totalLength += strlen($buffer);
			$packetBuffers[] = $buffer;
		}

		//a server normally has a single compressor: no grouping unless the recipients really differ
		//TODO: different compressors might be compatible, it might not be necessary to split them up by object
		if(NetworkSession::hasOnlyStandardSessions()){
			$this->broadcastToCompressorGroup($recipients, ZlibCompressor::getInstance(), $packetBuffers, $totalLength);
			return;
		}
		$compressor = null;
		foreach($recipients as $recipient){
			$recipientCompressor = $recipient->getCompressor();
			if($compressor === null){
				$compressor = $recipientCompressor;
			}elseif($recipientCompressor !== $compressor){
				$compressor = null;
				break;
			}
		}
		if($compressor !== null){
			$this->broadcastToCompressorGroup($recipients, $compressor, $packetBuffers, $totalLength);
			return;
		}

		$compressors = [];
		$targetsByCompressor = [];
		foreach($recipients as $recipient){
			$compressor = $recipient->getCompressor();
			$compressors[spl_object_id($compressor)] = $compressor;
			$targetsByCompressor[spl_object_id($compressor)][] = $recipient;
		}
		foreach($targetsByCompressor as $compressorId => $compressorTargets){
			$this->broadcastToCompressorGroup($compressorTargets, $compressors[$compressorId], $packetBuffers, $totalLength);
		}
	}

	/**
	 * @param NetworkSession[] $targets
	 * @param string[]         $packetBuffers length-prefixed packet buffers
	 * @phpstan-param list<string> $packetBuffers
	 */
	private function broadcastToCompressorGroup(array $targets, Compressor $compressor, array $packetBuffers, int $totalLength) : void{
		$threshold = $compressor->getCompressionThreshold();
		if(count($targets) > 1 && $threshold !== null && $totalLength >= $threshold){
			//do not prepare shared batch unless we're sure it will be compressed
			$batch = $this->server->prepareBatch(implode("", $packetBuffers), $compressor, timings: Timings::$playerNetworkSendCompressBroadcast);
			foreach($targets as $target){
				$target->queueCompressed($batch);
			}
		}elseif(count($packetBuffers) === 1){
			$packetBuffer = $packetBuffers[0];
			foreach($targets as $target){
				$target->addPrefixedToSendBuffer($packetBuffer);
			}
		}else{
			foreach($targets as $target){
				foreach($packetBuffers as $packetBuffer){
					$target->addPrefixedToSendBuffer($packetBuffer);
				}
			}
		}
	}
}
