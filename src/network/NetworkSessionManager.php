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

namespace pocketmine\network;

use pocketmine\lang\Translatable;
use pocketmine\network\mcpe\NetworkSession;
use function count;
use function hrtime;
use function inet_ntop;
use function spl_object_id;
use function strlen;

class NetworkSessionManager{
	/** Minimum time between two log lines about refused sessions (nanoseconds) */
	private const REFUSAL_LOG_INTERVAL_NS = 5_000_000_000;

	/**
	 * Sessions that have not sent a login request yet, per address (see {@link AddressSet::limitKey()})
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $pendingPerAddress = [];
	/** Maximum sessions per address that have not sent a login request yet, 0 = no limit */
	private int $maxPendingPerAddress = 0;
	private ?AddressSet $limitExempt = null;
	private ?\Logger $limitLogger = null;
	private int $nextRefusalLog = 0;
	private int $refusalsNotLogged = 0;

	/**
	 * @var NetworkSession[]
	 * @phpstan-var array<int, NetworkSession>
	 */
	private array $sessions = [];

	/**
	 * @var NetworkSession[]
	 * @phpstan-var array<int, NetworkSession>
	 */
	private array $pendingLoginSessions = [];

	/**
	 * Adds a network session to the manager. This should only be called on session creation.
	 */
	public function add(NetworkSession $session) : void{
		$idx = spl_object_id($session);
		$this->sessions[$idx] = $session;
		if(!isset($this->pendingLoginSessions[$idx])){
			$this->pendingLoginSessions[$idx] = $session;
			$key = AddressSet::limitKey($session->getIp());
			$this->pendingPerAddress[$key] = ($this->pendingPerAddress[$key] ?? 0) + 1;
		}
	}

	/**
	 * Marks the session as having sent a login request. After this point, they are counted towards the total player
	 * count.
	 */
	public function markLoginReceived(NetworkSession $session) : void{
		$this->removePending(spl_object_id($session));
	}

	/**
	 * Removes the given network session, due to disconnect. This should only be called by a network session on
	 * disconnection.
	 */
	public function remove(NetworkSession $session) : void{
		$idx = spl_object_id($session);
		unset($this->sessions[$idx]);
		$this->removePending($idx);
	}

	private function removePending(int $idx) : void{
		if(!isset($this->pendingLoginSessions[$idx])){
			return;
		}
		$key = AddressSet::limitKey($this->pendingLoginSessions[$idx]->getIp());
		unset($this->pendingLoginSessions[$idx]);
		if(($this->pendingPerAddress[$key] ?? 0) > 1){
			$this->pendingPerAddress[$key]--;
		}else{
			unset($this->pendingPerAddress[$key]);
		}
	}

	/**
	 * Limits the sessions of one IP address (IPv6: one /64) that have not sent a login request yet. Sessions above the
	 * limit are refused by {@link NetworkSessionManager::acceptsNewSession()}; players who sent their login never count,
	 * so many players behind one address (mobile carriers, schools, LAN parties) are only limited in how many connect
	 * at the very same moment.
	 *
	 * @param int        $maxPerAddress 0 = no limit
	 * @param AddressSet $exempt        addresses exempt from the limit (trusted proxies)
	 * @param \Logger    $logger        receives the debug lines about refused sessions (at most one per 5 seconds)
	 */
	public function setPendingLoginLimit(int $maxPerAddress, AddressSet $exempt, \Logger $logger) : void{
		$this->maxPendingPerAddress = $maxPerAddress > 0 ? $maxPerAddress : 0;
		$this->limitExempt = $exempt;
		$this->limitLogger = $logger;
	}

	/**
	 * Returns the number of sessions of the IP address (IPv6: of its /64) that have not sent a login request yet.
	 */
	public function getPendingLoginSessionCount(string $ip) : int{
		return $this->pendingPerAddress[AddressSet::limitKey($ip)] ?? 0;
	}

	/**
	 * Returns whether a network interface may open a new session for a client at the IP address, according to the
	 * limit set with {@link NetworkSessionManager::setPendingLoginLimit()}. A refused client should be disconnected
	 * before a session is created. Refusals are logged at debug level, rate-limited.
	 */
	public function acceptsNewSession(string $ip) : bool{
		if($this->maxPendingPerAddress === 0){
			return true;
		}
		$pending = $this->pendingPerAddress[AddressSet::limitKey($ip)] ?? 0;
		if($pending < $this->maxPendingPerAddress || ($this->limitExempt !== null && $this->limitExempt->contains($ip))){
			return true;
		}
		$this->logRefusal($ip, $pending);
		return false;
	}

	private function logRefusal(string $ip, int $pending) : void{
		if($this->limitLogger === null){
			return;
		}
		$now = hrtime(true);
		if($now < $this->nextRefusalLog){
			$this->refusalsNotLogged++;
			return;
		}
		$this->nextRefusalLog = $now + self::REFUSAL_LOG_INTERVAL_NS;
		$key = AddressSet::limitKey($ip);
		$address = $ip;
		if(strlen($key) === 8 && ($prefix = inet_ntop($key . "\x00\x00\x00\x00\x00\x00\x00\x00")) !== false){
			$address = "$prefix/64";
		}
		$this->limitLogger->debug("Refused a connection from $address: $pending connections from this address have not logged in yet (limit network.connection-limits.pending-logins-per-ip: " . $this->maxPendingPerAddress . "; raise it or add the address to exempt-addresses if many players share it)" .
			($this->refusalsNotLogged > 0 ? " ($this->refusalsNotLogged more refused since the last message)" : ""));
		$this->refusalsNotLogged = 0;
	}

	/**
	 * Returns the number of known connected sessions, including sessions which have not yet sent a login request.
	 */
	public function getSessionCount() : int{
		return count($this->sessions);
	}

	/**
	 * Returns the number of connected sessions which have either sent a login request, or have already completed the
	 * login sequence.
	 */
	public function getValidSessionCount() : int{
		return count($this->sessions) - count($this->pendingLoginSessions);
	}

	/** @return NetworkSession[] */
	public function getSessions() : array{ return $this->sessions; }

	/**
	 * Updates all sessions which need it.
	 */
	public function tick() : void{
		foreach($this->sessions as $k => $session){
			$session->tick();
			if(!$session->isConnected()){
				unset($this->sessions[$k]);
				//a session that disconnected before sending its login must not stay pending (and count towards its
				//address's limit) forever
				$this->removePending($k);
			}
		}
	}

	/**
	 * Terminates all connected sessions with the given reason.
	 *
	 * @param Translatable|string      $reason                  Shown in the server log - this should be a short one-line message
	 * @param Translatable|string|null $disconnectScreenMessage Shown on the player's disconnection screen (null will use the reason)
	 */
	public function close(Translatable|string $reason = "", Translatable|string|null $disconnectScreenMessage = null) : void{
		foreach($this->sessions as $session){
			$session->disconnect($reason, $disconnectScreenMessage);
		}
		$this->sessions = [];
		$this->pendingLoginSessions = [];
		$this->pendingPerAddress = [];
	}
}
