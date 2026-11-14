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

use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

/**
 * Sound of an eyeblossom opening or closing: the long one for the flower that changed on its own, the short one for
 * the eyeblossoms around that follow it.
 */
class EyeblossomSwitchSound implements Sound{

	public function __construct(
		private Block $block,
		private bool $open,
		private bool $long
	){}

	public function encode(Vector3 $pos) : array{
		return [LevelSoundEventPacket::nonActorSound(
			$this->open ? ($this->long ? LevelSoundEvent::OPEN_LONG : LevelSoundEvent::OPEN) : ($this->long ? LevelSoundEvent::CLOSE_LONG : LevelSoundEvent::CLOSE),
			$pos,
			false,
			TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->block->getStateId())
		)];
	}
}
