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
use pocketmine\command\selector\CommandTargets;
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\selector\TargetSelectorException;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use function array_keys;
use function count;
use function preg_match;
use function strtolower;

/**
 * /damage <target> <amount> [cause] [entity <damager>], like Bedrock: hurts the targets as if by the cause (Bedrock
 * ActorDamageCause names, e.g. fall, fire, entity_attack), optionally attributed to one damager entity. Armor,
 * resistance, immunities and invulnerability apply as for the real cause.
 */
class DamageCommand extends VanillaCommand{

	/** Bedrock damage cause names and the PocketMine cause they map to. */
	public const CAUSES = [
		"anvil" => EntityDamageEvent::CAUSE_FALLING_BLOCK,
		"block_explosion" => EntityDamageEvent::CAUSE_BLOCK_EXPLOSION,
		"campfire" => EntityDamageEvent::CAUSE_CAMPFIRE,
		"contact" => EntityDamageEvent::CAUSE_CONTACT,
		"drowning" => EntityDamageEvent::CAUSE_DROWNING,
		"entity_attack" => EntityDamageEvent::CAUSE_ENTITY_ATTACK,
		"entity_explosion" => EntityDamageEvent::CAUSE_ENTITY_EXPLOSION,
		"fall" => EntityDamageEvent::CAUSE_FALL,
		"falling_block" => EntityDamageEvent::CAUSE_FALLING_BLOCK,
		"fire" => EntityDamageEvent::CAUSE_FIRE,
		"fire_tick" => EntityDamageEvent::CAUSE_FIRE_TICK,
		"fireworks" => EntityDamageEvent::CAUSE_ENTITY_EXPLOSION,
		"fly_into_wall" => EntityDamageEvent::CAUSE_FLY_INTO_WALL,
		"freezing" => EntityDamageEvent::CAUSE_FREEZE,
		"lava" => EntityDamageEvent::CAUSE_LAVA,
		"lightning" => EntityDamageEvent::CAUSE_LIGHTNING,
		"magic" => EntityDamageEvent::CAUSE_MAGIC,
		"magma" => EntityDamageEvent::CAUSE_MAGMA,
		"none" => EntityDamageEvent::CAUSE_CUSTOM,
		"override" => EntityDamageEvent::CAUSE_CUSTOM,
		"projectile" => EntityDamageEvent::CAUSE_PROJECTILE,
		"sonic_boom" => EntityDamageEvent::CAUSE_SONIC_BOOM,
		"soul_campfire" => EntityDamageEvent::CAUSE_CAMPFIRE,
		"stalactite" => EntityDamageEvent::CAUSE_STALACTITE,
		"stalagmite" => EntityDamageEvent::CAUSE_STALAGMITE,
		"starve" => EntityDamageEvent::CAUSE_STARVATION,
		"suffocation" => EntityDamageEvent::CAUSE_SUFFOCATION,
		"suicide" => EntityDamageEvent::CAUSE_SUICIDE,
		"thorns" => EntityDamageEvent::CAUSE_THORNS,
		"void" => EntityDamageEvent::CAUSE_VOID,
		"wither" => EntityDamageEvent::CAUSE_WITHER,
	];

	public function __construct(){
		parent::__construct(
			"damage",
			KnownTranslationFactory::pocketmine_command_damage_description(),
			KnownTranslationFactory::pocketmine_command_damage_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_DAMAGE);
	}

	public function getOverloads() : array{
		$cause = fn(bool $optional) : CommandParameter => CommandParameter::enum("cause", "DamageCause", array_keys(self::CAUSES), $optional);
		return [
			[CommandParameter::target("target"), CommandParameter::int("amount"), $cause(true)],
			[CommandParameter::target("target"), CommandParameter::int("amount"), $cause(false), CommandParameter::enum("entity", "DamageOriginEntity", ["entity"]), CommandParameter::target("damager")],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2 || count($args) > 5 || count($args) === 4){
			throw new InvalidCommandSyntaxException();
		}
		if(preg_match('/^[-+]?\d+$/', $args[1]) !== 1){
			throw new InvalidCommandSyntaxException();
		}
		$amount = $this->getBoundedInt($sender, $args[1], 0, 2147483647);
		if($amount === null){
			return true;
		}
		$cause = EntityDamageEvent::CAUSE_CUSTOM;
		if(isset($args[2])){
			$cause = self::CAUSES[strtolower($args[2])] ?? throw new InvalidCommandSyntaxException();
		}
		$damager = null;
		if(isset($args[3])){
			if(strtolower($args[3]) !== "entity"){
				throw new InvalidCommandSyntaxException();
			}
			$damagers = CommandTargets::entities($sender, $args[4]);
			if(count($damagers) > 1){
				throw new TargetSelectorException(KnownTranslationFactory::commands_damage_tooManySources());
			}
			$damager = $damagers[0];
		}

		$targets = CommandTargets::entities($sender, $args[0]);
		$damaged = [];
		$failed = [];
		foreach($targets as $target){
			if(self::damage($target, (float) $amount, $cause, $damager)){
				$damaged[] = $target;
			}else{
				$failed[] = $target;
			}
		}
		if(count($damaged) > 0){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_damage_success(TargetSelector::joinNames($damaged)));
		}
		if(count($failed) > 0){
			$sender->sendMessage(KnownTranslationFactory::commands_damage_failed(TargetSelector::joinNames($failed))->prefix(TextFormat::RED));
		}
		return true;
	}

	/**
	 * Hurts an entity like /damage. Returns whether the damage was applied (not cancelled by immunity, game mode,
	 * invulnerability or a plugin).
	 */
	public static function damage(Entity $target, float $amount, int $cause, ?Entity $damager) : bool{
		if(!$target->isAlive()){
			return false;
		}
		$event = $damager !== null && $damager !== $target ?
			new EntityDamageByEntityEvent($damager, $target, $cause, $amount, [], 0.0) :
			new EntityDamageEvent($target, $cause, $amount);
		$target->attack($event);
		return !$event->isCancelled();
	}
}
