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

namespace pocketmine\command\utils;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_keys;
use function count;
use function implode;
use function preg_match;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;
use function trim;
use const PREG_UNMATCHED_AS_NULL;
use const SORT_STRING;

/**
 * Parses block arguments of commands like Bedrock: a block name with or without "minecraft:" (and old names the block
 * upgrader knows, e.g. wooden_door), optionally followed by block states in brackets:
 * `oak_stairs ["weirdo_direction"=1, "upside_down_bit"=true]`. States that are not given keep the block's default
 * value. Values are true/false, integers or "strings".
 *
 * Names and states are resolved through the current block state data, so every block the server knows can be named,
 * including blocks newer than the legacy ID tables.
 */
final class CommandBlockParser{
	/**
	 * Default state of each block name, built when first needed.
	 * @var BlockStateData[]|null
	 * @phpstan-var array<string, BlockStateData>|null
	 */
	private static ?array $defaults = null;

	private function __construct(){
		//NOOP
	}

	/**
	 * Parses the block at $args[$offset] and its states, if the next argument starts with "[" (states with spaces in
	 * them span several arguments). $offset is moved past what was read.
	 *
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 *
	 * @throws InvalidCommandSyntaxException if there is no block argument
	 * @throws CommandFailureException if the block or a state is unknown
	 */
	public static function parse(array $args, int &$offset) : ParsedBlock{
		if(!isset($args[$offset])){
			throw new InvalidCommandSyntaxException();
		}
		$name = $args[$offset++];
		$statesText = null;
		$bracket = strpos($name, "[");
		if($bracket !== false){
			//"stone[...]" written without a space
			$statesText = substr($name, $bracket);
			$name = substr($name, 0, $bracket);
		}elseif(isset($args[$offset]) && str_starts_with($args[$offset], "[")){
			$statesText = $args[$offset++];
		}
		if($statesText !== null){
			while(substr_count($statesText, "[") > substr_count($statesText, "]") && isset($args[$offset])){
				$statesText .= " " . $args[$offset++];
			}
		}
		return self::resolve($name, $statesText !== null ? self::parseStates($statesText) : []);
	}

	/**
	 * Parses a block states argument: `["a"=1,"b"=true,"c"="x"]` (also with ":" instead of "=", and unquoted names or
	 * string values).
	 *
	 * @return Tag[]
	 * @phpstan-return array<string, Tag>
	 * @throws CommandFailureException
	 */
	public static function parseStates(string $text) : array{
		$text = trim($text);
		if(!str_starts_with($text, "[") || !str_ends_with($text, "]")){
			throw new CommandFailureException(KnownTranslationFactory::commands_blockstate_invalidState($text));
		}
		$inner = trim(substr($text, 1, -1));
		if($inner === ""){
			return [];
		}
		$states = [];
		foreach(self::splitStates($inner) as $part){
			if(preg_match('/^\s*(?:"([^"]*)"|([\w:.\-]+))\s*[=:]\s*(?:"([^"]*)"|([^\s",]+))\s*$/', $part, $m, PREG_UNMATCHED_AS_NULL) !== 1){
				throw new CommandFailureException(KnownTranslationFactory::commands_blockstate_invalidState(trim($part)));
			}
			$key = $m[1] ?? $m[2] ?? "";
			if($m[4] === null){
				$states[$key] = new StringTag($m[3] ?? "");
				continue;
			}
			$value = $m[4];
			$lower = strtolower($value);
			if($lower === "true" || $lower === "false"){
				$states[$key] = new ByteTag($lower === "true" ? 1 : 0);
			}elseif(preg_match('/^[-+]?\d+$/', $value) === 1){
				$states[$key] = new IntTag((int) $value);
			}else{
				$states[$key] = new StringTag($value);
			}
		}
		return $states;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private static function splitStates(string $inner) : array{
		$parts = [];
		$current = "";
		$quoted = false;
		for($i = 0, $len = strlen($inner); $i < $len; ++$i){
			$ch = $inner[$i];
			if($ch === "\""){
				$quoted = !$quoted;
			}elseif($ch === "," && !$quoted){
				$parts[] = $current;
				$current = "";
				continue;
			}
			$current .= $ch;
		}
		$parts[] = $current;
		return $parts;
	}

	/**
	 * Resolves a block name and the given states (the others keep their default values).
	 *
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 * @throws CommandFailureException
	 */
	public static function resolve(string $name, array $states = []) : ParsedBlock{
		$id = strtolower(trim($name));
		if($id !== "" && !str_contains($id, ":")){
			$id = "minecraft:" . $id;
		}
		$default = $id !== "" ? self::getDefaultState($id) : null;
		if($default === null){
			throw new CommandFailureException(KnownTranslationFactory::commands_setblock_notFound($name));
		}
		$merged = $default->getStates();
		foreach(Utils::stringifyKeys($states) as $stateName => $value){
			$current = $merged[$stateName] ?? null;
			if($current === null){
				throw new CommandFailureException(KnownTranslationFactory::commands_blockstate_stateError($stateName, $name));
			}
			//1/0 for booleans and true/false for integer states
			if($current instanceof ByteTag && $value instanceof IntTag && ($value->getValue() === 0 || $value->getValue() === 1)){
				$value = new ByteTag($value->getValue());
			}elseif($current instanceof IntTag && $value instanceof ByteTag){
				$value = new IntTag($value->getValue());
			}
			if($current::class !== $value::class){
				throw new CommandFailureException(KnownTranslationFactory::commands_blockstate_typeError($stateName));
			}
			$merged[$stateName] = $value;
			$states[$stateName] = $value;
		}
		try{
			$stateId = GlobalBlockStateHandlers::getDeserializer()->deserialize(BlockStateData::current($default->getName(), $merged));
			$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
		}catch(\Throwable){
			throw count($states) > 0 ?
				new CommandFailureException(KnownTranslationFactory::commands_blockstate_valueError(implode(", ", array_keys($states)))) :
				new CommandFailureException(KnownTranslationFactory::commands_setblock_notFound($name));
		}
		return new ParsedBlock($block, $default->getName(), $states);
	}

	/**
	 * Default state of a block name ("minecraft:..."): the state of the vanilla block's default, or the first known
	 * state with that name (e.g. other colours of a block family), or what the block upgrader makes of an old name.
	 */
	public static function getDefaultState(string $id) : ?BlockStateData{
		self::$defaults ??= self::buildDefaults();
		if(isset(self::$defaults[$id])){
			return self::$defaults[$id];
		}
		try{
			$upgraded = GlobalBlockStateHandlers::getUpgrader()->upgradeStringIdMeta($id, 0);
		}catch(\Throwable){
			return null;
		}
		return self::$defaults[$upgraded->getName()] ?? null;
	}

	/**
	 * Names of the blocks the parser knows, without "minecraft:" for vanilla blocks, sorted.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function getBlockNames() : array{
		self::$defaults ??= self::buildDefaults();
		$names = [];
		foreach(Utils::stringifyKeys(self::$defaults) as $name => $_){
			$names[] = str_starts_with($name, "minecraft:") ? substr($name, 10) : $name;
		}
		sort($names, SORT_STRING);
		return $names;
	}

	/**
	 * @return BlockStateData[]
	 * @phpstan-return array<string, BlockStateData>
	 */
	private static function buildDefaults() : array{
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$defaults = [];
		$states = [];
		foreach(VanillaBlocks::getAll() as $block){
			$states[] = $block->getStateId();
		}
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $stateId => $_){
			$states[] = $stateId;
		}
		foreach($states as $stateId){
			try{
				$data = $serializer->serialize($stateId);
			}catch(\Throwable){
				continue;
			}
			$defaults[$data->getName()] ??= $data;
		}
		return $defaults;
	}

	/**
	 * Whether the block has the given name and states (only the states given; the others may have any value), like
	 * /execute if block and /fill ... replace.
	 *
	 * @param Tag[] $states
	 * @phpstan-param array<string, Tag> $states
	 */
	public static function matches(Block $block, string $name, array $states) : bool{
		try{
			$data = GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId());
		}catch(\Throwable){
			return false;
		}
		if($data->getName() !== $name){
			return false;
		}
		foreach(Utils::stringifyKeys($states) as $stateName => $value){
			$actual = $data->getState($stateName);
			if($actual === null || !$actual->equals($value)){
				return false;
			}
		}
		return true;
	}
}
