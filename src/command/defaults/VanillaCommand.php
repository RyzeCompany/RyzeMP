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

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ExecuteCommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\ParameterizedCommand;
use pocketmine\command\selector\CommandTargets;
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\selector\TargetSelectorException;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function is_numeric;
use function substr;

abstract class VanillaCommand extends Command implements ParameterizedCommand{
	public const MAX_COORD = 30000000;
	public const MIN_COORD = -30000000;

	/**
	 * Overloads advertised to clients; none by default (the command is sent as taking free text).
	 *
	 * @return CommandParameter[][]
	 * @phpstan-return list<list<CommandParameter>>
	 */
	public function getOverloads() : array{
		return [];
	}

	/**
	 * The entity a command acts on when no target is given: the sender itself if it is an entity, or the executing
	 * entity of an /execute (null if there is none).
	 */
	protected static function getSenderEntity(CommandSender $sender) : ?Entity{
		if($sender instanceof ExecuteCommandSender){
			return $sender->getExecutor();
		}
		return $sender instanceof Entity ? $sender : null;
	}

	/**
	 * Origin of the sender's relative coordinates (see {@link CommandCoordinates::senderOrigin()}); the spawn of the
	 * default world for the console and other senders without a position. Null if there is no world at all.
	 */
	protected static function getCommandOrigin(CommandSender $sender) : ?Location{
		$origin = CommandCoordinates::senderOrigin($sender);
		if($origin !== null){
			return $origin;
		}
		$world = $sender->getServer()->getWorldManager()->getDefaultWorld();
		return $world !== null ? Location::fromObject($world->getSpawnLocation(), $world) : null;
	}

	/**
	 * Whether the target is the sender (or the player who ran an /execute): the "self" permissions apply then.
	 */
	protected static function isSender(CommandSender $sender, Entity $target) : bool{
		return $target === $sender || ($sender instanceof ExecuteCommandSender && $target === $sender->getOriginalSender());
	}

	/**
	 * Resolves a single player target (a player name or a selector matching one player), or the sender if no target
	 * is given, and checks the self/other permission.
	 */
	protected function fetchPermittedPlayerTarget(CommandSender $sender, ?string $target, string $selfPermission, string $otherPermission) : ?Player{
		if($target !== null && TargetSelector::isSelectorArgument($target)){
			try{
				$player = CommandTargets::player($sender, $target);
			}catch(TargetSelectorException $e){
				$sender->sendMessage($e->getTranslatable()->prefix(TextFormat::RED));
				return null;
			}
		}elseif($target !== null){
			$player = $sender->getServer()->getPlayerByPrefix($target);
			if($player === null){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_error_playerNotFound($target)->prefix(TextFormat::RED));
				return null;
			}
		}elseif(($self = self::getSenderEntity($sender)) instanceof Player){
			$player = $self;
		}else{
			throw new InvalidCommandSyntaxException();
		}

		if(
			(self::isSender($sender, $player) && $this->testPermission($sender, $selfPermission)) ||
			(!self::isSender($sender, $player) && $this->testPermission($sender, $otherPermission))
		){
			return $player;
		}
		return null;
	}

	/**
	 * Resolves player targets (a player name or a target selector), or the sender if no target is given, and checks
	 * the self/other permissions (both when the targets include the sender and other players). Returns an empty array
	 * if the sender lacks a permission (after telling them). Selector errors throw a {@link TargetSelectorException},
	 * which the command map shows to the sender.
	 *
	 * @return Player[]
	 * @phpstan-return list<Player>
	 * @throws TargetSelectorException
	 */
	protected function fetchPermittedPlayerTargets(CommandSender $sender, ?string $target, string $selfPermission, string $otherPermission) : array{
		if($target !== null){
			$players = CommandTargets::players($sender, $target);
		}elseif(($self = self::getSenderEntity($sender)) instanceof Player){
			$players = [$self];
		}else{
			throw new InvalidCommandSyntaxException();
		}
		return $this->checkTargetPermissions($sender, $players, $selfPermission, $otherPermission) ? $players : [];
	}

	/**
	 * Like {@link fetchPermittedPlayerTargets()}, but the targets may be any entities (selectors like @e[type=cow]).
	 *
	 * @return Entity[]
	 * @phpstan-return list<Entity>
	 * @throws TargetSelectorException
	 */
	protected function fetchPermittedEntityTargets(CommandSender $sender, ?string $target, string $selfPermission, string $otherPermission) : array{
		if($target !== null){
			$entities = CommandTargets::entities($sender, $target);
		}elseif(($self = self::getSenderEntity($sender)) !== null){
			$entities = [$self];
		}else{
			throw new InvalidCommandSyntaxException();
		}
		return $this->checkTargetPermissions($sender, $entities, $selfPermission, $otherPermission) ? $entities : [];
	}

	/**
	 * @param Entity[] $targets
	 */
	private function checkTargetPermissions(CommandSender $sender, array $targets, string $selfPermission, string $otherPermission) : bool{
		$self = false;
		$other = false;
		foreach($targets as $target){
			if(self::isSender($sender, $target)){
				$self = true;
			}else{
				$other = true;
			}
		}
		return (!$self || $this->testPermission($sender, $selfPermission)) && (!$other || $this->testPermission($sender, $otherPermission));
	}

	protected function getInteger(CommandSender $sender, string $value, int $min = self::MIN_COORD, int $max = self::MAX_COORD) : int{
		$i = (int) $value;

		if($i < $min){
			$i = $min;
		}elseif($i > $max){
			$i = $max;
		}

		return $i;
	}

	protected function getRelativeDouble(float $original, CommandSender $sender, string $input, float $min = self::MIN_COORD, float $max = self::MAX_COORD) : float{
		if($input[0] === "~"){
			$value = $this->getDouble($sender, substr($input, 1));

			return $original + $value;
		}

		return $this->getDouble($sender, $input, $min, $max);
	}

	protected function getDouble(CommandSender $sender, string $value, float $min = self::MIN_COORD, float $max = self::MAX_COORD) : float{
		$i = (float) $value;

		if($i < $min){
			$i = $min;
		}elseif($i > $max){
			$i = $max;
		}

		return $i;
	}

	protected function getBoundedInt(CommandSender $sender, string $input, int $min, int $max) : ?int{
		if(!is_numeric($input)){
			throw new InvalidCommandSyntaxException();
		}

		$v = (int) $input;
		if($v > $max){
			$sender->sendMessage(KnownTranslationFactory::commands_generic_num_tooBig($input, (string) $max)->prefix(TextFormat::RED));
			return null;
		}
		if($v < $min){
			$sender->sendMessage(KnownTranslationFactory::commands_generic_num_tooSmall($input, (string) $min)->prefix(TextFormat::RED));
			return null;
		}

		return $v;
	}
}
