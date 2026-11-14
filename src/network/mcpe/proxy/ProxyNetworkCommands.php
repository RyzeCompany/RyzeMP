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
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use function array_keys;
use function count;
use function implode;
use function in_array;
use function ksort;
use function sort;
use const SORT_STRING;

/**
 * @internal
 * Network-wide commands behind pm-proxy, the equivalents of the usual WaterdogPE plugins: /glist, /find, /send and
 * /alert. pm-proxy runs them itself and checks the permissions (like BungeeCord); they are registered here, open to
 * everyone, so that players see them in the command list.
 */
final class ProxyNetworkCommands{
	public const PERMISSION_GLIST = "pocketmine.command.glist";
	public const PERMISSION_FIND = "pocketmine.command.find";
	public const PERMISSION_SEND = "pocketmine.command.send";
	public const PERMISSION_ALERT = "pocketmine.command.alert";

	private function __construct(){
		//NOOP
	}

	public static function register(Server $server, ProxyLinkInterface $proxy) : void{
		$operatorRoot = PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_OPERATOR);
		$userRoot = PermissionManager::getInstance()->getPermission(DefaultPermissions::ROOT_USER);
		if($operatorRoot !== null && $userRoot !== null){
			DefaultPermissions::registerPermission(new Permission(self::PERMISSION_GLIST, "Allows the user to list the players of the whole network"), [$userRoot]);
			DefaultPermissions::registerPermission(new Permission(self::PERMISSION_FIND, "Allows the user to find the server of a player of the network"), [$userRoot]);
			DefaultPermissions::registerPermission(new Permission(self::PERMISSION_SEND, "Allows the user to move players of the network to another server"), [$userRoot]);
			DefaultPermissions::registerPermission(new Permission(self::PERMISSION_ALERT, "Allows the user to send a message to every player of the network"), [$userRoot]);
		}
		$server->getCommandMap()->registerAll("pocketmine", [
			self::glist($proxy),
			self::find($proxy),
			self::send($proxy),
			self::alert($proxy),
		]);
	}

	private static function glist(ProxyLinkInterface $proxy) : Command{
		return new class($proxy) extends Command implements ExhaustiveOverloads{
			public function __construct(private ProxyLinkInterface $proxy){
				parent::__construct("glist", "Lists the players of every server of the network", "/glist");
				$this->setPermission(ProxyNetworkCommands::PERMISSION_GLIST);
			}

			public function getOverloads() : array{
				return [[]];
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				$byServer = [];
				foreach($this->proxy->getServers() as $server){
					$byServer[$server] = [];
				}
				foreach(Utils::stringifyKeys($this->proxy->getOnlinePlayers()) as $player => $server){
					$byServer[$server === "" ? "(changing server)" : $server][] = $player;
				}
				ksort($byServer, SORT_STRING);
				$sender->sendMessage(TextFormat::GOLD . "Players on the network: " . TextFormat::WHITE . $this->proxy->getGlobalPlayerCount());
				foreach(Utils::stringifyKeys($byServer) as $server => $players){
					sort($players, SORT_STRING);
					$state = $server === "(changing server)" || $this->proxy->isServerOnline($server) ? "" : TextFormat::RED . " (offline)";
					$sender->sendMessage(TextFormat::YELLOW . $server . TextFormat::GRAY . " (" . count($players) . ")" . $state . TextFormat::WHITE . (count($players) > 0 ? ": " . implode(", ", $players) : ""));
				}
				return true;
			}
		};
	}

	private static function find(ProxyLinkInterface $proxy) : Command{
		return new class($proxy) extends Command implements ExhaustiveOverloads{
			private const USAGE = "/find <player>";

			public function __construct(private ProxyLinkInterface $proxy){
				parent::__construct("find", "Shows the server a player of the network is on", self::USAGE);
				$this->setPermission(ProxyNetworkCommands::PERMISSION_FIND);
			}

			public function getOverloads() : array{
				return [[CommandParameter::softEnum("player", "ProxyPlayer", array_keys($this->proxy->getOnlinePlayers()))]];
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				if(count($args) < 1){
					$sender->sendMessage(TextFormat::RED . "Usage: " . self::USAGE);
					return false;
				}
				$server = $this->proxy->findPlayer($args[0]);
				if($server === null){
					$sender->sendMessage(TextFormat::RED . "$args[0] is not on the network.");
					return false;
				}
				$sender->sendMessage(TextFormat::YELLOW . "$args[0] is on " . ($server === "" ? "its way to another server" : $server) . ".");
				return true;
			}
		};
	}

	private static function send(ProxyLinkInterface $proxy) : Command{
		return new class($proxy) extends Command implements ExhaustiveOverloads{
			private const USAGE = "/send <player|*> <server> [dimension|seamless|cinematic]";

			public function __construct(private ProxyLinkInterface $proxy){
				parent::__construct("send", "Moves a player of the network (or everyone with *) to another server", self::USAGE);
				$this->setPermission(ProxyNetworkCommands::PERMISSION_SEND);
			}

			public function getOverloads() : array{
				return [[
					CommandParameter::softEnum("player", "ProxyPlayer", array_keys($this->proxy->getOnlinePlayers())),
					CommandParameter::softEnum("server", "ProxyServer", $this->proxy->getNetworkTargets()),
					CommandParameter::enum("style", "ProxyTransferStyle", ProxyTransferStyle::ALL, true),
				]];
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				if(count($args) < 2){
					$sender->sendMessage(TextFormat::RED . "Usage: " . self::USAGE);
					return false;
				}
				[$player, $target] = $args;
				$style = $args[2] ?? "";
				if($style !== "" && !in_array($style, ProxyTransferStyle::ALL, true)){
					$sender->sendMessage(TextFormat::RED . "Unknown style \"$style\": use " . implode(", ", ProxyTransferStyle::ALL) . ".");
					return false;
				}
				if(!$this->proxy->isNetworkTarget($target)){
					$sender->sendMessage(TextFormat::RED . "Unknown server \"$target\".");
					return false;
				}
				if($player !== "*" && $this->proxy->findPlayer($player) === null){
					$sender->sendMessage(TextFormat::RED . "$player is not on the network.");
					return false;
				}
				$this->proxy->sendPlayer($player, $target, $style);
				$sender->sendMessage(TextFormat::YELLOW . ($player === "*" ? "Sending everyone" : "Sending $player") . " to $target.");
				return true;
			}
		};
	}

	private static function alert(ProxyLinkInterface $proxy) : Command{
		return new class($proxy) extends Command implements ExhaustiveOverloads{
			private const USAGE = "/alert <message>";

			public function __construct(private ProxyLinkInterface $proxy){
				parent::__construct("alert", "Sends a message to every player of the network", self::USAGE);
				$this->setPermission(ProxyNetworkCommands::PERMISSION_ALERT);
			}

			public function getOverloads() : array{
				return [[CommandParameter::message("message")]];
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				if(count($args) < 1){
					$sender->sendMessage(TextFormat::RED . "Usage: " . self::USAGE);
					return false;
				}
				$this->proxy->broadcastAlert(TextFormat::RED . "[Alert] " . TextFormat::RESET . implode(" ", $args));
				return true;
			}
		};
	}
}
