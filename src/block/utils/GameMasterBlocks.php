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

namespace pocketmine\block\utils;

use pocketmine\block\Allow;
use pocketmine\block\Block;
use pocketmine\block\BorderBlock;
use pocketmine\block\CommandBlock;
use pocketmine\block\Deny;
use pocketmine\block\Jigsaw;
use pocketmine\block\StructureBlock;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;

/**
 * Structure blocks and jigsaw blocks are "game master" blocks: only operators in creative mode may place them, open
 * their screens or edit them, like vanilla.
 */
final class GameMasterBlocks{

	private function __construct(){
		//NOOP
	}

	public static function canUse(Player $player) : bool{
		return $player->isCreative(true) && $player->hasPermission(DefaultPermissions::ROOT_OPERATOR);
	}

	/**
	 * Whether only players who {@link self::canUse()} game master blocks may break the block: command, structure and
	 * jigsaw blocks (vanilla game master blocks), and the allow, deny and border blocks that share their placement rule.
	 */
	public static function isGameMasterBlock(Block $block) : bool{
		return $block instanceof CommandBlock ||
			$block instanceof StructureBlock ||
			$block instanceof Jigsaw ||
			$block instanceof Allow ||
			$block instanceof Deny ||
			$block instanceof BorderBlock;
	}
}
