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

namespace pocketmine\crafting;

use function count;

/**
 * How a recipe appears in the client's recipe book: its priority (lower values are preferred when several recipes
 * make the same item) and what unlocks it for a player (see {@link \pocketmine\player\RecipeBook}).
 *
 * Recipes without this data (e.g. registered by plugins) are always unlocked.
 */
final class RecipeUnlocking{
	/** Unlocked when the player obtains any of the unlocking ingredients (never if there are none). */
	public const CONTEXT_NONE = 0;
	public const CONTEXT_ALWAYS_UNLOCKED = 1;
	public const CONTEXT_PLAYER_IN_WATER = 2;
	/** Unlocked when the player's inventory has items in at least {@link self::MANY_ITEMS_SLOTS} slots. */
	public const CONTEXT_PLAYER_HAS_MANY_ITEMS = 3;

	/** BDS 1.26.52 unlocks the chest recipe when the 10th inventory slot gets an item (640 dirt or 10 different items). */
	public const MANY_ITEMS_SLOTS = 10;

	/** Priority the client gets for recipes without unlocking data. */
	public const DEFAULT_PRIORITY = 50;

	/**
	 * @param RecipeIngredient[] $unlockingIngredients only used with {@link self::CONTEXT_NONE}
	 * @phpstan-param list<RecipeIngredient> $unlockingIngredients
	 */
	public function __construct(
		private int $priority,
		private int $context,
		private array $unlockingIngredients = []
	){
		if($context < self::CONTEXT_NONE || $context > self::CONTEXT_PLAYER_HAS_MANY_ITEMS){
			throw new \InvalidArgumentException("Unknown recipe unlocking context $context");
		}
		if($context !== self::CONTEXT_NONE && count($unlockingIngredients) !== 0){
			throw new \InvalidArgumentException("Unlocking ingredients can only be used with CONTEXT_NONE");
		}
	}

	public function getPriority() : int{ return $this->priority; }

	public function getContext() : int{ return $this->context; }

	/**
	 * @return RecipeIngredient[]
	 * @phpstan-return list<RecipeIngredient>
	 */
	public function getUnlockingIngredients() : array{ return $this->unlockingIngredients; }
}
