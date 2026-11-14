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
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Entity;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Translatable;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use function count;
use function in_array;
use function strtolower;

/**
 * /ride, like Bedrock:
 * - /ride <riders> start_riding <ride> [teleport_rider|teleport_ride] [if_group_fits|until_full]
 * - /ride <riders> stop_riding
 * - /ride <rides> evict_riders
 * - /ride <rides> summon_rider <entityType> [spawnEvent] [nameTag]
 * - /ride <riders> summon_ride <entityType> [reassign_rides|no_ride_change|skip_riders] [spawnEvent] [nameTag]
 *
 * Riders take the free seats of the ride that they are allowed to take (the vanilla rideable component: seats and
 * family types, see {@link Entity::canAddPassenger()}).
 */
class RideCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"ride",
			KnownTranslationFactory::pocketmine_command_ride_description(),
			KnownTranslationFactory::pocketmine_command_ride_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_RIDE);
	}

	public function getOverloads() : array{
		$entity = fn() : CommandParameter => SummonCommand::entityTypeParameter("entityType");
		return [
			[CommandParameter::target("riders"), CommandParameter::enum("mode", "RideStartRiding", ["start_riding"]), CommandParameter::target("ride"),
				CommandParameter::enum("teleportRules", "TeleportRules", ["teleport_ride", "teleport_rider"], true), CommandParameter::enum("howToFill", "FillType", ["if_group_fits", "until_full"], true)],
			[CommandParameter::target("riders"), CommandParameter::enum("mode", "RideStopRiding", ["stop_riding"])],
			[CommandParameter::target("rides"), CommandParameter::enum("mode", "RideEvictRiders", ["evict_riders"])],
			[CommandParameter::target("rides"), CommandParameter::enum("mode", "RideSummonRider", ["summon_rider"]), $entity(), CommandParameter::string("spawnEvent", true), CommandParameter::string("nameTag", true)],
			[CommandParameter::target("riders"), CommandParameter::enum("mode", "RideSummonRide", ["summon_ride"]), $entity(),
				CommandParameter::enum("rideRules", "RideRules", ["no_ride_change", "reassign_rides", "skip_riders"], true), CommandParameter::string("spawnEvent", true), CommandParameter::string("nameTag", true)],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}
		$mode = strtolower($args[1]);
		switch($mode){
			case "start_riding":
				if(count($args) < 3 || count($args) > 5){
					throw new InvalidCommandSyntaxException();
				}
				$teleport = "teleport_rider";
				$fill = "until_full";
				foreach([$args[3] ?? null, $args[4] ?? null] as $option){
					if($option === null){
						continue;
					}
					$option = strtolower($option);
					if(in_array($option, ["teleport_ride", "teleport_rider"], true)){
						$teleport = $option;
					}elseif(in_array($option, ["if_group_fits", "until_full"], true)){
						$fill = $option;
					}else{
						throw new InvalidCommandSyntaxException();
					}
				}
				$riders = CommandTargets::entities($sender, $args[0]);
				$rides = CommandTargets::entities($sender, $args[2]);
				if(count($rides) > 1){
					self::fail($sender, KnownTranslationFactory::commands_ride_startRiding_tooManyRides());
					return true;
				}
				if($teleport === "teleport_ride" && count($riders) > 1){
					self::fail($sender, KnownTranslationFactory::commands_ride_startRiding_tooManyRiders());
					return true;
				}
				$this->startRiding($sender, $riders, $rides[0], $teleport === "teleport_ride", $fill === "if_group_fits");
				return true;
			case "stop_riding":
				if(count($args) !== 2){
					throw new InvalidCommandSyntaxException();
				}
				$stopped = [];
				foreach(CommandTargets::entities($sender, $args[0]) as $rider){
					if($rider->isRiding() && $rider->dismount(true)){
						$stopped[] = $rider;
					}
				}
				if(count($stopped) > 0){
					Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_ride_stopRiding_success(TargetSelector::joinNames($stopped)));
				}
				return true;
			case "evict_riders":
				if(count($args) !== 2){
					throw new InvalidCommandSyntaxException();
				}
				$evicted = [];
				foreach(CommandTargets::entities($sender, $args[0]) as $ride){
					if(count($ride->getPassengers()) > 0){
						$ride->ejectPassengers();
						$evicted[] = $ride;
					}
				}
				if(count($evicted) > 0){
					Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_ride_evictRiders_success(TargetSelector::joinNames($evicted)));
				}
				return true;
			case "summon_rider":
				if(count($args) < 3 || count($args) > 5){
					throw new InvalidCommandSyntaxException();
				}
				$this->summonRiders($sender, CommandTargets::entities($sender, $args[0]), SummonCommand::resolveEntityType($args[2]), $args[3] ?? null, $args[4] ?? null);
				return true;
			case "summon_ride":
				if(count($args) < 3 || count($args) > 6){
					throw new InvalidCommandSyntaxException();
				}
				$rules = "reassign_rides";
				$i = 3;
				if(isset($args[$i]) && in_array(strtolower($args[$i]), ["no_ride_change", "reassign_rides", "skip_riders"], true)){
					$rules = strtolower($args[$i++]);
				}
				if(count($args) > $i + 2){
					throw new InvalidCommandSyntaxException();
				}
				$this->summonRides($sender, CommandTargets::entities($sender, $args[0]), SummonCommand::resolveEntityType($args[2]), $rules, $args[$i] ?? null, $args[$i + 1] ?? null);
				return true;
			default:
				throw new InvalidCommandSyntaxException();
		}
	}

	private static function fail(CommandSender $sender, Translatable $message) : void{
		$sender->sendMessage($message->prefix(TextFormat::RED));
	}

	/**
	 * @param Entity[] $riders
	 * @phpstan-param list<Entity> $riders
	 */
	private function startRiding(CommandSender $sender, array $riders, Entity $ride, bool $teleportRide, bool $ifGroupFits) : void{
		$seats = count($ride->getRideableSeats());
		if($seats === 0){
			self::fail($sender, KnownTranslationFactory::commands_ride_startRiding_notRideable());
			return;
		}
		$free = $seats - count($ride->getPassengers());
		$newRiders = [];
		foreach($riders as $rider){
			if($rider->getVehicle() !== $ride){
				$newRiders[] = $rider;
			}
		}
		if($free <= 0 && count($newRiders) > 0){
			self::fail($sender, KnownTranslationFactory::commands_ride_startRiding_rideFull());
			return;
		}
		if($ifGroupFits && count($newRiders) > $free){
			self::fail($sender, KnownTranslationFactory::commands_ride_startRiding_groupDoesntFit());
			return;
		}
		$added = [];
		$failed = [];
		$full = false;
		foreach($newRiders as $rider){
			if(count($ride->getPassengers()) >= $seats){
				$full = true;
				break;
			}
			if($teleportRide){
				$ride->teleport($rider->getLocation());
			}elseif($rider->getWorld() !== $ride->getWorld()){
				$rider->teleport($ride->getLocation());
			}
			if($rider->mount($ride)){
				$added[] = $rider;
			}else{
				$failed[] = $rider;
			}
		}
		if(count($added) > 0){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_ride_startRiding_success(TargetSelector::joinNames($added)));
		}
		if(count($failed) > 0){
			self::fail($sender, KnownTranslationFactory::commands_ride_startRiding_failure(TargetSelector::joinNames($failed)));
		}
		if($full){
			self::fail($sender, KnownTranslationFactory::commands_ride_startRiding_rideFullRidersLeft());
		}
	}

	/**
	 * @param Entity[] $rides
	 * @phpstan-param list<Entity> $rides
	 */
	private function summonRiders(CommandSender $sender, array $rides, string $type, ?string $event, ?string $nameTag) : void{
		$success = [];
		$failed = [];
		$full = [];
		$notRideable = [];
		foreach($rides as $ride){
			if(count($ride->getRideableSeats()) === 0){
				$notRideable[] = $ride;
				continue;
			}
			if(count($ride->getPassengers()) >= count($ride->getRideableSeats())){
				$full[] = $ride;
				continue;
			}
			$rider = SummonCommand::summon($type, $ride->getLocation(), $event, $nameTag);
			if($rider === null){
				throw new InvalidCommandSyntaxException();
			}
			if($rider->mount($ride)){
				$success[] = $ride;
			}else{
				$failed[] = $ride;
			}
		}
		if(count($success) > 0){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_ride_summonRider_success(TargetSelector::joinNames($success)));
		}
		if(count($failed) > 0){
			self::fail($sender, KnownTranslationFactory::commands_ride_summonRider_failed(TargetSelector::joinNames($failed)));
		}
		if(count($full) > 0){
			self::fail($sender, KnownTranslationFactory::commands_ride_summonRider_fullRides(TargetSelector::joinNames($full)));
		}
		if(count($notRideable) > 0){
			self::fail($sender, KnownTranslationFactory::commands_ride_summonRider_ridesWithNoRideableComp(TargetSelector::joinNames($notRideable)));
		}
	}

	/**
	 * @param Entity[] $riders
	 * @phpstan-param list<Entity> $riders
	 */
	private function summonRides(CommandSender $sender, array $riders, string $type, string $rules, ?string $event, ?string $nameTag) : void{
		$success = [];
		$failed = [];
		$skipped = [];
		foreach($riders as $rider){
			if($rider->isRiding() && $rules === "skip_riders"){
				$skipped[] = $rider;
				continue;
			}
			$ride = SummonCommand::summon($type, $rider->getLocation(), $event, $nameTag);
			if($ride === null){
				throw new InvalidCommandSyntaxException();
			}
			if(count($ride->getRideableSeats()) === 0){
				self::fail($sender, KnownTranslationFactory::commands_ride_summonRide_notRideable());
				continue;
			}
			if($rider->isRiding() && $rules === "no_ride_change"){
				continue;
			}
			if($rider->mount($ride)){
				$success[] = $rider;
			}else{
				$failed[] = $rider;
			}
		}
		if(count($success) > 0){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_ride_summonRide_success(TargetSelector::joinNames($success)));
		}
		if(count($failed) > 0){
			self::fail($sender, KnownTranslationFactory::commands_ride_summonRide_failed(TargetSelector::joinNames($failed)));
		}
		if(count($skipped) > 0){
			self::fail($sender, KnownTranslationFactory::commands_ride_summonRide_skipped(TargetSelector::joinNames($skipped)));
		}
	}
}
