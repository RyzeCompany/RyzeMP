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
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Attribute;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Limits;
use pocketmine\utils\TextFormat;
use function abs;
use function count;
use function preg_match;
use function str_ends_with;
use function substr;

class XpCommand extends VanillaCommand implements ExhaustiveOverloads{

	public function __construct(){
		parent::__construct(
			"xp",
			KnownTranslationFactory::pocketmine_command_xp_description(),
			KnownTranslationFactory::pocketmine_command_xp_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_XP_SELF,
			DefaultPermissionNames::COMMAND_XP_OTHER
		]);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::int("amount"), CommandParameter::target("player", true)],
			[CommandParameter::postfixed("amount", "L"), CommandParameter::target("player", true)],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1 || count($args) > 2 || preg_match('/^-?\d+[lL]?$/', $args[0]) !== 1){
			throw new InvalidCommandSyntaxException();
		}

		$players = $this->fetchPermittedPlayerTargets($sender, $args[1] ?? null, DefaultPermissionNames::COMMAND_XP_SELF, DefaultPermissionNames::COMMAND_XP_OTHER);
		foreach($players as $player){
			$xpManager = $player->getXpManager();
			if(str_ends_with($args[0], "L") || str_ends_with($args[0], "l")){
				$xpLevelAttr = $player->getAttributeMap()->get(Attribute::EXPERIENCE_LEVEL) ?? throw new AssumptionFailedError();
				$maxXpLevel = (int) $xpLevelAttr->getMaxValue();
				$currentXpLevel = $xpManager->getXpLevel();
				$requestedLevels = (int) substr($args[0], 0, -1);
				$xpLevels = $this->getInteger($sender, substr($args[0], 0, -1), -$currentXpLevel, $maxXpLevel - $currentXpLevel);
				if($requestedLevels < -$currentXpLevel){
					//vanilla: a level below 0 is clamped to 0 and the bar is emptied too
					$xpManager->setXpAndProgress(0, 0.0);
					$sender->sendMessage(KnownTranslationFactory::commands_xp_success_negative_levels((string) $currentXpLevel, $player->getName()));
				}elseif($xpLevels >= 0){
					$xpManager->addXpLevels($xpLevels, false);
					$sender->sendMessage(KnownTranslationFactory::commands_xp_success_levels((string) $xpLevels, $player->getName()));
				}else{
					$xpLevels = abs($xpLevels);
					$xpManager->subtractXpLevels($xpLevels);
					$sender->sendMessage(KnownTranslationFactory::commands_xp_success_negative_levels((string) $xpLevels, $player->getName()));
				}
			}else{
				$xp = $this->getInteger($sender, $args[0], max: Limits::INT32_MAX);
				if($xp < 0){
					$sender->sendMessage(KnownTranslationFactory::commands_xp_failure_widthdrawXp()->prefix(TextFormat::RED));
					return true;
				}else{
					$xpManager->addXp($xp, false);
					$sender->sendMessage(KnownTranslationFactory::commands_xp_success((string) $xp, $player->getName()));
				}
			}
		}

		return true;
	}
}
