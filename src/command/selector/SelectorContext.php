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

use pocketmine\command\BlockCommandSender;
use pocketmine\command\CommandSender;
use pocketmine\command\EntityCommandSender;
use pocketmine\command\ExecuteCommandSender;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use function array_values;

/**
 * What a target selector is evaluated against: the executing entity (@s), the origin used for distances and relative
 * coordinates, and where to look for players and entities.
 */
final class SelectorContext{
	/**
	 * @phpstan-param \Closure() : list<Player> $players
	 * @phpstan-param \Closure() : list<World>  $worlds
	 */
	public function __construct(
		private ?Entity $executor,
		private ?World $world,
		private Vector3 $origin,
		private float $yaw,
		private float $pitch,
		private \Closure $players,
		private \Closure $worlds,
		private GameMode $defaultGameMode
	){}

	/**
	 * Context of a command sender: an entity sender (a player) is the executor and the origin; any other sender (the
	 * console, RCON...) uses the default world's spawn, like a Bedrock command block-less server console.
	 */
	public static function fromSender(CommandSender $sender) : self{
		$server = $sender->getServer();
		$players = fn() : array => array_values($server->getOnlinePlayers());
		$worlds = fn() : array => array_values($server->getWorldManager()->getWorlds());
		if($sender instanceof ExecuteCommandSender){
			///execute: @s is the executing entity (if any), distances from the position it runs at
			$position = $sender->getExecutingPosition();
			return new self($sender->getExecutor(), $position->getWorld(), $position->asVector3(), $sender->getYaw(), $sender->getPitch(), $players, $worlds, $server->getGamemode());
		}
		$entity = $sender instanceof Entity ? $sender : ($sender instanceof EntityCommandSender ? $sender->getExecutingEntity() : null);
		if($entity !== null){
			$location = $entity->getLocation();
			return new self($entity, $location->getWorld(), $location->asVector3(), $location->yaw, $location->pitch, $players, $worlds, $server->getGamemode());
		}
		if($sender instanceof BlockCommandSender){
			//command blocks: distances from the centre of the block, nothing for @s
			$position = $sender->getExecutingPosition();
			return new self(null, $position->getWorld(), $position->asVector3(), 0.0, 0.0, $players, $worlds, $server->getGamemode());
		}
		$world = $server->getWorldManager()->getDefaultWorld();
		$origin = $world !== null ? $world->getSpawnLocation()->asVector3() : Vector3::zero();
		return new self(null, $world, $origin, 0.0, 0.0, $players, $worlds, $server->getGamemode());
	}

	/** The entity running the command (@s), or null for the console and other non-entity senders. */
	public function getExecutor() : ?Entity{ return $this->executor; }

	/** World of the origin (null if the server has no default world). */
	public function getWorld() : ?World{ return $this->world; }

	public function getOrigin() : Vector3{ return $this->origin; }

	public function getYaw() : float{ return $this->yaw; }

	public function getPitch() : float{ return $this->pitch; }

	/**
	 * @return Player[]
	 * @phpstan-return list<Player>
	 */
	public function getPlayers() : array{
		return ($this->players)();
	}

	/**
	 * @return World[]
	 * @phpstan-return list<World>
	 */
	public function getWorlds() : array{
		return ($this->worlds)();
	}

	/** Game mode matched by m=default / m=d / m=5. */
	public function getDefaultGameMode() : GameMode{ return $this->defaultGameMode; }
}
