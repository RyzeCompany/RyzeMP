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
use pocketmine\command\selector\CommandTargets;
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use function array_keys;
use function count;
use function implode;
use function strtolower;

/**
 * Bedrock /tag: /tag <targets> add <name>, /tag <targets> remove <name>, /tag <targets> list. Tags are stored in the
 * entities (Entity::getCommandTags()) and matched by the tag= selector argument.
 */
class TagCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"tag",
			KnownTranslationFactory::pocketmine_command_tag_description(),
			KnownTranslationFactory::commands_tag_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_TAG);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::target("entity"), CommandParameter::enum("action", "TagChangeAction", ["add", "remove"]), CommandParameter::string("name")],
			[CommandParameter::target("entity"), CommandParameter::enum("list", "TagListAction", ["list"])],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$action = strtolower($args[1]);
		if(($action === "add" || $action === "remove") && count($args) !== 3){
			throw new InvalidCommandSyntaxException();
		}
		if($action !== "add" && $action !== "remove" && ($action !== "list" || count($args) !== 2)){
			throw new InvalidCommandSyntaxException();
		}

		$entities = CommandTargets::entities($sender, $args[0]);

		if($action === "list"){
			$tags = [];
			foreach($entities as $entity){
				foreach($entity->getCommandTags() as $tag){
					$tags[$tag] = true;
				}
			}
			$list = implode(", ", array_keys($tags));
			if(count($entities) === 1){
				$name = TargetSelector::getEntityName($entities[0]);
				$sender->sendMessage(count($tags) === 0 ?
					KnownTranslationFactory::commands_tag_list_single_empty($name) :
					KnownTranslationFactory::commands_tag_list_single_success($name, (string) count($tags), $list)
				);
			}else{
				$sender->sendMessage(count($tags) === 0 ?
					KnownTranslationFactory::commands_tag_list_multiple_empty((string) count($entities)) :
					KnownTranslationFactory::commands_tag_list_multiple_success((string) count($entities), (string) count($tags), $list)
				);
			}
			return true;
		}

		$tag = $args[2];
		$changed = [];
		foreach($entities as $entity){
			if($action === "add" ? $entity->addCommandTag($tag) : $entity->removeCommandTag($tag)){
				$changed[] = $entity;
			}
		}
		if(count($changed) === 0){
			$sender->sendMessage(($action === "add" ? KnownTranslationFactory::commands_tag_add_failed() : KnownTranslationFactory::commands_tag_remove_failed())->prefix(TextFormat::RED));
			return true;
		}
		if(count($changed) === 1){
			$name = TargetSelector::getEntityName($changed[0]);
			$message = $action === "add" ? KnownTranslationFactory::commands_tag_add_success_single($tag, $name) : KnownTranslationFactory::commands_tag_remove_success_single($tag, $name);
		}else{
			$count = (string) count($changed);
			$message = $action === "add" ? KnownTranslationFactory::commands_tag_add_success_multiple($tag, $count) : KnownTranslationFactory::commands_tag_remove_success_multiple($tag, $count);
		}
		Command::broadcastCommandMessage($sender, $message);
		return true;
	}
}
