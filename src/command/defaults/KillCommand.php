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
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use function count;

/**
 * /kill [target]: kills the targets (players, mobs, or any entity with a selector such as @e[type=cow,r=20]);
 * entities that cannot die (items, arrows...) are removed, like Bedrock.
 */
class KillCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"kill",
			KnownTranslationFactory::pocketmine_command_kill_description(),
			KnownTranslationFactory::pocketmine_command_kill_usage(),
			["suicide"]
		);
		$this->setPermissions([DefaultPermissionNames::COMMAND_KILL_SELF, DefaultPermissionNames::COMMAND_KILL_OTHER]);
	}

	public function getOverloads() : array{
		return [[CommandParameter::target("target", true)]];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) >= 2){
			throw new InvalidCommandSyntaxException();
		}

		$targets = $this->fetchPermittedEntityTargets($sender, $args[0] ?? null, DefaultPermissionNames::COMMAND_KILL_SELF, DefaultPermissionNames::COMMAND_KILL_OTHER);
		if(count($targets) === 0){
			return true;
		}

		foreach($targets as $entity){
			if($entity instanceof Living){
				$entity->attack(new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_SUICIDE, $entity->getHealth()));
			}else{
				$entity->flagForDespawn();
			}
		}
		$names = TargetSelector::joinNames($targets);
		if(count($targets) === 1 && $targets[0] === $sender){
			$sender->sendMessage(KnownTranslationFactory::commands_kill_successful($names));
		}else{
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_kill_successful($names));
		}

		return true;
	}
}
