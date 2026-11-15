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

namespace pocketmine\utils;

use function array_keys;
use function str_replace;
use function strtolower;
use function trim;

/**
 * Handles parsing any Minecraft thing from strings. This can be used, for example, to implement a user-friendly item
 * parser to be used by the /give command (and others).
 * Custom aliases may be registered.
 * Note that the aliases should be user-friendly, i.e. easily readable and writable.
 *
 * @phpstan-template T
 */
abstract class StringToTParser{

	/**
	 * @var \Closure[]
	 * @phpstan-var array<string, \Closure(string $input) : T>
	 */
	private array $callbackMap = [];

	/**
	 * Built-in aliases that a later register() or registerAlias() of the same name leaves in place instead of failing.
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	private array $keepBuiltIn = [];

	/** @phpstan-param \Closure(string $input) : T $callback */
	public function register(string $alias, \Closure $callback) : void{
		$key = $this->reprocess($alias);
		if(isset($this->callbackMap[$key])){
			if(isset($this->keepBuiltIn[$key])){
				$this->ignoreBuiltInRedefinition($key);
				return;
			}
			throw new \InvalidArgumentException("Alias \"$key\" is already registered");
		}
		$this->callbackMap[$key] = $callback;
	}

	/** @phpstan-param \Closure(string $input) : T $callback */
	public function override(string $alias, \Closure $callback) : void{
		$this->callbackMap[$this->reprocess($alias)] = $callback;
	}

	/**
	 * Keeps these existing aliases when something registers them again, instead of throwing. The server uses this for
	 * the aliases VanillaMine added after PocketMine-MP 5.44.3: a plugin written for 5.44.3 that implemented one of
	 * those things itself (e.g. filled maps) keeps loading, and the built-in one stays in use.
	 * @internal
	 */
	public function keepBuiltIn(string ...$aliases) : void{
		foreach($aliases as $alias){
			$key = $this->reprocess($alias);
			if(isset($this->callbackMap[$key])){
				$this->keepBuiltIn[$key] = true;
			}
		}
	}

	private function ignoreBuiltInRedefinition(string $key) : void{
		\GlobalLogger::get()->warning("A plugin tried to register \"$key\", which this server already provides: the built-in one is kept");
	}

	/**
	 * Registers a new alias for an existing known alias.
	 */
	public function registerAlias(string $existing, string $alias) : void{
		$existingKey = $this->reprocess($existing);
		if(!isset($this->callbackMap[$existingKey])){
			throw new \InvalidArgumentException("Cannot register new alias for unknown existing alias \"$existing\"");
		}
		$newKey = $this->reprocess($alias);
		if(isset($this->callbackMap[$newKey])){
			if(isset($this->keepBuiltIn[$newKey])){
				$this->ignoreBuiltInRedefinition($newKey);
				return;
			}
			throw new \InvalidArgumentException("Alias \"$newKey\" is already registered");
		}
		$this->callbackMap[$newKey] = $this->callbackMap[$existingKey];
	}

	/**
	 * Tries to parse the specified string into a corresponding instance of T.
	 * @phpstan-return T|null
	 */
	public function parse(string $input){
		$key = $this->reprocess($input);
		if(isset($this->callbackMap[$key])){
			return ($this->callbackMap[$key])($input);
		}

		return null;
	}

	protected function reprocess(string $input) : string{
		return strtolower(str_replace([" ", "minecraft:"], ["_", ""], trim($input)));
	}

	/** @return string[]|int[] */
	public function getKnownAliases() : array{
		return array_keys($this->callbackMap);
	}
}
