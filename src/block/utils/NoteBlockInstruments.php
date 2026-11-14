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

use pocketmine\block\Block;
use pocketmine\world\sound\NoteInstrument;

/**
 * Note block instruments like BDS 1.26.52: the block below picks the instrument (table captured from BDS, copper
 * trumpets by oxidation), and a mob head on top replaces it with that mob's sound at a fixed note.
 *
 * @internal
 */
final class NoteBlockInstruments{

	/** Note BDS sends with the mob head sounds, whatever the note block is tuned to */
	public const HEAD_NOTE = 9;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the instrument a note block plays over the given block, or null for block types missing from the BDS
	 * capture (custom blocks...).
	 */
	public static function fromBlockBelow(Block $block) : ?NoteInstrument{
		$instrument = NoteBlockInstrumentTable::BY_TYPE_ID[$block->getTypeId()] ?? null;
		if($instrument === NoteInstrument::TRUMPET && $block instanceof CopperMaterial){
			return match($block->getOxidation()){
				CopperOxidation::NONE => NoteInstrument::TRUMPET,
				CopperOxidation::EXPOSED => NoteInstrument::TRUMPET_EXPOSED,
				CopperOxidation::WEATHERED => NoteInstrument::TRUMPET_WEATHERED,
				CopperOxidation::OXIDIZED => NoteInstrument::TRUMPET_OXIDIZED,
			};
		}
		return $instrument;
	}

	/**
	 * Returns the sound a mob head on top of a note block plays, or null if the head doesn't play (player heads cover
	 * the note block like any other block).
	 */
	public static function fromHead(MobHeadType $type) : ?NoteInstrument{
		return match($type){
			MobHeadType::ZOMBIE => NoteInstrument::ZOMBIE,
			MobHeadType::SKELETON => NoteInstrument::SKELETON,
			MobHeadType::CREEPER => NoteInstrument::CREEPER,
			MobHeadType::DRAGON => NoteInstrument::ENDER_DRAGON,
			MobHeadType::WITHER_SKELETON => NoteInstrument::WITHER_SKELETON,
			MobHeadType::PIGLIN => NoteInstrument::PIGLIN,
			MobHeadType::PLAYER => null,
		};
	}
}
