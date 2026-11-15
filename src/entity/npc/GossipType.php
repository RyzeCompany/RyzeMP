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

namespace pocketmine\entity\npc;

/**
 * Kinds of gossip a villager keeps about a player (https://minecraft.wiki/w/Villager#Gossiping): weight in the
 * reputation, maximum value, decay every 20 minutes and value lost when shared with another villager. Values added:
 * curing +20 major and +25 minor positive, each trade +2 trading, a hit +25 minor negative, killing a villager nearby
 * +25 major negative (see the GOSSIP_* constants of {@link VillagerV2}).
 */
enum GossipType : string{
	/** Killing a villager nearby. */
	case MAJOR_NEGATIVE = "major_negative";
	/** Hitting the villager. */
	case MINOR_NEGATIVE = "minor_negative";
	/** Curing the villager (decays). */
	case MINOR_POSITIVE = "minor_positive";
	/** Curing the villager (permanent, never shared). */
	case MAJOR_POSITIVE = "major_positive";
	/** Trading with the villager. */
	case TRADING = "trading";

	public function getWeight() : int{
		return match($this){
			self::MAJOR_NEGATIVE => -5,
			self::MINOR_NEGATIVE => -1,
			self::MINOR_POSITIVE => 1,
			self::MAJOR_POSITIVE => 5,
			self::TRADING => 1,
		};
	}

	public function getMax() : int{
		return match($this){
			self::MAJOR_NEGATIVE => 100,
			self::MINOR_NEGATIVE => 200,
			self::MINOR_POSITIVE => 200,
			self::MAJOR_POSITIVE => 20,
			self::TRADING => 25,
		};
	}

	/** Value lost every 20 minutes. */
	public function getDecay() : int{
		return match($this){
			self::MAJOR_NEGATIVE => 10,
			self::MINOR_NEGATIVE => 20,
			self::MINOR_POSITIVE => 1,
			self::MAJOR_POSITIVE => 0,
			self::TRADING => 2,
		};
	}

	/** Value lost when the gossip is passed to another villager. */
	public function getShareCost() : int{
		return match($this){
			self::MAJOR_NEGATIVE => 10,
			self::MINOR_NEGATIVE => 20,
			self::MINOR_POSITIVE => 5,
			self::MAJOR_POSITIVE => 100,
			self::TRADING => 20,
		};
	}
}
