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
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Living;
use pocketmine\player\Player;
use pocketmine\world\sound\OminousBottleEndUseSound;

/**
 * Drinking it gives Bad Omen for 100 minutes. The level of the effect is stored in the item's meta.
 */
class OminousBottle extends Item implements ConsumableItem{
	public const MIN_AMPLIFIER = 0;
	public const MAX_AMPLIFIER = 4;

	/** Bad Omen duration in ticks (100 minutes) */
	public const EFFECT_DURATION = 120000;

	private int $amplifier = self::MIN_AMPLIFIER;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::MIN_AMPLIFIER, self::MAX_AMPLIFIER, $this->amplifier);
	}

	/**
	 * Returns the amplifier of the Bad Omen effect given when drunk (0 = Bad Omen I).
	 */
	public function getAmplifier() : int{ return $this->amplifier; }

	/**
	 * @return $this
	 */
	public function setAmplifier(int $amplifier) : self{
		if($amplifier < self::MIN_AMPLIFIER || $amplifier > self::MAX_AMPLIFIER){
			throw new \InvalidArgumentException("Amplifier must be in range " . self::MIN_AMPLIFIER . " ... " . self::MAX_AMPLIFIER);
		}
		$this->amplifier = $amplifier;
		return $this;
	}

	public function onConsume(Living $consumer) : void{
		$consumer->broadcastSound(new OminousBottleEndUseSound());
	}

	public function getAdditionalEffects() : array{
		return [
			new EffectInstance(VanillaEffects::BAD_OMEN(), self::EFFECT_DURATION, $this->amplifier, visible: false)
		];
	}

	public function getResidue() : Item{
		return VanillaItems::AIR();
	}

	public function canStartUsingItem(Player $player) : bool{
		return true;
	}
}
