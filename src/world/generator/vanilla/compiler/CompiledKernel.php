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

namespace pocketmine\world\generator\vanilla\compiler;

/**
 * Base class of generated router kernels (see {@link KernelFactory}). Every method is generated code.
 */
abstract class CompiledKernel{
	public const ROLE_TEMPERATURE = 0;
	public const ROLE_VEGETATION = 1;
	public const ROLE_CONTINENTS = 2;
	public const ROLE_EROSION = 3;
	public const ROLE_DEPTH = 4;
	public const ROLE_RIDGES = 5;
	public const ROLE_BARRIER = 6;
	public const ROLE_FLOODEDNESS = 7;
	public const ROLE_SPREAD = 8;
	public const ROLE_LAVA = 9;
	public const ROLE_EXCLUSION = 10;
	public const ROLE_SURFACE_LEVEL = 11;

	/**
	 * Final density for every block of the chunk (cell corners evaluated, then trilinear interpolation),
	 * index ((x << 4) | z) * height + (y - minY).
	 * @return float[]
	 * @phpstan-return list<float>
	 */
	abstract public function fillDensity(int $baseX, int $baseZ) : array;

	abstract public function temperature(float $x, float $y, float $z) : float;

	abstract public function vegetation(float $x, float $y, float $z) : float;

	abstract public function continents(float $x, float $y, float $z) : float;

	abstract public function erosion(float $x, float $y, float $z) : float;

	abstract public function depth(float $x, float $y, float $z) : float;

	abstract public function ridges(float $x, float $y, float $z) : float;

	abstract public function aquiferBarrier(float $x, float $y, float $z) : float;

	abstract public function aquiferFloodedness(float $x, float $y, float $z) : float;

	abstract public function aquiferSpread(float $x, float $y, float $z) : float;

	abstract public function aquiferLava(float $x, float $y, float $z) : float;

	abstract public function aquiferExclusion(float $x, float $y, float $z) : float;

	abstract public function aquiferSurfaceLevel(float $x, float $y, float $z) : float;

	public function evaluate(int $role, float $x, float $y, float $z) : float{
		return match($role){
			self::ROLE_TEMPERATURE => $this->temperature($x, $y, $z),
			self::ROLE_VEGETATION => $this->vegetation($x, $y, $z),
			self::ROLE_CONTINENTS => $this->continents($x, $y, $z),
			self::ROLE_EROSION => $this->erosion($x, $y, $z),
			self::ROLE_DEPTH => $this->depth($x, $y, $z),
			self::ROLE_RIDGES => $this->ridges($x, $y, $z),
			self::ROLE_BARRIER => $this->aquiferBarrier($x, $y, $z),
			self::ROLE_FLOODEDNESS => $this->aquiferFloodedness($x, $y, $z),
			self::ROLE_SPREAD => $this->aquiferSpread($x, $y, $z),
			self::ROLE_LAVA => $this->aquiferLava($x, $y, $z),
			self::ROLE_EXCLUSION => $this->aquiferExclusion($x, $y, $z),
			self::ROLE_SURFACE_LEVEL => $this->aquiferSurfaceLevel($x, $y, $z),
			default => throw new \InvalidArgumentException("Unknown role $role")
		};
	}
}
