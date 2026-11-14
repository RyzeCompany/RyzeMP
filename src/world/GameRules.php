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

namespace pocketmine\world;

use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\vehicle\MinecartTNT;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\player\Player;
use function array_keys;
use function is_bool;
use function is_int;
use function strtolower;

/**
 * The game rules a world supports (Bedrock names and defaults), see {@link World::getGameRule()} and /gamerule.
 * Each world saves its rules in its level.dat (Bedrock worlds), like the vanilla game.
 *
 * The rules only set the default outcome: events (PlayerDeathEvent, EntityDamageEvent, BlockBreakEvent,
 * EntityExplodeEvent...) are still called, so plugins can change the result.
 */
final class GameRules{
	public const DO_DAYLIGHT_CYCLE = "dodaylightcycle";
	public const DO_WEATHER_CYCLE = "doweathercycle";
	public const DO_MOB_SPAWNING = "domobspawning";
	public const DO_INSOMNIA = "doinsomnia";
	public const DO_PATROL_SPAWNING = "dopatrolspawning";
	public const DO_TRADER_SPAWNING = "dotraderspawning";
	public const DO_FIRE_TICK = "dofiretick";
	public const DO_MOB_LOOT = "domobloot";
	public const DO_TILE_DROPS = "dotiledrops";
	public const DO_IMMEDIATE_RESPAWN = "doimmediaterespawn";
	public const KEEP_INVENTORY = "keepinventory";
	public const NATURAL_REGENERATION = "naturalregeneration";
	public const MOB_GRIEFING = "mobgriefing";
	public const TNT_EXPLODES = "tntexplodes";
	public const PVP = "pvp";
	public const FALL_DAMAGE = "falldamage";
	public const FIRE_DAMAGE = "firedamage";
	public const DROWNING_DAMAGE = "drowningdamage";
	public const FREEZE_DAMAGE = "freezedamage";
	public const SHOW_COORDINATES = "showcoordinates";
	public const SHOW_DAYS_PLAYED = "showdaysplayed";
	public const SHOW_DEATH_MESSAGES = "showdeathmessages";
	public const RANDOM_TICK_SPEED = "randomtickspeed";
	public const DO_ENTITY_DROPS = "doentitydrops";
	public const PROJECTILES_CAN_BREAK_BLOCKS = "projectilescanbreakblocks";
	public const COMMAND_BLOCK_OUTPUT = "commandblockoutput";
	public const SEND_COMMAND_FEEDBACK = "sendcommandfeedback";
	public const COMMAND_BLOCKS_ENABLED = "commandblocksenabled";
	public const MAX_COMMAND_CHAIN_LENGTH = "maxcommandchainlength";
	public const FUNCTION_COMMAND_LIMIT = "functioncommandlimit";
	public const SHOW_TAGS = "showtags";
	public const SHOW_BORDER_EFFECT = "showbordereffect";
	public const SHOW_RECIPE_MESSAGES = "showrecipemessages";
	public const PLAYERS_SLEEPING_PERCENTAGE = "playerssleepingpercentage";
	public const SPAWN_RADIUS = "spawnradius";
	public const RESPAWN_BLOCKS_EXPLODE = "respawnblocksexplode";
	public const TNT_EXPLOSION_DROP_DECAY = "tntexplosiondropdecay";
	public const LOCATOR_BAR = "locatorbar";
	public const RECIPES_UNLOCK = "recipesunlock";
	public const DO_LIMITED_CRAFTING = "dolimitedcrafting";

	/**
	 * Default value of every rule (its type is the type of the default), and whether clients are told about it
	 * (rules the client applies itself: HUD, sun and moon, respawn screen).
	 *
	 * @phpstan-var array<string, array{bool|int, bool}>
	 */
	private const RULES = [
		self::DO_DAYLIGHT_CYCLE => [true, true],
		self::DO_WEATHER_CYCLE => [true, false],
		self::DO_MOB_SPAWNING => [true, false],
		self::DO_INSOMNIA => [true, false],
		self::DO_PATROL_SPAWNING => [true, false],
		self::DO_TRADER_SPAWNING => [true, false],
		self::DO_FIRE_TICK => [true, false],
		self::DO_MOB_LOOT => [true, false],
		self::DO_TILE_DROPS => [true, false],
		self::DO_IMMEDIATE_RESPAWN => [false, true],
		self::KEEP_INVENTORY => [false, true],
		self::NATURAL_REGENERATION => [true, false], //clients are always told false: the server heals players
		self::MOB_GRIEFING => [true, false],
		self::TNT_EXPLODES => [true, false],
		self::PVP => [true, false],
		self::FALL_DAMAGE => [true, false],
		self::FIRE_DAMAGE => [true, false],
		self::DROWNING_DAMAGE => [true, false],
		self::FREEZE_DAMAGE => [true, false],
		self::SHOW_COORDINATES => [false, true],
		self::SHOW_DAYS_PLAYED => [false, true],
		self::SHOW_DEATH_MESSAGES => [true, false],
		self::RANDOM_TICK_SPEED => [1, false],
		self::DO_ENTITY_DROPS => [true, false],
		self::PROJECTILES_CAN_BREAK_BLOCKS => [true, false],
		self::COMMAND_BLOCK_OUTPUT => [true, false],
		self::SEND_COMMAND_FEEDBACK => [true, false],
		self::COMMAND_BLOCKS_ENABLED => [true, false],
		self::MAX_COMMAND_CHAIN_LENGTH => [65535, false],
		self::FUNCTION_COMMAND_LIMIT => [10000, false],
		self::SHOW_TAGS => [true, true], //item tooltips (can place on / can destroy)
		self::SHOW_BORDER_EFFECT => [true, true], //particles of border blocks
		self::SHOW_RECIPE_MESSAGES => [true, true], //"new recipe unlocked" toasts
		self::PLAYERS_SLEEPING_PERCENTAGE => [100, false], //share of the players that must sleep to skip the night
		self::SPAWN_RADIUS => [10, false], //BDS 1.26.52 default (blocks around the world spawn)
		self::RESPAWN_BLOCKS_EXPLODE => [true, false], //beds outside the Overworld, respawn anchors outside the Nether
		self::TNT_EXPLOSION_DROP_DECAY => [false, false], //false: every block broken by TNT or a TNT minecart drops
		self::LOCATOR_BAR => [true, true], //HUD bar showing the direction of the other players (see LocatorBarUpdater)
		//recipe book (see RecipeBook): false shows every recipe unlocked, the client applies it like BDS
		self::RECIPES_UNLOCK => [true, true],
		self::DO_LIMITED_CRAFTING => [false, true], //only unlocked recipes can be crafted
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getNames() : array{
		return array_keys(self::RULES);
	}

	/**
	 * Returns the rule's name in lowercase if the rule exists (names are case-insensitive), null otherwise.
	 */
	public static function normalizeName(string $name) : ?string{
		$name = strtolower($name);
		return isset(self::RULES[$name]) ? $name : null;
	}

	public static function getDefault(string $name) : bool|int{
		return self::RULES[$name][0] ?? throw new \InvalidArgumentException("Unknown game rule \"$name\"");
	}

	public static function isBool(string $name) : bool{
		return is_bool(self::getDefault($name));
	}

	public static function isSentToClients(string $name) : bool{
		return self::RULES[$name][1] ?? throw new \InvalidArgumentException("Unknown game rule \"$name\"");
	}

	/**
	 * Returns the value if it has the rule's type, null otherwise (e.g. a corrupted level.dat entry).
	 */
	public static function validate(string $name, mixed $value) : bool|int|null{
		$default = self::getDefault($name);
		if(is_bool($default)){
			return is_bool($value) ? $value : null;
		}
		return is_int($value) && $value >= 0 ? $value : null;
	}

	/**
	 * Whether the world's rules let this damage happen to a player (falldamage, firedamage, drowningdamage,
	 * freezedamage, pvp). Only players are protected, like in vanilla.
	 */
	public static function allowsPlayerDamage(World $world, EntityDamageEvent $source) : bool{
		$cause = $source->getCause();
		$rule = match(true){
			$cause === EntityDamageEvent::CAUSE_FALL, $cause === EntityDamageEvent::CAUSE_STALAGMITE => self::FALL_DAMAGE,
			EntityDamageEvent::isFireCause($cause) => self::FIRE_DAMAGE,
			$cause === EntityDamageEvent::CAUSE_DROWNING => self::DROWNING_DAMAGE,
			$cause === EntityDamageEvent::CAUSE_FREEZE => self::FREEZE_DAMAGE,
			default => null
		};
		if($rule !== null && $world->getGameRule($rule) === false){
			return false;
		}
		if($source instanceof EntityDamageByEntityEvent && $source->getDamager() instanceof Player && $source->getEntity() instanceof Player && $source->getDamager() !== $source->getEntity()){
			return $world->getGameRule(self::PVP) !== false;
		}
		return true;
	}

	/**
	 * Whether an explosion caused by this entity may destroy blocks (mobgriefing; TNT and TNT minecarts are not mobs:
	 * vanilla tnt_minecart.json has no destroy_affected_by_griefing).
	 */
	public static function allowsExplosionGriefing(World $world, object $cause) : bool{
		return $cause instanceof PrimedTNT || $cause instanceof MinecartTNT || $world->getGameRule(self::MOB_GRIEFING) !== false;
	}

	/**
	 * Whether mobs may change blocks (sheep eating grass, villagers farming, ravagers breaking leaves, snow golem
	 * trails...) and pick up items (mobgriefing).
	 */
	public static function allowsMobGriefing(World $world) : bool{
		return $world->getGameRule(self::MOB_GRIEFING) !== false;
	}

	/**
	 * Whether non-living entities (boats, minecarts, armor stands, paintings, leash knots) drop their item when they
	 * are broken (doentitydrops).
	 */
	public static function allowsEntityDrops(World $world) : bool{
		return $world->getGameRule(self::DO_ENTITY_DROPS) !== false;
	}

	/**
	 * Whether projectiles may break the blocks they hit (decorated pots, chorus flowers, pointed dripstone...)
	 * (projectilescanbreakblocks).
	 */
	public static function allowsProjectileBlockBreaking(World $world) : bool{
		return $world->getGameRule(self::PROJECTILES_CAN_BREAK_BLOCKS) !== false;
	}

	/**
	 * Whether beds used outside the Overworld and respawn anchors used outside the Nether explode
	 * (respawnblocksexplode). When they don't, using them just fails.
	 */
	public static function allowsRespawnBlockExplosions(World $world) : bool{
		return $world->getGameRule(self::RESPAWN_BLOCKS_EXPLODE) !== false;
	}
}
