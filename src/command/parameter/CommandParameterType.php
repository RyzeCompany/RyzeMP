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
 * Kinds of command parameters advertised to clients (for autocompletion and client-side syntax checks). Independent of
 * the network protocol; the network layer maps them to Bedrock argument types.
 */
enum CommandParameterType{
	/** A target selector or a player name (the client suggests @a, @e, @n, @p, @r, @s and online players). */
	case TARGET;
	case INT;
	case FLOAT;
	/** A number that may be relative (~ or ~5), like a single coordinate. */
	case VALUE;
	/** Three coordinates (x y z), each absolute or relative. */
	case POSITION;
	/** A single word. */
	case STRING;
	/** The rest of the line as a chat message (selectors in it are expanded). */
	case MESSAGE;
	/** The rest of the line, as it is. */
	case RAWTEXT;
	case JSON;
	/** Bedrock block states in brackets, e.g. ["facing_direction"=2] (see {@link \pocketmine\command\utils\CommandBlockParser}). */
	case BLOCK_STATES;
	/** One of a fixed list of keywords (see {@link CommandParameter::enum()}). */
	case ENUM;
	/** A word with suggested values; other values are accepted too (see {@link CommandParameter::softEnum()}). */
	case SOFT_ENUM;
	/** An integer followed by a fixed suffix, like Bedrock's "/xp 5L" (see {@link CommandParameter::postfixed()}). */
	case POSTFIX;
	/**
	 * The name of a command (or alias) the receiving player may use (see {@link CommandParameter::commandName()}).
	 * The values are filled in per player when the command list is sent.
	 */
	case COMMAND_NAME;
}
