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

use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\InstantEffect;
use function intdiv;
use function max;

/**
 * Arrow tipped with a potion (Bedrock: minecraft:arrow with meta = potion ID + 1). The arrow applies the potion's
 * effects to the mob it hits, with 1/8 of the potion's duration (instant effects are applied as they are).
 */
class TippedArrow extends Arrow{
	/** Tipped arrows apply their effects for this fraction of the potion's duration. */
	public const DURATION_DIVISOR = 8;

	private PotionType $potionType = PotionType::WATER;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->enum($this->potionType);
	}

	public function getType() : PotionType{ return $this->potionType; }

	/**
	 * @return $this
	 */
	public function setType(PotionType $type) : self{
		$this->potionType = $type;
		return $this;
	}

	/**
	 * Returns the effects that an arrow tipped with the given potion applies on hit.
	 *
	 * @return EffectInstance[]
	 * @phpstan-return list<EffectInstance>
	 */
	public static function getArrowEffects(PotionType $type) : array{
		$result = [];
		foreach($type->getEffects() as $effect){
			if(!$effect->getType() instanceof InstantEffect){
				$effect->setDuration(max(1, intdiv($effect->getDuration(), self::DURATION_DIVISOR)));
			}
			$result[] = $effect;
		}
		return $result;
	}
}
