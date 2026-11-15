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
use pocketmine\entity\animal\Animal;
use pocketmine\entity\boss\Wither;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\monster\Illager;
use pocketmine\entity\monster\Monster;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\world\World;
use function abs;
use function array_slice;
use function count;
use function fmod;
use function implode;
use function in_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function preg_match;
use function shuffle;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function usort;

/**
 * A Bedrock-style target selector (@p, @a, @r, @e, @n, @s, @initiator) with its arguments, parsed by {@link parse()}.
 *
 * Supported arguments: x, y, z (absolute or ~relative), r, rm, dx, dy, dz, c (negative = farthest first), type,
 * name, tag, family, m (game mode), l, lm, rx, rxm, ry, rym. type, name, m, tag and family accept "!" negation; type,
 * name and m may appear several times only when negated, tag and family may repeat. hasitem, scores, haspermission
 * and has_property are recognised but not supported (a {@link TargetSelectorException} explains that).
 *
 * Semantics follow Bedrock: @p is the nearest living player (spectators excluded), @a every online player (dead ones
 * too), @r a random living player (any entity when a non-player type is given), @e every living entity, @n the
 * nearest living entity of any type (like @e[c=1]), @s the executing entity. type= cannot be used with @a and @p.
 * Without position arguments @a, @r and @e look in every world; any position argument, c, @p or @n restricts the
 * selection to the origin's world. Console senders use the default world spawn as origin.
 *
 * Errors use the vanilla translation keys (commands.generic.*) when Bedrock has one, so clients show them in their own
 * language.
 *
 * Use {@link CommandTargets} to resolve command arguments that may be a selector or a player name.
 */
final class TargetSelector{
	public const NEAREST_PLAYER = "p";
	public const ALL_PLAYERS = "a";
	public const RANDOM_PLAYER = "r";
	public const ALL_ENTITIES = "e";
	public const SELF = "s";
	public const INITIATOR = "initiator";
	/** Nearest entity of any type (Bedrock 1.21+). */
	public const NEAREST_ENTITY = "n";

	private const TYPES = [self::NEAREST_PLAYER, self::ALL_PLAYERS, self::RANDOM_PLAYER, self::ALL_ENTITIES, self::SELF, self::INITIATOR, self::NEAREST_ENTITY];

	/**
	 * Selector variables in the order the Bedrock client suggests them.
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public const ALL_TYPES = [self::ALL_PLAYERS, self::ALL_ENTITIES, self::NEAREST_ENTITY, self::NEAREST_PLAYER, self::RANDOM_PLAYER, self::SELF, self::INITIATOR];

	private const UNSUPPORTED_ARGUMENTS = ["hasitem", "scores", "haspermission", "has_property"];

	public const PLAYER_TYPE = "minecraft:player";

	/**
	 * Old entity names that also select the entities that replaced them, like BDS (type=villager selects villager_v2).
	 */
	private const TYPE_ALIASES = [
		EntityIds::VILLAGER => EntityIds::VILLAGER_V2,
		EntityIds::ZOMBIE_VILLAGER => EntityIds::ZOMBIE_VILLAGER_V2,
	];

	/** @phpstan-var array{float, bool}|null */
	private ?array $x = null;
	/** @phpstan-var array{float, bool}|null */
	private ?array $y = null;
	/** @phpstan-var array{float, bool}|null */
	private ?array $z = null;
	private ?float $r = null;
	private ?float $rm = null;
	private ?float $dx = null;
	private ?float $dy = null;
	private ?float $dz = null;
	private ?int $c = null;
	private ?int $l = null;
	private ?int $lm = null;
	private ?float $rx = null;
	private ?float $rxm = null;
	private ?float $ry = null;
	private ?float $rym = null;
	/**
	 * @var array[]
	 * @phpstan-var list<array{string, bool}>
	 */
	private array $types = [];
	/**
	 * @var array[]
	 * @phpstan-var list<array{string, bool}>
	 */
	private array $names = [];
	/**
	 * @var array[]
	 * @phpstan-var list<array{string, bool}>
	 */
	private array $tags = [];
	/**
	 * @var array[]
	 * @phpstan-var list<array{string, bool}>
	 */
	private array $families = [];
	/**
	 * Game modes (null = the server default game mode) and whether they are negated.
	 * @var array[]
	 * @phpstan-var list<array{GameMode|null, bool}>
	 */
	private array $gameModes = [];

	private function __construct(
		private string $type,
		private string $text
	){}

	/**
	 * Returns whether the string looks like a target selector (starts with "@" followed by a selector type), so that
	 * commands can tell selectors from player names.
	 */
	public static function isSelector(string $text) : bool{
		return preg_match('/^@(p|a|r|e|s|n|initiator)(\[|$)/', $text) === 1;
	}

	/**
	 * Returns whether a command argument must be treated as a target selector: anything starting with "@" (player
	 * names cannot contain "@"), so that a mistyped selector like "@x" gets a syntax error instead of "player not
	 * found".
	 */
	public static function isSelectorArgument(string $text) : bool{
		return str_starts_with($text, "@");
	}

	/**
	 * Vanilla "Syntax error: Unexpected ..." message pointing at the given part of the selector text.
	 */
	private static function syntaxError(string $text, string $token) : TargetSelectorException{
		$pos = $token === "" ? false : strpos($text, $token);
		if($pos === false){
			return new TargetSelectorException(KnownTranslationFactory::commands_generic_syntax("", $text, ""));
		}
		return new TargetSelectorException(KnownTranslationFactory::commands_generic_syntax(substr($text, 0, $pos), $token, substr($text, $pos + strlen($token))));
	}

	/**
	 * @throws TargetSelectorException if the selector is malformed or uses unknown/unsupported arguments
	 */
	public static function parse(string $text) : self{
		$text = trim($text);
		if(preg_match('/^@([a-zA-Z_]*)(?:\[(.*)\])?$/s', $text, $matches) !== 1){
			if(preg_match('/^@[a-zA-Z_]*/', $text, $typeMatch) === 1 && !in_array(substr($typeMatch[0], 1), self::TYPES, true)){
				throw self::syntaxError($text, $typeMatch[0]);
			}
			$bracket = strpos($text, "[");
			throw self::syntaxError($text, $bracket !== false ? substr($text, $bracket) : $text);
		}
		$type = $matches[1];
		if(!in_array($type, self::TYPES, true)){
			throw self::syntaxError($text, "@" . $type);
		}
		$selector = new self($type, $text);
		$arguments = $matches[2] ?? "";
		if(trim($arguments) !== ""){
			$seen = [];
			foreach(self::splitArguments($arguments, $text) as $argument){
				$eq = self::indexOfUnquoted($argument, "=");
				if($eq === null){
					throw self::syntaxError($text, trim($argument));
				}
				$key = strtolower(trim(substr($argument, 0, $eq)));
				$value = trim(substr($argument, $eq + 1));
				$selector->addArgument($key, $value, $seen);
			}
			$selector->validate();
		}
		return $selector;
	}

	/**
	 * Checks combinations of arguments, like Bedrock.
	 * @throws TargetSelectorException
	 */
	private function validate() : void{
		if($this->r !== null && $this->rm !== null && $this->rm > $this->r){
			throw new TargetSelectorException(KnownTranslationFactory::commands_generic_radiusError());
		}
		if($this->l !== null && $this->lm !== null && $this->lm > $this->l){
			throw new TargetSelectorException(KnownTranslationFactory::commands_generic_levelError());
		}
		if(($this->type === self::ALL_PLAYERS || $this->type === self::NEAREST_PLAYER) && count($this->types) > 0){
			foreach($this->types as [$id, $negated]){
				if($negated || $id !== self::PLAYER_TYPE){
					throw new TargetSelectorException(KnownTranslationFactory::commands_generic_invalidPlayerType());
				}
			}
		}
	}

	/**
	 * Splits the text between the brackets at top-level commas (not inside quotes, braces or brackets).
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function splitArguments(string $arguments, string $text) : array{
		$parts = [];
		$depth = 0;
		$quoted = false;
		$current = "";
		for($i = 0, $len = strlen($arguments); $i < $len; ++$i){
			$ch = $arguments[$i];
			if($quoted){
				if($ch === "\\" && $i + 1 < $len){
					$current .= $ch . $arguments[++$i];
					continue;
				}
				if($ch === "\""){
					$quoted = false;
				}
			}elseif($ch === "\""){
				$quoted = true;
			}elseif($ch === "{" || $ch === "["){
				$depth++;
			}elseif($ch === "}" || $ch === "]"){
				$depth--;
			}elseif($ch === "," && $depth === 0){
				if(trim($current) === ""){
					throw self::syntaxError($text, "[" . $arguments . "]");
				}
				$parts[] = $current;
				$current = "";
				continue;
			}
			$current .= $ch;
		}
		if($quoted || $depth !== 0 || trim($current) === ""){
			throw self::syntaxError($text, "[" . $arguments . "]");
		}
		$parts[] = $current;
		return $parts;
	}

	private static function indexOfUnquoted(string $text, string $char) : ?int{
		$quoted = false;
		for($i = 0, $len = strlen($text); $i < $len; ++$i){
			if($text[$i] === "\""){
				$quoted = !$quoted;
			}elseif(!$quoted && $text[$i] === $char){
				return $i;
			}
		}
		return null;
	}

	private static function unquote(string $value) : string{
		if(strlen($value) >= 2 && $value[0] === "\"" && str_ends_with($value, "\"")){
			return str_replace(["\\\"", "\\\\"], ["\"", "\\"], substr($value, 1, -1));
		}
		return $value;
	}

	/**
	 * @param true[] $seen
	 * @phpstan-param array<string, true> $seen
	 */
	private function addArgument(string $key, string $value, array &$seen) : void{
		$negated = false;
		if(in_array($key, ["type", "name", "tag", "family", "m"], true) && str_starts_with($value, "!")){
			$negated = true;
			$value = trim(substr($value, 1));
		}
		$invalid = fn() : TargetSelectorException => new TargetSelectorException(KnownTranslationFactory::pocketmine_command_selector_invalidValue($value, $key));
		$invalidNumber = fn() : TargetSelectorException => new TargetSelectorException(KnownTranslationFactory::commands_generic_num_invalid($value));
		if(!$negated || $key === "x" || $key === "y" || $key === "z"){
			$repeatable = in_array($key, ["tag", "family"], true);
			if(!$repeatable && isset($seen[$key])){
				throw new TargetSelectorException(match($key){
					"type" => KnownTranslationFactory::commands_generic_duplicateType(),
					"name" => KnownTranslationFactory::commands_generic_tooManyNames(),
					default => KnownTranslationFactory::commands_generic_duplicateSelectorArgument($key)
				});
			}
			$seen[$key] = true;
		}
		switch($key){
			case "x":
				$this->x = self::parseCoordinate($value) ?? throw $invalidNumber();
				return;
			case "y":
				$this->y = self::parseCoordinate($value) ?? throw $invalidNumber();
				return;
			case "z":
				$this->z = self::parseCoordinate($value) ?? throw $invalidNumber();
				return;
			case "r":
			case "rm":
			case "dx":
			case "dy":
			case "dz":
			case "rx":
			case "rxm":
			case "ry":
			case "rym":
				if(!is_numeric($value)){
					throw $invalidNumber();
				}
				if(($key === "r" || $key === "rm") && (float) $value < 0){
					throw new TargetSelectorException(KnownTranslationFactory::commands_generic_radiusNegative());
				}
				$float = (float) $value;
				match($key){
					"r" => $this->r = $float,
					"rm" => $this->rm = $float,
					"dx" => $this->dx = $float,
					"dy" => $this->dy = $float,
					"dz" => $this->dz = $float,
					"rx" => $this->rx = $float,
					"rxm" => $this->rxm = $float,
					"ry" => $this->ry = $float,
					"rym" => $this->rym = $float,
				};
				return;
			case "c":
			case "l":
			case "lm":
				if(preg_match('/^[-+]?\d+$/', $value) !== 1){
					throw $invalidNumber();
				}
				if(($key === "c" && (int) $value === 0) || ($key !== "c" && (int) $value < 0)){
					throw $invalid();
				}
				$int = (int) $value;
				match($key){
					"c" => $this->c = $int,
					"l" => $this->l = $int,
					"lm" => $this->lm = $int,
				};
				return;
			case "type":
				$value = self::unquote($value);
				if($value === ""){
					throw $invalid();
				}
				$id = self::normalizeEntityType($value);
				if($id === null){
					throw new TargetSelectorException(KnownTranslationFactory::commands_generic_entity_invalidType($value));
				}
				$this->types[] = [$id, $negated];
				return;
			case "name":
				$this->names[] = [self::unquote($value), $negated];
				return;
			case "tag":
				$this->tags[] = [self::unquote($value), $negated];
				return;
			case "family":
				$family = strtolower(self::unquote($value));
				if($family === ""){
					throw $invalid();
				}
				$this->families[] = [$family, $negated];
				return;
			case "m":
				$mode = strtolower(self::unquote($value));
				if(in_array($mode, ["d", "default", "5"], true)){
					$gameMode = null;
				}else{
					$gameMode = $mode === "6" ? GameMode::SPECTATOR : (GameMode::fromString($mode) ?? throw $invalid());
				}
				$this->gameModes[] = [$gameMode, $negated];
				return;
			default:
				if(in_array($key, self::UNSUPPORTED_ARGUMENTS, true)){
					throw new TargetSelectorException(KnownTranslationFactory::pocketmine_command_selector_unsupportedArgument($key));
				}
				throw new TargetSelectorException(KnownTranslationFactory::commands_generic_parameter_invalid($key));
		}
	}

	/**
	 * @phpstan-return array{float, bool}|null
	 */
	private static function parseCoordinate(string $value) : ?array{
		if(str_starts_with($value, "~")){
			$rest = substr($value, 1);
			if($rest === ""){
				return [0.0, true];
			}
			return is_numeric($rest) ? [(float) $rest, true] : null;
		}
		return is_numeric($value) ? [(float) $value, false] : null;
	}

	/**
	 * Returns the namespaced entity type id ("cow" -> "minecraft:cow"), or null for an unknown vanilla type. Ids with
	 * another namespace (custom entities) are accepted as they are.
	 */
	public static function normalizeEntityType(string $type) : ?string{
		$type = strtolower($type);
		if(!str_contains($type, ":")){
			$type = "minecraft:" . $type;
		}
		if(!str_starts_with($type, "minecraft:")){
			return $type;
		}
		/** @phpstan-var array<string, true>|null $known */
		static $known = null;
		if($known === null){
			$known = [];
			foreach((new \ReflectionClass(EntityIds::class))->getConstants() as $id){
				if(is_string($id)){
					$known[$id] = true;
				}
			}
		}
		return isset($known[$type]) ? $type : null;
	}

	/** The selector text as it was given. */
	public function getText() : string{ return $this->text; }

	/** Selector type: one of the class constants (p, a, r, e, n, s, initiator). */
	public function getType() : string{ return $this->type; }

	/**
	 * Returns whether this selector can only select players (@p, @a, @s, @initiator, @r without a non-player type,
	 * @e[type=player], @n[type=player]). Commands taking player targets reject other selectors, like Bedrock.
	 */
	public function isPlayerOnly() : bool{
		$positiveType = null;
		foreach($this->types as [$id, $negated]){
			if(!$negated){
				$positiveType = $id;
			}
		}
		return match($this->type){
			self::ALL_ENTITIES, self::NEAREST_ENTITY => $positiveType === self::PLAYER_TYPE,
			self::RANDOM_PLAYER => $positiveType === null || $positiveType === self::PLAYER_TYPE,
			default => true
		};
	}

	/**
	 * Selects the matching entities as seen by the given command sender.
	 *
	 * @return Entity[]
	 * @phpstan-return list<Entity>
	 */
	public function selectEntities(CommandSender $sender) : array{
		return $this->select(SelectorContext::fromSender($sender));
	}

	/**
	 * Selects the matching players as seen by the given command sender (non-player matches are dropped).
	 *
	 * @return Player[]
	 * @phpstan-return list<Player>
	 */
	public function selectPlayers(CommandSender $sender) : array{
		$players = [];
		foreach($this->selectEntities($sender) as $entity){
			if($entity instanceof Player){
				$players[] = $entity;
			}
		}
		return $players;
	}

	/**
	 * @return Entity[]
	 * @phpstan-return list<Entity>
	 */
	public function select(SelectorContext $context) : array{
		$base = $context->getOrigin();
		$origin = new Vector3(
			self::resolveCoordinate($this->x, $base->x),
			self::resolveCoordinate($this->y, $base->y),
			self::resolveCoordinate($this->z, $base->z)
		);
		$positional = $this->x !== null || $this->y !== null || $this->z !== null || $this->r !== null || $this->rm !== null ||
			$this->dx !== null || $this->dy !== null || $this->dz !== null;
		$sortsByDistance = $this->type === self::NEAREST_PLAYER || $this->type === self::NEAREST_ENTITY || ($this->c !== null && $this->type !== self::RANDOM_PLAYER);
		$world = null;
		if($positional || $sortsByDistance){
			$world = $context->getWorld();
			if($world === null){
				return [];
			}
		}

		$executor = $context->getExecutor();
		$candidates = match($this->type){
			self::SELF => $executor !== null ? [$executor] : [],
			self::INITIATOR => $executor instanceof Player ? [$executor] : [],
			self::ALL_PLAYERS, self::NEAREST_PLAYER => $context->getPlayers(),
			self::RANDOM_PLAYER => $this->isPlayerOnly() ? $context->getPlayers() : $this->allEntities($context, $world),
			default => $this->allEntities($context, $world),
		};

		$box = null;
		if($this->dx !== null || $this->dy !== null || $this->dz !== null){
			$dx = $this->dx ?? 0.0;
			$dy = $this->dy ?? 0.0;
			$dz = $this->dz ?? 0.0;
			$box = new AxisAlignedBB(
				min($origin->x, $origin->x + $dx), min($origin->y, $origin->y + $dy), min($origin->z, $origin->z + $dz),
				max($origin->x, $origin->x + $dx) + 1, max($origin->y, $origin->y + $dy) + 1, max($origin->z, $origin->z + $dz) + 1
			);
		}

		$selected = [];
		foreach($candidates as $entity){
			if($entity->isClosed() || $entity->isFlaggedForDespawn()){
				continue;
			}
			if($this->type !== self::ALL_PLAYERS && $this->type !== self::SELF && !$entity->isAlive()){
				continue;
			}
			if(($this->type === self::NEAREST_PLAYER || $this->type === self::RANDOM_PLAYER) && $entity instanceof Player && $entity->isSpectator() && count($this->gameModes) === 0){
				continue;
			}
			if($world !== null && $entity->getWorld() !== $world){
				continue;
			}
			if($this->matches($entity, $origin, $box, $context)){
				$selected[] = $entity;
			}
		}

		if($this->type === self::RANDOM_PLAYER){
			shuffle($selected);
			return array_slice($selected, 0, abs($this->c ?? 1));
		}
		if($sortsByDistance){
			$farthest = $this->c !== null && $this->c < 0;
			usort($selected, function(Entity $a, Entity $b) use ($origin, $farthest) : int{
				$cmp = $a->getPosition()->distanceSquared($origin) <=> $b->getPosition()->distanceSquared($origin);
				return $farthest ? -$cmp : $cmp;
			});
			return array_slice($selected, 0, abs($this->c ?? 1));
		}
		return $selected;
	}

	/**
	 * @phpstan-param array{float, bool}|null $coordinate
	 */
	private static function resolveCoordinate(?array $coordinate, float $base) : float{
		if($coordinate === null){
			return $base;
		}
		return $coordinate[1] ? $base + $coordinate[0] : $coordinate[0];
	}

	/**
	 * @return Entity[]
	 * @phpstan-return list<Entity>
	 */
	private function allEntities(SelectorContext $context, ?World $world) : array{
		$entities = [];
		foreach($world !== null ? [$world] : $context->getWorlds() as $w){
			foreach($w->getEntities() as $entity){
				$entities[] = $entity;
			}
		}
		return $entities;
	}

	private function matches(Entity $entity, Vector3 $origin, ?AxisAlignedBB $box, SelectorContext $context) : bool{
		if($this->r !== null || $this->rm !== null){
			$distanceSquared = $entity->getPosition()->distanceSquared($origin);
			if($this->r !== null && $distanceSquared > $this->r * $this->r){
				return false;
			}
			if($this->rm !== null && $distanceSquared < $this->rm * $this->rm){
				return false;
			}
		}
		if($box !== null && !$entity->getBoundingBox()->intersectsWith($box)){
			return false;
		}
		if(count($this->types) > 0){
			$id = $entity::getNetworkTypeId();
			foreach($this->types as [$type, $negated]){
				if(($id === $type || $id === (self::TYPE_ALIASES[$type] ?? null)) === $negated){
					return false;
				}
			}
		}
		if(count($this->names) > 0){
			foreach($this->names as [$wanted, $negated]){
				$equal = $entity instanceof Player ? strtolower($entity->getName()) === strtolower($wanted) : $entity->getNameTag() === $wanted;
				if($equal === $negated){
					return false;
				}
			}
		}
		foreach($this->tags as [$tag, $negated]){
			$has = $tag === "" ? count($entity->getCommandTags()) === 0 : $entity->hasCommandTag($tag);
			if($has === $negated){
				return false;
			}
		}
		foreach($this->families as [$family, $negated]){
			if(self::isInFamily($entity, $family) === $negated){
				return false;
			}
		}
		if(count($this->gameModes) > 0){
			if(!$entity instanceof Player){
				return false;
			}
			foreach($this->gameModes as [$gameMode, $negated]){
				if(($entity->getGamemode() === ($gameMode ?? $context->getDefaultGameMode())) === $negated){
					return false;
				}
			}
		}
		if($this->l !== null || $this->lm !== null){
			if(!$entity instanceof Player){
				return false;
			}
			$level = $entity->getXpManager()->getXpLevel();
			if(($this->l !== null && $level > $this->l) || ($this->lm !== null && $level < $this->lm)){
				return false;
			}
		}
		if($this->rx !== null || $this->rxm !== null || $this->ry !== null || $this->rym !== null){
			$location = $entity->getLocation();
			if(($this->rx !== null && $location->pitch > $this->rx) || ($this->rxm !== null && $location->pitch < $this->rxm)){
				return false;
			}
			if($this->ry !== null || $this->rym !== null){
				$yaw = self::wrapDegrees($location->yaw);
				$max = $this->ry !== null ? self::wrapDegrees($this->ry) : 180.0;
				$min = $this->rym !== null ? self::wrapDegrees($this->rym) : -180.0;
				if($min <= $max ? ($yaw < $min || $yaw > $max) : ($yaw < $min && $yaw > $max)){
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Wraps an angle into [-180, 180).
	 */
	private static function wrapDegrees(float $degrees) : float{
		$degrees = fmod($degrees + 180.0, 360.0);
		if($degrees < 0){
			$degrees += 360.0;
		}
		return $degrees - 180.0;
	}

	/**
	 * Bedrock entity families supported by family=: player, mob (any living entity but players), monster, undead,
	 * animal, illager, inanimate (non-living), and the entity's own type name (family=zombie matches zombies).
	 */
	public static function isInFamily(Entity $entity, string $family) : bool{
		return match($family){
			"player" => $entity instanceof Player,
			"mob" => $entity instanceof Living && !$entity instanceof Player,
			"monster" => $entity instanceof Monster,
			"undead" => Wither::isUndead($entity),
			"animal" => $entity instanceof Animal,
			"illager" => $entity instanceof Illager,
			"inanimate" => !$entity instanceof Living,
			default => $entity::getNetworkTypeId() === "minecraft:" . $family
		};
	}

	/**
	 * Name of an entity as shown in command feedback: the player name, the name tag, or the entity type.
	 */
	public static function getEntityName(Entity $entity) : string{
		if($entity instanceof Player){
			return $entity->getName();
		}
		if($entity->getNameTag() !== ""){
			return $entity->getNameTag();
		}
		if($entity instanceof Living){
			return $entity->getName();
		}
		$id = $entity::getNetworkTypeId();
		return str_starts_with($id, "minecraft:") ? substr($id, 10) : $id;
	}

	/**
	 * Names of the given entities joined like Bedrock command feedback ("Steve, Alex, Cow").
	 *
	 * @param Entity[] $entities
	 */
	public static function joinNames(array $entities) : string{
		$names = [];
		foreach($entities as $entity){
			$names[] = self::getEntityName($entity);
		}
		return implode(", ", $names);
	}
}
