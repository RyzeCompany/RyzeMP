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

namespace pocketmine\network\mcpe\proxy;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function count;
use function implode;
use function in_array;

/**
 * @internal
 * /server [name]: moves the player to another server or group of the pm-proxy network. Only registered behind the
 * proxy; operators only by default (permission pocketmine.command.server).
 */
final class ProxyServerCommand extends Command implements ExhaustiveOverloads{
	public const PERMISSION = "pocketmine.command.server";

	public function __construct(
		private ProxyLinkInterface $proxy
	){
		parent::__construct("server", "Move to another server of the network", "/server [name] [dimension|seamless|cinematic]");
		$this->setPermission(self::PERMISSION);
	}

	public function getOverloads() : array{
		return [[
			CommandParameter::softEnum("server", "ProxyServer", $this->proxy->getNetworkTargets(), true),
			CommandParameter::enum("style", "ProxyTransferStyle", ProxyTransferStyle::ALL, true),
		]];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(!$sender instanceof Player){
			$sender->sendMessage(TextFormat::RED . "Only players can change server.");
			return false;
		}
		if(count($args) === 0){
			$targets = $this->proxy->getNetworkTargets();
			$sender->sendMessage(TextFormat::YELLOW . "You are on " . $this->proxy->getServerName() . "." . (count($targets) > 0 ? " Servers: " . implode(", ", $targets) : ""));
			return true;
		}
		$style = $args[1] ?? "";
		if($style !== "" && !in_array($style, ProxyTransferStyle::ALL, true)){
			$sender->sendMessage(TextFormat::RED . "Unknown style \"$style\": use " . implode(", ", ProxyTransferStyle::ALL) . ".");
			return false;
		}
		if(!$this->proxy->isProxied($sender)){
			$sender->sendMessage(TextFormat::RED . "You are not connected through the proxy.");
			return false;
		}
		//false: a plugin cancelled the transfer, and told the player why if it wanted to
		return $this->proxy->transfer($sender, $args[0], $style);
	}
}
