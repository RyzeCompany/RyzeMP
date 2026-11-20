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

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\mcpe\ClientVersionSupport;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use pocketmine\VersionInfo;
use function array_shift;
use function count;
use function implode;
use function str_repeat;
use function substr;
use function stripos;
use function strtolower;
use const PHP_VERSION;
use const PHP_ZTS;

class VersionCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"version",
			KnownTranslationFactory::pocketmine_command_version_description(),
			KnownTranslationFactory::pocketmine_command_version_usage(),
			["ver", "about"]
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_VERSION);
	}


	/**
	 * Native Bedrock suggestions plus the free-text legacy plugin lookup from
	 * CommandOverloadMapper, so /version <plugin name> keeps working.
	 */
	public function getOverloads() : array{
		return [
			[],
			[CommandParameter::enum("section", "RyzeMPVersionSection", ["runtime", "protocols", "help"])],
			[CommandParameter::enum("action", "RyzeMPVersionPluginAction", ["plugin"]), CommandParameter::rawText("pluginName")]
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			$this->sendServerInfo($sender);
			return true;
		}

		$first = strtolower($args[0]);
		if($first === "help" && count($args) === 1){
			$sender->sendMessage(TextFormat::AQUA . "RyzeMP /version" . TextFormat::RESET . " - /version, /version protocols, /version runtime, /version plugin <name>");
			$sender->sendMessage(TextFormat::GRAY . "Aliases: /ver, /about. Legacy /version <plugin name> is supported.");
			return true;
		}
		if($first === "runtime" && count($args) === 1){
			$this->sendRuntimeInfo($sender);
			return true;
		}
		if($first === "protocols" && count($args) === 1){
			$this->sendCompatibleVersions($sender);
			return true;
		}
		if($first === "plugin"){
			array_shift($args);
			if(count($args) === 0){
				$sender->sendMessage(TextFormat::RED . "Usage: /version plugin <name>");
				return true;
			}
		}
		$this->sendPluginInfo($sender, implode(" ", $args));
		return true;
	}

	private function sendServerInfo(CommandSender $sender) : void{
		$version = VersionInfo::VERSION()->getFullVersion();
		$hash = VersionInfo::GIT_HASH();
		$shortHash = $hash === str_repeat("0", 40) ? "unknown" : substr($hash, 0, 8);
		$sender->sendMessage(TextFormat::DARK_AQUA . TextFormat::BOLD . "RyzeMP" . TextFormat::RESET . TextFormat::GRAY . " | Server software");
		$sender->sendMessage(TextFormat::GRAY . "Release: " . TextFormat::AQUA . $version . TextFormat::GRAY . " (" . VersionInfo::BUILD_CHANNEL . ")");
		$sender->sendMessage(TextFormat::GRAY . "Build: " . TextFormat::AQUA . $shortHash);
		$sender->sendMessage(TextFormat::GRAY . "Minecraft Bedrock: " . TextFormat::AQUA . ProtocolInfo::MINECRAFT_VERSION_NETWORK . TextFormat::GRAY . " | Protocol: " . TextFormat::AQUA . ProtocolInfo::CURRENT_PROTOCOL);
		$this->sendCompatibleVersions($sender);
		$sender->sendMessage(TextFormat::GRAY . "Plugin API: " . TextFormat::AQUA . VersionInfo::PLUGIN_API_VERSION . TextFormat::GRAY . " | Composer API: " . TextFormat::AQUA . VersionInfo::COMPOSER_API_VERSION);
		$sender->sendMessage(TextFormat::GRAY . "Runtime: " . TextFormat::AQUA . "PHP " . PHP_VERSION . TextFormat::GRAY . " | JIT: " . $this->jitStatus());
		$sender->sendMessage(TextFormat::GRAY . "Source: " . TextFormat::AQUA . VersionInfo::GITHUB_URL);
	}

	private function sendCompatibleVersions(CommandSender $sender) : void{
		$versions = ClientVersionSupport::getVersions();
		$sender->sendMessage(TextFormat::GRAY . "Compatible Bedrock protocol IDs: " . TextFormat::AQUA . count($versions) . TextFormat::GRAY . " (version: protocol)");
		$entries = [];
		foreach($versions as $protocolId => $version){
			$entries[] = "$version: $protocolId";
			if(count($entries) === 4){
				$sender->sendMessage(TextFormat::AQUA . implode(TextFormat::GRAY . " | " . TextFormat::AQUA, $entries));
				$entries = [];
			}
		}
		if($entries !== []){
			$sender->sendMessage(TextFormat::AQUA . implode(TextFormat::GRAY . " | " . TextFormat::AQUA, $entries));
		}
		$sender->sendMessage(TextFormat::GRAY . "1.26.50+ clients require NetherNet (RyzeRTC). Same-protocol patch releases may connect; test gameplay before production.");
	}

	private function sendRuntimeInfo(CommandSender $sender) : void{
		$sender->sendMessage(TextFormat::DARK_AQUA . TextFormat::BOLD . "RyzeMP" . TextFormat::RESET . TextFormat::GRAY . " | PHP runtime");
		$sender->sendMessage(TextFormat::GRAY . "PHP: " . TextFormat::AQUA . PHP_VERSION . TextFormat::GRAY . " (" . (PHP_ZTS ? "ZTS" : "NTS") . ")");
		$sender->sendMessage(TextFormat::GRAY . "Operating system: " . TextFormat::AQUA . Utils::getOS());
		$sender->sendMessage(TextFormat::GRAY . "OPcache JIT: " . $this->jitStatus());
		$sender->sendMessage(TextFormat::GRAY . "JIT is configured at PHP startup, not by this command.");
	}

	private function jitStatus() : string{
		$mode = Utils::getOpcacheJitMode();
		if($mode === null){
			return TextFormat::YELLOW . "unavailable";
		}
		return $mode === 0 ?
			TextFormat::YELLOW . "disabled" :
			TextFormat::GREEN . "enabled (CRTO: " . $mode . ")";
	}

	private function sendPluginInfo(CommandSender $sender, string $pluginName) : void{
		$exactPlugin = $sender->getServer()->getPluginManager()->getPlugin($pluginName);
		if($exactPlugin instanceof Plugin){
			$this->describeToSender($exactPlugin, $sender);
			return;
		}
		$found = false;
		$pluginName = strtolower($pluginName);
		foreach($sender->getServer()->getPluginManager()->getPlugins() as $plugin){
			if(stripos($plugin->getName(), $pluginName) !== false){
				$this->describeToSender($plugin, $sender);
				$found = true;
			}
		}
		if(!$found){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_noSuchPlugin());
		}
	}

	private function describeToSender(Plugin $plugin, CommandSender $sender) : void{
		$desc = $plugin->getDescription();
		$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_header(
			TextFormat::DARK_GREEN . $desc->getName() . TextFormat::RESET,
			TextFormat::DARK_GREEN . $desc->getVersion() . TextFormat::RESET
		));

		if($desc->getDescription() !== ""){
			$sender->sendMessage($desc->getDescription());
		}

		if($desc->getWebsite() !== ""){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_website($desc->getWebsite()));
		}

		if(count($authors = $desc->getAuthors()) > 0){
			if(count($authors) === 1){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_author(implode(", ", $authors)));
			}else{
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_version_plugin_authors(implode(", ", $authors)));
			}
		}
	}
}
