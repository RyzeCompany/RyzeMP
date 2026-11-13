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

namespace pocketmine\item;

use function str_replace;
use function strtolower;
use function ucwords;

/**
 * The picture on a pottery sherd. Also used by decorated pots to describe the pattern on each side.
 */
enum PotterySherdType{
	case ANGLER;
	case ARCHER;
	case ARMS_UP;
	case BLADE;
	case BREWER;
	case BURN;
	case DANGER;
	case EXPLORER;
	case FLOW;
	case FRIEND;
	case GUSTER;
	case HEART;
	case HEARTBREAK;
	case HOWL;
	case MINER;
	case MOURNER;
	case PLENTY;
	case PRIZE;
	case SCRAPE;
	case SHEAF;
	case SHELTER;
	case SKULL;
	case SNORT;

	/**
	 * Returns the human-readable name of the pattern, e.g. "Arms Up".
	 */
	public function getDisplayName() : string{
		return ucwords(str_replace("_", " ", strtolower($this->name)));
	}
}
