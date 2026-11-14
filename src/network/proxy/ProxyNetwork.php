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

namespace pocketmine\network\proxy;

use pocketmine\player\Player;

/**
 * The pm-proxy network this server belongs to (network.proxy in pocketmine.yml), for plugins. Get it with
 * {@link \pocketmine\Server::getProxyNetwork()}, which returns null when the server is not behind pm-proxy.
 *
 * What it knows about other servers comes from the proxy about once a second, so it can be slightly out of date.
 */
interface ProxyNetwork{
	/** Transfer style: a dimension change with its loading screen. */
	public const STYLE_DIMENSION = "dimension";
	/** Transfer style: no loading screen, the terrain is replaced (same dimension only, else dimension). */
	public const STYLE_SEAMLESS = "seamless";
	/** Transfer style: like seamless, with the camera flying up and down (GTA V style). */
	public const STYLE_CINEMATIC = "cinematic";
	/** Transfer target meaning the lobby (the proxy's fallback groups). */
	public const TARGET_HUB = "@hub";

	/**
	 * Whether the proxy is linked right now. While it is not, players cannot join and nothing is sent.
	 */
	public function isConnected() : bool;

	/**
	 * Name of this server in the network (network.proxy.server-name).
	 */
	public function getServerName() : string;

	/**
	 * Servers of the network.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getServers() : array;

	/**
	 * Groups of servers of the network (a group sends players to its least busy server).
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getGroups() : array;

	/**
	 * Whether a name is a server or a group of the network.
	 */
	public function isNetworkTarget(string $name) : bool;

	/**
	 * Players on the whole network.
	 */
	public function getGlobalPlayerCount() : int;

	/**
	 * Players on a server of the network (0 for unknown servers).
	 */
	public function getServerPlayerCount(string $server) : int;

	/**
	 * Whether the proxy has a working link to a server of the network.
	 */
	public function isServerOnline(string $server) : bool;

	/**
	 * Every player of the network, with the server it is on ("" while changing server).
	 * @return string[] player name => server name
	 * @phpstan-return array<string, string>
	 */
	public function getOnlinePlayers() : array;

	/**
	 * The server a player of the network is on (name matched without case), or null if not online.
	 */
	public function findPlayer(string $name) : ?string;

	/**
	 * Moves a player of this server to a server or group of the network (or {@link self::TARGET_HUB}), without
	 * reconnecting. Calls {@link \pocketmine\event\player\PlayerProxyTransferEvent}.
	 *
	 * @param string $style one of the STYLE_ constants, or "" for the proxy's transfer-style
	 *
	 * @return bool false if the event was cancelled or the player did not come through the proxy
	 */
	public function transfer(Player $player, string $target, string $style = "") : bool;

	/**
	 * Moves a player of any server of the network, by name ("*" for everyone).
	 *
	 * @param string $style one of the STYLE_ constants, or "" for the proxy's transfer-style
	 */
	public function sendPlayer(string $name, string $target, string $style = "") : void;

	/**
	 * Sends a plugin message to another server (or to every other server when $server is null). It arrives there as
	 * a {@link \pocketmine\event\server\ProxyMessageReceiveEvent}.
	 */
	public function sendMessage(string $channel, string $data, ?string $server = null) : void;

	/**
	 * Shows a chat message to every player of the network.
	 */
	public function broadcastAlert(string $message) : void;
}
