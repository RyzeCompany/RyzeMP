<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/** Called before a native redstone component changes its electrical state. */
class BlockRedstoneUpdateEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(Block $block, private int $oldPower, private int $newPower){
		parent::__construct($block);
		self::validatePower($oldPower);
		self::validatePower($newPower);
	}

	private static function validatePower(int $power) : void{
		if($power < 0 || $power > 15){
			throw new \InvalidArgumentException("Redstone power must be in range 0-15");
		}
	}

	public function getOldPower() : int{ return $this->oldPower; }

	public function getNewPower() : int{ return $this->newPower; }

	public function setNewPower(int $power) : void{
		self::validatePower($power);
		$this->newPower = $power;
	}
}
