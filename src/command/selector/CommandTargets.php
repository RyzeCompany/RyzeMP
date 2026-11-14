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

use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use function count;
use function preg_last_error_msg;
use function preg_replace_callback;

/**
 * Resolves command arguments that name targets: a Bedrock target selector (@a, @e[type=cow,r=10]...) or a player name
 * (matched by prefix, like PocketMine always did). Plugin commands can use these helpers to accept selectors:
 *
 * ```php
 * foreach(CommandTargets::players($sender, $args[0]) as $player){ ... }
 * ```
 *
 * Failures throw a {@link TargetSelectorException} with a message for the sender; when thrown from
 * Command::execute() the command map shows it (in red), so commands usually let it propagate.
 */
final class CommandTargets{

	private function __construct(){
		//NOOP
	}

	/**
	 * Players named by the argument: the players matched by a selector (which must be able to select only players),
	 * or the online player whose name starts with the argument. Never empty.
	 *
	 * @return Player[]
	 * @phpstan-return non-empty-list<Player>
	 * @throws TargetSelectorException
	 */
	public static function players(CommandSender $sender, string $target) : array{
		if(TargetSelector::isSelectorArgument($target)){
			$selector = TargetSelector::parse($target);
			if(!$selector->isPlayerOnly()){
				throw new TargetSelectorException(KnownTranslationFactory::commands_generic_targetNotPlayer());
			}
			$players = $selector->selectPlayers($sender);
			if(count($players) === 0){
				throw new TargetSelectorException(KnownTranslationFactory::commands_generic_noTargetMatch());
			}
			return $players;
		}
		$player = $sender->getServer()->getPlayerByPrefix($target);
		if($player === null){
			//Bedrock treats a name like a selector: an unknown or offline player is "No targets matched selector"
			throw new TargetSelectorException(KnownTranslationFactory::commands_generic_noTargetMatch());
		}
		return [$player];
	}

	/**
	 * Entities named by the argument: the entities matched by a selector, or the online player whose name starts with
	 * the argument. Never empty.
	 *
	 * @return Entity[]
	 * @phpstan-return non-empty-list<Entity>
	 * @throws TargetSelectorException
	 */
	public static function entities(CommandSender $sender, string $target) : array{
		if(TargetSelector::isSelectorArgument($target)){
			$entities = TargetSelector::parse($target)->selectEntities($sender);
			if(count($entities) === 0){
				throw new TargetSelectorException(KnownTranslationFactory::commands_generic_noTargetMatch());
			}
			return $entities;
		}
		return self::players($sender, $target);
	}

	/**
	 * Exactly one entity (e.g. a teleport destination): throws "Too many targets matched selector" when a selector
	 * matches more than one.
	 *
	 * @throws TargetSelectorException
	 */
	public static function entity(CommandSender $sender, string $target) : Entity{
		$entities = self::entities($sender, $target);
		if(count($entities) > 1){
			throw new TargetSelectorException(KnownTranslationFactory::commands_generic_tooManyTargets());
		}
		return $entities[0];
	}

	/**
	 * Exactly one player.
	 *
	 * @throws TargetSelectorException
	 */
	public static function player(CommandSender $sender, string $target) : Player{
		$players = self::players($sender, $target);
		if(count($players) > 1){
			throw new TargetSelectorException(KnownTranslationFactory::commands_generic_tooManyTargets());
		}
		return $players[0];
	}

	/**
	 * Replaces the target selectors in a chat text by the names they match, like Bedrock /say, /me and /tell
	 * ("hi @a" -> "hi Steve, Alex"). Selectors that are malformed or match nothing are left as they are.
	 */
	public static function expandSelectors(CommandSender $sender, string $text) : string{
		return preg_replace_callback(
			'/(?<![\w@])@(?:p|a|r|e|s|n|initiator)(?:\[(?:"(?:\\\\.|[^\\\\"])*"|[^\]"])*\])?(?![\w\[])/u',
			function(array $matches) use ($sender) : string{
				try{
					$entities = TargetSelector::parse($matches[0])->selectEntities($sender);
				}catch(TargetSelectorException){
					return $matches[0];
				}
				return count($entities) > 0 ? TargetSelector::joinNames($entities) : $matches[0];
			},
			$text
		) ?? throw new AssumptionFailedError(preg_last_error_msg());
	}
}
