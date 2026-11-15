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
 * Describes one parameter of a command overload, for the commands list sent to clients. See
 * {@link ParameterizedCommand}.
 */
final class CommandParameter{

	/**
	 * @param string[] $enumValues
	 * @phpstan-param list<string> $enumValues
	 */
	private function __construct(
		private string $name,
		private CommandParameterType $type,
		private bool $optional,
		private ?string $enumName = null,
		private array $enumValues = [],
		private ?string $postfix = null
	){}

	public static function target(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::TARGET, $optional);
	}

	public static function int(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::INT, $optional);
	}

	public static function float(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::FLOAT, $optional);
	}

	public static function value(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::VALUE, $optional);
	}

	public static function position(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::POSITION, $optional);
	}

	public static function string(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::STRING, $optional);
	}

	public static function message(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::MESSAGE, $optional);
	}

	public static function rawText(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::RAWTEXT, $optional);
	}

	public static function json(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::JSON, $optional);
	}

	/** Block states in brackets, following a {@link block()} parameter. */
	public static function blockStates(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::BLOCK_STATES, $optional);
	}

	/**
	 * A keyword parameter. Enums are shared by name between all commands, so the same name must always have the same
	 * values (prefix it with the command name, e.g. "TitleAction").
	 *
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 */
	public static function enum(string $name, string $enumName, array $values, bool $optional = false) : self{
		return new self($name, CommandParameterType::ENUM, $optional, $enumName, $values);
	}

	/**
	 * A word for which the client suggests the given values without rejecting others (Bedrock soft enum), e.g. names
	 * of structures or biomes. Like enums, soft enums are shared by name between all commands.
	 *
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 */
	public static function softEnum(string $name, string $enumName, array $values, bool $optional = false) : self{
		return new self($name, CommandParameterType::SOFT_ENUM, $optional, $enumName, $values);
	}

	/**
	 * An integer directly followed by a fixed suffix, e.g. postfixed("amount", "L") for Bedrock's "/xp 5L".
	 */
	public static function postfixed(string $name, string $postfix, bool $optional = false) : self{
		return new self($name, CommandParameterType::POSTFIX, $optional, postfix: $postfix);
	}

	/**
	 * The name of a command, suggesting the commands (and aliases) the receiving player is allowed to use, like the
	 * argument of /help.
	 */
	public static function commandName(string $name, bool $optional = false) : self{
		return new self($name, CommandParameterType::COMMAND_NAME, $optional, CommandEnums::COMMAND_NAME);
	}

	/** true or false (Bedrock's "Boolean" enum). */
	public static function boolean(string $name, bool $optional = false) : self{
		return self::enum($name, CommandEnums::BOOLEAN, ["true", "false"], $optional);
	}

	/**
	 * An item name, suggesting the vanilla item IDs the server supports (Bedrock's "Item" enum). Commands should parse
	 * it with {@link \pocketmine\command\utils\CommandItemParser}, which accepts every suggested value.
	 */
	public static function item(string $name, bool $optional = false) : self{
		return self::enum($name, CommandEnums::ITEM, CommandEnums::items(), $optional);
	}

	/**
	 * A block name, suggesting the blocks the server supports (Bedrock's "Block" enum). Commands should parse it with
	 * {@link \pocketmine\command\utils\CommandBlockParser}.
	 */
	public static function block(string $name, bool $optional = false) : self{
		return self::enum($name, CommandEnums::BLOCK, CommandEnums::blocks(), $optional);
	}

	/** An effect name (Bedrock's "Effect" enum). */
	public static function effect(string $name, bool $optional = false) : self{
		return self::enum($name, CommandEnums::EFFECT, CommandEnums::effects(), $optional);
	}

	/** An enchantment name (Bedrock's "Enchant" enum). */
	public static function enchantment(string $name, bool $optional = false) : self{
		return self::enum($name, CommandEnums::ENCHANT, CommandEnums::enchantments(), $optional);
	}

	/** A game mode name or letter (Bedrock's "GameMode" enum); numeric game modes need an int parameter. */
	public static function gameMode(string $name, bool $optional = false) : self{
		return self::enum($name, CommandEnums::GAME_MODE, CommandEnums::gameModes(), $optional);
	}

	public function getName() : string{ return $this->name; }

	public function getType() : CommandParameterType{ return $this->type; }

	public function isOptional() : bool{ return $this->optional; }

	/** Enum name, for {@link CommandParameterType::ENUM} and {@link CommandParameterType::SOFT_ENUM} parameters. */
	public function getEnumName() : ?string{ return $this->enumName; }

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getEnumValues() : array{ return $this->enumValues; }

	/** Suffix of {@link CommandParameterType::POSTFIX} parameters. */
	public function getPostfix() : ?string{ return $this->postfix; }
}
