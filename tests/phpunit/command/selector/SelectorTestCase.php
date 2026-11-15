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

namespace pocketmine\command\selector;

use PHPUnit\Framework\MockObject\MockObject;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\CommandFailureException;
use pocketmine\command\utils\CommandStringHelper;
use pocketmine\entity\ai\MobTestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\ExperienceManager;
use pocketmine\entity\Location;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\PlayerCursorInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\lang\Translatable;
use pocketmine\math\AxisAlignedBB;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\WorldManager;
use function array_shift;
use function count;
use function ltrim;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * A test world (see MobTestCase) with mocked players registered as online in the mocked server, and helpers to run
 * commands like the command map does.
 */
abstract class SelectorTestCase extends MobTestCase{
	/**
	 * @var Player[]
	 * @phpstan-var list<Player&MockObject>
	 */
	protected array $players = [];
	/**
	 * Messages received per sender name (translation keys for translatable messages).
	 * @var string[][]
	 * @phpstan-var array<string, list<string>>
	 */
	protected array $messages = [];

	protected function setUp() : void{
		parent::setUp();
		DefaultPermissions::registerCorePermissions();
		$this->players = [];
		$this->messages = [];
		$server = $this->server();
		$server->method("getOnlinePlayers")->willReturnCallback(fn() => $this->players);
		$server->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		$server->method("getPlayerByPrefix")->willReturnCallback(function(string $name) : ?Player{
			foreach($this->players as $player){
				if(str_starts_with(strtolower($player->getName()), strtolower($name))){
					return $player;
				}
			}
			return null;
		});
		$server->method("getPlayerExact")->willReturnCallback(function(string $name) : ?Player{
			foreach($this->players as $player){
				if(strtolower($player->getName()) === strtolower($name)){
					return $player;
				}
			}
			return null;
		});
		$worldManager = $server->getWorldManager();
		self::assertInstanceOf(MockObject::class, $worldManager);
		/** @var WorldManager&MockObject $worldManager */
		$worldManager->method("getWorlds")->willReturnCallback(fn() => [1 => $this->world]);
		$worldManager->method("getDefaultWorld")->willReturnCallback(fn() => $this->world);
	}

	/**
	 * @return Server&MockObject
	 */
	protected function server() : Server{
		$server = $this->world->getServer();
		self::assertInstanceOf(MockObject::class, $server);
		return $server;
	}

	protected function loc(float $x, float $y, float $z, float $yaw = 0.0, float $pitch = 0.0) : Location{
		return new Location($x, $y, $z, $this->world, $yaw, $pitch);
	}

	/**
	 * A mocked online player.
	 *
	 * @return Player&MockObject
	 */
	protected function player(string $name, float $x, float $y, float $z, GameMode $mode = GameMode::SURVIVAL, int $xpLevel = 0, bool $alive = true, float $yaw = 0.0, float $pitch = 0.0) : Player{
		$player = $this->createMock(Player::class);
		$location = $this->loc($x, $y, $z, $yaw, $pitch);
		$player->method("getWorld")->willReturn($this->world);
		$player->method("getPosition")->willReturn($location);
		$player->method("getLocation")->willReturn($location);
		$player->method("getName")->willReturn($name);
		$player->method("getDisplayName")->willReturn($name);
		$player->method("isAlive")->willReturn($alive);
		$player->method("isOnline")->willReturn(true);
		$player->method("isConnected")->willReturn(true);
		$player->method("getGamemode")->willReturn($mode);
		$player->method("isSpectator")->willReturn($mode === GameMode::SPECTATOR);
		$xp = $this->createMock(ExperienceManager::class);
		$xp->method("getXpLevel")->willReturn($xpLevel);
		$player->method("getXpManager")->willReturn($xp);
		$player->method("getServer")->willReturn($this->world->getServer());
		$player->method("hasPermission")->willReturn(true);
		$player->method("getBoundingBox")->willReturn(new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3));
		$player->method("getInventory")->willReturn(new PlayerInventory($player));
		$player->method("getOffHandInventory")->willReturn(new PlayerOffHandInventory($player));
		$player->method("getCursorInventory")->willReturn(new PlayerCursorInventory($player));
		$player->method("getArmorInventory")->willReturn(new ArmorInventory($player));
		$player->method("sendMessage")->willReturnCallback(function(Translatable|string $message) use ($name) : void{
			$this->messages[$name][] = self::messageKey($message);
		});
		//keep the mock from running Player::onDispose() when it is destroyed
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$this->players[] = $player;
		return $player;
	}

	/**
	 * A mocked console sender (no position: selectors use the default world spawn).
	 *
	 * @return CommandSender&MockObject
	 */
	protected function console() : CommandSender{
		$console = $this->createMock(CommandSender::class);
		$console->method("getServer")->willReturn($this->world->getServer());
		$console->method("getName")->willReturn("CONSOLE");
		$console->method("hasPermission")->willReturn(true);
		$console->method("sendMessage")->willReturnCallback(function(Translatable|string $message) : void{
			$this->messages["CONSOLE"][] = self::messageKey($message);
		});
		return $console;
	}

	/**
	 * Translation key of a message (without the colour prefix), or the message itself.
	 */
	private static function messageKey(Translatable|string $message) : string{
		return $message instanceof Translatable ? ltrim(TextFormat::clean($message->getText()), "%") : $message;
	}

	/**
	 * Runs a command line like SimpleCommandMap::dispatch() (selector and command errors become messages to the sender).
	 */
	protected function runCommand(Command $command, CommandSender $sender, string $line) : void{
		$args = CommandStringHelper::parseQuoteAware($line);
		$label = array_shift($args) ?? "";
		if(str_starts_with($label, "/")){
			$label = substr($label, 1);
		}
		try{
			$command->execute($sender, $label, $args);
		}catch(TargetSelectorException|CommandFailureException $e){
			$sender->sendMessage($e->getTranslatable());
		}
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	protected function messagesOf(string $name) : array{
		return $this->messages[$name] ?? [];
	}

	protected function lastMessage(string $name) : ?string{
		$messages = $this->messagesOf($name);
		return $messages[count($messages) - 1] ?? null;
	}
}
