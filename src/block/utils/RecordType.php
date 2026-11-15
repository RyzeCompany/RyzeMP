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

use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Translatable;
use pocketmine\utils\LegacyEnumShimTrait;
use function spl_object_id;

/**
 * TODO: These tags need to be removed once we get rid of LegacyEnumShimTrait (PM6)
 *  These are retained for backwards compatibility only.
 *
 * @method static RecordType DISK_11()
 * @method static RecordType DISK_13()
 * @method static RecordType DISK_5()
 * @method static RecordType DISK_BLOCKS()
 * @method static RecordType DISK_CAT()
 * @method static RecordType DISK_CHIRP()
 * @method static RecordType DISK_FAR()
 * @method static RecordType DISK_MALL()
 * @method static RecordType DISK_MELLOHI()
 * @method static RecordType DISK_OTHERSIDE()
 * @method static RecordType DISK_PIGSTEP()
 * @method static RecordType DISK_STAL()
 * @method static RecordType DISK_STRAD()
 * @method static RecordType DISK_WAIT()
 * @method static RecordType DISK_WARD()
 *
 * @phpstan-type TMetadata array{0: string, 1: Translatable}
 */
enum RecordType{
	use LegacyEnumShimTrait;

	case DISK_13;
	case DISK_5;
	case DISK_CAT;
	case DISK_BLOCKS;
	case DISK_BOUNCE;
	case DISK_CHIRP;
	case DISK_CREATOR;
	case DISK_CREATOR_MUSIC_BOX;
	case DISK_FAR;
	case DISK_LAVA_CHICKEN;
	case DISK_MALL;
	case DISK_MELLOHI;
	case DISK_OTHERSIDE;
	case DISK_PIGSTEP;
	case DISK_PRECIPICE;
	case DISK_RELIC;
	case DISK_STAL;
	case DISK_STRAD;
	case DISK_TEARS;
	case DISK_WARD;
	case DISK_11;
	case DISK_WAIT;

	/**
	 * @phpstan-return TMetadata
	 */
	private function getMetadata() : array{
		/** @phpstan-var array<int, TMetadata> $cache */
		static $cache = [];

		return $cache[spl_object_id($this)] ??= match($this){
			self::DISK_13 => ["C418 - 13", KnownTranslationFactory::item_record_13_desc()],
			self::DISK_5 => ["Samuel Åberg - 5", KnownTranslationFactory::item_record_5_desc()],
			self::DISK_CAT => ["C418 - cat", KnownTranslationFactory::item_record_cat_desc()],
			self::DISK_BLOCKS => ["C418 - blocks", KnownTranslationFactory::item_record_blocks_desc()],
			self::DISK_BOUNCE => ["fingerspit - Bounce", KnownTranslationFactory::item_record_bounce_desc()],
			self::DISK_CHIRP => ["C418 - chirp", KnownTranslationFactory::item_record_chirp_desc()],
			self::DISK_CREATOR => ["Lena Raine - Creator", KnownTranslationFactory::item_record_creator_desc()],
			self::DISK_CREATOR_MUSIC_BOX => ["Lena Raine - Creator (Music Box)", KnownTranslationFactory::item_record_creator_music_box_desc()],
			self::DISK_FAR => ["C418 - far", KnownTranslationFactory::item_record_far_desc()],
			self::DISK_LAVA_CHICKEN => ["Hyper Potions - Lava Chicken", KnownTranslationFactory::item_record_lava_chicken_desc()],
			self::DISK_MALL => ["C418 - mall", KnownTranslationFactory::item_record_mall_desc()],
			self::DISK_MELLOHI => ["C418 - mellohi", KnownTranslationFactory::item_record_mellohi_desc()],
			self::DISK_OTHERSIDE => ["Lena Raine - otherside", KnownTranslationFactory::item_record_otherside_desc()],
			self::DISK_PIGSTEP => ["Lena Raine - Pigstep", KnownTranslationFactory::item_record_pigstep_desc()],
			self::DISK_PRECIPICE => ["Aaron Cherof - Precipice", KnownTranslationFactory::item_record_precipice_desc()],
			self::DISK_RELIC => ["Aaron Cherof - Relic", KnownTranslationFactory::item_record_relic_desc()],
			self::DISK_STAL => ["C418 - stal", KnownTranslationFactory::item_record_stal_desc()],
			self::DISK_STRAD => ["C418 - strad", KnownTranslationFactory::item_record_strad_desc()],
			self::DISK_TEARS => ["Amos Roddy - Tears", KnownTranslationFactory::item_record_tears_desc()],
			self::DISK_WARD => ["C418 - ward", KnownTranslationFactory::item_record_ward_desc()],
			self::DISK_11 => ["C418 - 11", KnownTranslationFactory::item_record_11_desc()],
			self::DISK_WAIT => ["C418 - wait", KnownTranslationFactory::item_record_wait_desc()]
		};
	}

	public function getSoundName() : string{
		return $this->getMetadata()[0];
	}

	/**
	 * @deprecated
	 */
	public function getSoundId() : int{
		return 0;
	}

	public function getTranslatableName() : Translatable{
		return $this->getMetadata()[1];
	}

	/**
	 * Returns the signal strength (1-15) a comparator reading a jukebox with this disc outputs.
	 */
	public function getComparatorSignal() : int{
		return match($this){
			self::DISK_13 => 1,
			self::DISK_CAT => 2,
			self::DISK_BLOCKS => 3,
			self::DISK_CHIRP => 4,
			self::DISK_FAR => 5,
			self::DISK_MALL => 6,
			self::DISK_MELLOHI => 7,
			self::DISK_STAL, self::DISK_BOUNCE => 8,
			self::DISK_STRAD, self::DISK_LAVA_CHICKEN => 9,
			self::DISK_WARD, self::DISK_TEARS => 10,
			self::DISK_11, self::DISK_CREATOR_MUSIC_BOX => 11,
			self::DISK_WAIT, self::DISK_CREATOR => 12,
			self::DISK_PIGSTEP, self::DISK_PRECIPICE => 13,
			self::DISK_OTHERSIDE, self::DISK_RELIC => 14,
			self::DISK_5 => 15,
		};
	}

	/**
	 * Returns the length of the track in seconds (a playing jukebox emits redstone power for that long).
	 */
	public function getDurationSeconds() : int{
		return match($this){
			self::DISK_13 => 178,
			self::DISK_CAT => 185,
			self::DISK_BLOCKS => 345,
			self::DISK_CHIRP => 185,
			self::DISK_FAR => 174,
			self::DISK_MALL => 197,
			self::DISK_MELLOHI => 96,
			self::DISK_STAL => 150,
			self::DISK_STRAD => 188,
			self::DISK_WARD => 251,
			self::DISK_11 => 71,
			self::DISK_WAIT => 237,
			self::DISK_PIGSTEP => 148,
			self::DISK_OTHERSIDE => 195,
			self::DISK_5 => 178,
			self::DISK_RELIC => 219,
			self::DISK_PRECIPICE => 299,
			self::DISK_CREATOR => 176,
			self::DISK_CREATOR_MUSIC_BOX => 73,
			self::DISK_TEARS => 175,
			self::DISK_LAVA_CHICKEN => 135,
			self::DISK_BOUNCE => 234,
		};
	}
}
