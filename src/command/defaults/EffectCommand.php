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
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\InstantEffect;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\entity\Living;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\Limits;
use pocketmine\utils\TextFormat;
use function count;
use function implode;
use function strtolower;

/**
 * /effect <target> <effect> [seconds|infinite] [amplifier] [hideParticles], /effect <target> clear [effect]. Targets may be
 * any living entities (e.g. @e[type=zombie]); other entities are ignored.
 */
class EffectCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"effect",
			KnownTranslationFactory::pocketmine_command_effect_description(),
			KnownTranslationFactory::commands_effect_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_EFFECT_SELF,
			DefaultPermissionNames::COMMAND_EFFECT_OTHER
		]);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::target("player"), CommandParameter::enum("clear", "EffectClear", ["clear"]), CommandParameter::effect("effect", true)],
			[
				CommandParameter::target("player"),
				CommandParameter::effect("effect"),
				CommandParameter::int("seconds", true),
				CommandParameter::int("amplifier", true),
				CommandParameter::boolean("hideParticles", true)
			],
			[
				CommandParameter::target("player"),
				CommandParameter::effect("effect"),
				CommandParameter::enum("infinite", "EffectInfinite", ["infinite"]),
				CommandParameter::int("amplifier", true),
				CommandParameter::boolean("hideParticles", true)
			],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$clear = strtolower($args[1]) === "clear";
		if($clear && count($args) > 3){
			throw new InvalidCommandSyntaxException();
		}

		$targets = [];
		foreach($this->fetchPermittedEntityTargets($sender, $args[0], DefaultPermissionNames::COMMAND_EFFECT_SELF, DefaultPermissionNames::COMMAND_EFFECT_OTHER) as $entity){
			if($entity instanceof Living){
				$targets[] = $entity;
			}
		}
		if(count($targets) === 0){
			return true;
		}

		if($clear && !isset($args[2])){
			foreach($targets as $target){
				$target->getEffects()->clear();
			}
			$sender->sendMessage(KnownTranslationFactory::commands_effect_success_removed_all(self::names($targets)));
			return true;
		}

		$effectName = $clear ? $args[2] : $args[1];
		$effect = StringToEffectParser::getInstance()->parse($effectName);
		if($effect === null){
			$sender->sendMessage(KnownTranslationFactory::commands_effect_notFound($effectName)->prefix(TextFormat::RED));
			return true;
		}

		$amplification = 0;
		$infinite = false;
		$visible = true;

		if($clear){
			$duration = 0; //removes the effect
		}else{
			if(count($args) >= 3){
				if(strtolower($args[2]) === "infinite"){
					$duration = null;
					$infinite = true;
				}else{
					if(($d = $this->getBoundedInt($sender, $args[2], 0, (int) (Limits::INT32_MAX / 20))) === null){
						return false;
					}
					$duration = $d * 20; // ticks
				}
			}else{
				$duration = null;
			}

			if(count($args) >= 4){
				$amplification = $this->getBoundedInt($sender, $args[3], 0, 255);
				if($amplification === null){
					return false;
				}
			}

			if(count($args) >= 5){
				$v = strtolower($args[4]);
				if($v === "on" || $v === "true" || $v === "t" || $v === "1"){
					$visible = false;
				}
			}
		}

		if($effect instanceof InstantEffect && $duration !== 0){
			//vanilla applies instant health and damage once, whatever the duration (they apply every tick while they last)
			$duration = 1;
			$infinite = false;
		}

		if($duration === 0){
			$removed = [];
			foreach($targets as $target){
				$effectManager = $target->getEffects();
				if(!$effectManager->has($effect)){
					if(count($targets) === 1){
						if(count($effectManager->all()) === 0){
							$sender->sendMessage(KnownTranslationFactory::commands_effect_failure_notActive_all(self::names($targets)));
						}else{
							$sender->sendMessage(KnownTranslationFactory::commands_effect_failure_notActive($effect->getName(), self::names($targets)));
						}
						return true;
					}
					continue;
				}
				$effectManager->remove($effect);
				$removed[] = $target;
			}
			if(count($removed) === 0){
				$sender->sendMessage(KnownTranslationFactory::commands_effect_failure_notActive($effect->getName(), self::names($targets)));
			}else{
				$sender->sendMessage(KnownTranslationFactory::commands_effect_success_removed($effect->getName(), self::names($removed)));
			}
		}else{
			$instance = new EffectInstance($effect, $duration, $amplification, $visible, infinite: $infinite);
			foreach($targets as $target){
				$target->getEffects()->add(clone $instance);
			}
			if($infinite){
				self::broadcastCommandMessage($sender, KnownTranslationFactory::commands_effect_success_infinite($effect->getName(), (string) $instance->getAmplifier(), self::names($targets)));
			}else{
				self::broadcastCommandMessage($sender, KnownTranslationFactory::commands_effect_success($effect->getName(), (string) $instance->getAmplifier(), self::names($targets), (string) ($instance->getDuration() / 20)));
			}
		}

		return true;
	}

	/**
	 * Display names of the targets (players' display names, like before selectors were supported).
	 *
	 * @param Living[] $targets
	 */
	private static function names(array $targets) : string{
		$names = [];
		foreach($targets as $target){
			$names[] = $target instanceof Player ?$target->getDisplayName() : TargetSelector::getEntityName($target);
		}
		return implode(", ", $names);
	}
}
