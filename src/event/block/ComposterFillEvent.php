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

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\block\Composter;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\item\Item;

class ComposterFillEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Block $block,
		protected Item $item,
		protected int $oldFillLayer,
		protected int $newFillLayer
	){
		parent::__construct($block);
	}

	public function getItem() : Item{ return $this->item; }

	public function getOldFillLayer() : int{ return $this->oldFillLayer; }

	public function getNewFillLayer() : int{ return $this->newFillLayer; }

	public function setNewFillLayer(int $layer) : void{
		if($layer < Composter::MIN_COMPOST_LAYERS || $layer > Composter::MAX_COMPOST_LAYERS){
			throw new \InvalidArgumentException("Compost layer must be in range " . Composter::MIN_COMPOST_LAYERS . " ... " . Composter::MAX_COMPOST_LAYERS . ", got $layer");
		}
		$this->newFillLayer = $layer;
	}
}
