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

use pocketmine\player\Player;

/**
 * Interface implemented by objects that can be charged.
 *
 * Flow (same as vanilla): the first use starts charging; while charging, the client sends another use when its
 * own charge bar is full, which calls onChargeComplete(); releasing before that cancels; once charged, the next
 * use fires (onClickAir()).
 */
interface Chargeable extends Releasable{
	/**
	 * Returns an amount of ticks that this item should be in charge.
	 */
	public function getChargeDuration() : int;

	public function setCharged(?Item $item) : void;

	public function isCharged() : bool;

	/**
	 * Called when the client reports that charging finished. Returns whether the item is now charged.
	 *
	 * @param int $useDuration ticks the player has been using the item, as measured by the server
	 */
	public function onChargeComplete(Player $player, int $useDuration) : bool;
}
