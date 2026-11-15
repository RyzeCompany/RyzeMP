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

namespace pocketmine\world\dimension;

use pocketmine\network\mcpe\protocol\types\DimensionIds;
use function strtolower;
use function trim;

/**
 * The vanilla dimension a world is presented as to the client. It decides the sky, fog and light the client renders,
 * the vertical range of chunks sent and the build height of the world.
 */
enum Dimension{
	case OVERWORLD;
	case NETHER;
	case END;

	/**
	 * @phpstan-return DimensionIds::*
	 */
	public function getNetworkId() : int{
		return match($this){
			self::OVERWORLD => DimensionIds::OVERWORLD,
			self::NETHER => DimensionIds::NETHER,
			self::END => DimensionIds::THE_END,
		};
	}

	/**
	 * Lowest Y coordinate blocks may be placed at in this dimension (inclusive).
	 */
	public function getMinY() : int{
		return match($this){
			self::OVERWORLD => -64,
			self::NETHER, self::END => 0,
		};
	}

	/**
	 * Y coordinate above the highest block that may be placed in this dimension (exclusive), same convention as
	 * World::Y_MAX.
	 */
	public function getMaxY() : int{
		return match($this){
			self::OVERWORLD => 320,
			self::NETHER => 128,
			self::END => 256,
		};
	}

	/**
	 * Whether the dimension receives light from the sky. The Nether and the End don't: their light comes only from
	 * blocks.
	 */
	public function hasSkyLight() : bool{
		return $this === self::OVERWORLD;
	}

	/**
	 * Whether the dimension is ultra-warm, like the vanilla Nether: water placed there evaporates, ice and wet sponges
	 * dry out, and lava flows faster and further.
	 */
	public function isUltraWarm() : bool{
		return $this === self::NETHER;
	}

	/**
	 * Horizontal coordinate scale relative to the Overworld (1 block in the Nether = 8 blocks in the Overworld).
	 */
	public function getCoordinateScale() : int{
		return $this === self::NETHER ? 8 : 1;
	}

	/**
	 * Suffix added to the name of an Overworld world to name its linked world of this dimension, like the vanilla
	 * Java server ("world_nether", "world_the_end").
	 */
	public function getWorldNameSuffix() : string{
		return match($this){
			self::OVERWORLD => "",
			self::NETHER => "_nether",
			self::END => "_the_end",
		};
	}

	/**
	 * Name of the built-in generator used to create a new world of this dimension.
	 */
	public function getDefaultGeneratorName() : string{
		return match($this){
			self::OVERWORLD => "normal",
			self::NETHER => "nether",
			self::END => "the_end",
		};
	}

	public static function fromString(string $name) : ?self{
		return match(strtolower(trim($name))){
			"overworld", "normal", "0" => self::OVERWORLD,
			"nether", "hell", "the_nether", "1" => self::NETHER,
			"end", "the_end", "2" => self::END,
			default => null,
		};
	}

	public static function fromNetworkId(int $id) : ?self{
		return match($id){
			DimensionIds::OVERWORLD => self::OVERWORLD,
			DimensionIds::NETHER => self::NETHER,
			DimensionIds::THE_END => self::END,
			default => null,
		};
	}
}
