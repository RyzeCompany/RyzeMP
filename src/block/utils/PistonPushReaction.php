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

use pocketmine\block\AmethystCluster;
use pocketmine\block\Bamboo;
use pocketmine\block\BaseBanner;
use pocketmine\block\BaseCake;
use pocketmine\block\BaseCoral;
use pocketmine\block\BaseSign;
use pocketmine\block\Bed;
use pocketmine\block\Block;
use pocketmine\block\BlockTypeIds as Ids;
use pocketmine\block\Cactus;
use pocketmine\block\Campfire;
use pocketmine\block\CopperGolemStatue;
use pocketmine\block\Door;
use pocketmine\block\DragonEgg;
use pocketmine\block\Flowable;
use pocketmine\block\GlowLichen;
use pocketmine\block\Ladder;
use pocketmine\block\Leaves;
use pocketmine\block\Liquid;
use pocketmine\block\PointedDripstone;
use pocketmine\block\SeaPickle;
use pocketmine\block\ShulkerBox;

/**
 * How a block reacts to being moved by a piston (Bedrock Edition rules).
 */
enum PistonPushReaction{
	/** Pushed and pulled normally. */
	case NORMAL;
	/** Pushed, but never pulled (by a sticky piston or by slime/honey dragging it along). */
	case PUSH_ONLY;
	/** Destroyed (dropping its items) when pushed; not pulled. */
	case BREAK;
	/** Stops the piston: nothing in the line moves. */
	case BLOCK;

	/**
	 * Default reaction of a block, used by {@link Block::getPistonPushReaction()}.
	 *
	 * Sources: Minecraft Wiki (Piston, Leaves, Shulker Box pages) and PowerNukkitX's Bedrock block definitions
	 * (canBePushed/canBePulled/breaksWhenMoved). Bedrock moves most blocks with a block entity (chests, furnaces,
	 * hoppers...) together with their data, unlike Java.
	 */
	public static function defaultFor(Block $block) : self{
		$typeId = $block->getTypeId();
		switch($typeId){
			case Ids::BARRIER:
			case Ids::BEACON:
			case Ids::BEDROCK:
			case Ids::INVISIBLE_BEDROCK:
			case Ids::OBSIDIAN:
			case Ids::CRYING_OBSIDIAN:
			case Ids::GLOWING_OBSIDIAN:
			case Ids::REINFORCED_DEEPSLATE:
			case Ids::RESPAWN_ANCHOR:
			case Ids::END_PORTAL_FRAME:
			case Ids::NETHER_PORTAL:
			case Ids::ENCHANTING_TABLE:
			case Ids::ENDER_CHEST:
			case Ids::JUKEBOX:
			case Ids::LODESTONE:
			case Ids::GRINDSTONE:
			case Ids::MONSTER_SPAWNER:
			case Ids::LIGHT:
			case Ids::COMPOUND_CREATOR:
			case Ids::ELEMENT_CONSTRUCTOR:
			case Ids::LAB_TABLE:
			case Ids::MATERIAL_REDUCER:
			case Ids::HARDENED_GLASS:
			case Ids::HARDENED_GLASS_PANE:
				return self::BLOCK;

			case Ids::FLOWER_POT:
			case Ids::DECORATED_POT:
			case Ids::GLAZED_TERRACOTTA:
				return self::PUSH_ONLY;

			case Ids::END_ROD:
				return self::NORMAL;

			case Ids::BUDDING_AMETHYST:
			case Ids::MELON:
			case Ids::PUMPKIN:
			case Ids::COBWEB:
			case Ids::STRUCTURE_VOID:
				return self::BREAK;
		}

		if(
			$block instanceof Flowable ||
			$block instanceof Liquid ||
			$block->canBeReplaced() ||
			$block instanceof Door ||
			$block instanceof Bed ||
			$block instanceof BaseSign ||
			$block instanceof BaseBanner ||
			$block instanceof Leaves ||
			$block instanceof Cactus ||
			$block instanceof BaseCake ||
			$block instanceof Bamboo ||
			$block instanceof Ladder ||
			$block instanceof GlowLichen ||
			$block instanceof DragonEgg ||
			$block instanceof ShulkerBox ||
			$block instanceof Campfire ||
			$block instanceof PointedDripstone ||
			$block instanceof AmethystCluster ||
			$block instanceof SeaPickle ||
			$block instanceof BaseCoral ||
			$block instanceof CopperGolemStatue
		){
			return self::BREAK;
		}

		return self::NORMAL;
	}
}
