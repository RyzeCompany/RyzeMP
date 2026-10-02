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

namespace pocketmine\entity\projectile;

use pocketmine\math\Vector3;
use pocketmine\ServerConfigGroup;
use function is_array;
use function is_finite;
use function is_numeric;
use function max;
use function min;
use function sqrt;

/** Settings are bounded once per cast; existing servers work without new YAML keys. */
final class FishingSettings{
	public readonly bool $enabled;
	public readonly bool $pvpEnabled;
	public readonly float $range;
	public readonly int $cooldownTicks;
	public readonly float $pullStrength;
	public readonly float $pullVertical;
	public readonly int $biteWaitMin;
	public readonly int $biteWaitMax;
	public readonly int $biteWindow;
	public readonly int $lifetimeTicks;

	/** @param array<string, mixed> $values */
	public function __construct(array $values = []){
		$this->enabled = ($values["enabled"] ?? true) === true;
		$this->pvpEnabled = ($values["pvp-enabled"] ?? true) === true;
		$this->range = self::number($values, "max-distance", 24, 2, 64);
		$this->cooldownTicks = (int) self::number($values, "cooldown-ticks", 10, 1, 200);
		$this->pullStrength = self::number($values, "pull-strength", 0.6, 0, 1.5);
		$this->pullVertical = self::number($values, "pull-vertical", 0.2, 0, 0.5);
		$this->biteWaitMin = (int) self::number($values, "bite-wait-min-ticks", 100, 1, 12000);
		$this->biteWaitMax = max($this->biteWaitMin, (int) self::number($values, "bite-wait-max-ticks", 300, 1, 12000));
		$this->biteWindow = (int) self::number($values, "bite-window-ticks", 30, 1, 200);
		$this->lifetimeTicks = (int) self::number($values, "lifetime-ticks", 2400, 20, 24000);
	}

	public static function fromConfig(ServerConfigGroup $config) : self{
		$values = $config->getProperty("fishing", []);
		return new self(is_array($values) ? $values : []);
	}

	/** @param array<string, mixed> $values */
	private static function number(array $values, string $key, float $default, float $min, float $max) : float{
		$value = $values[$key] ?? $default;
		if(!is_numeric($value) || !is_finite((float) $value)){
			return $default;
		}
		return max($min, min($max, (float) $value));
	}

	/** A bounded impulse, independent of sprint speed, with no teleportation. */
	public function getPullMotion(Vector3 $towardsOwner) : Vector3{
		$horizontal = sqrt($towardsOwner->x ** 2 + $towardsOwner->z ** 2);
		$scale = $horizontal > 0 ? min($this->pullStrength, $horizontal) / $horizontal : 0;
		return new Vector3(
			$towardsOwner->x * $scale,
			max(-$this->pullVertical, min($this->pullVertical, $towardsOwner->y * 0.05 + $this->pullVertical)),
			$towardsOwner->z * $scale
		);
	}
}
