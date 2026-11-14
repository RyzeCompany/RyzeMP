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

namespace pocketmine\command\parameter;

/**
 * Implemented by commands that describe their syntax to clients. Bedrock clients use the overloads for autocompletion
 * (e.g. target selector suggestions) and syntax hints. Commands without overloads are sent as taking free text.
 *
 * The client also checks typed commands against the overloads before sending them, so the server always adds a
 * free-text overload after these: commands keep accepting every syntax their execute() understands (unless the command
 * implements {@link ExhaustiveOverloads}).
 *
 * Plugin commands without this interface keep being sent with only the free-text overload. To describe a plugin
 * command, extend {@link \pocketmine\command\Command} and implement this interface, e.g.:
 *
 * ```php
 * final class HealCommand extends Command implements ParameterizedCommand{
 *     public function getOverloads() : array{
 *         return [
 *             [CommandParameter::target("player", optional: true)],
 *             [CommandParameter::enum("mode", "HealMode", ["full", "half"]), CommandParameter::target("player", optional: true)],
 *         ];
 *     }
 *     // execute()...
 * }
 * ```
 *
 * Enums are shared by name between all commands; reuse the names in {@link CommandEnums} (e.g.
 * {@link CommandParameter::item()}) for vanilla kinds of values and prefix other names with the command name. The
 * overloads are read whenever the command list is sent to a player (on join and when their permissions change).
 */
interface ParameterizedCommand{

	/**
	 * @return CommandParameter[][]
	 * @phpstan-return list<list<CommandParameter>>
	 */
	public function getOverloads() : array;
}
