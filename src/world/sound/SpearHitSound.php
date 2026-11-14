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

namespace pocketmine\world\sound;

use pocketmine\item\ToolTier;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

/**
 * Played when a spear charge attack hits an entity.
 */
final class SpearHitSound implements Sound{

	public function __construct(private ToolTier $tier){}

	public function encode(Vector3 $pos) : array{
		$sound = match($this->tier){
			ToolTier::WOOD => LevelSoundEvent::ITEM_WOODEN_SPEAR_ATTACK_HIT,
			ToolTier::STONE => LevelSoundEvent::ITEM_STONE_SPEAR_ATTACK_HIT,
			ToolTier::COPPER => LevelSoundEvent::ITEM_COPPER_SPEAR_ATTACK_HIT,
			ToolTier::IRON => LevelSoundEvent::ITEM_IRON_SPEAR_ATTACK_HIT,
			ToolTier::GOLD => LevelSoundEvent::ITEM_GOLDEN_SPEAR_ATTACK_HIT,
			ToolTier::DIAMOND => LevelSoundEvent::ITEM_DIAMOND_SPEAR_ATTACK_HIT,
			ToolTier::NETHERITE => LevelSoundEvent::ITEM_NETHERITE_SPEAR_ATTACK_HIT,
		};
		return [LevelSoundEventPacket::nonActorSound($sound, $pos, false)];
	}
}
