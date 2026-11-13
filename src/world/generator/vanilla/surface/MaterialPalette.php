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

namespace pocketmine\world\generator\vanilla\surface;

use pocketmine\world\generator\vanilla\Aquifer;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;

/**
 * Small integer ids for the Java block ids produced by material rules, stored in TerrainChunk::$materials.
 * The terrain stage materials (Aquifer::MATERIAL_*) keep their ids; MATERIAL_STONE is the default block of the noise
 * settings (stone, netherrack, end stone).
 */
final class MaterialPalette{
	/** @phpstan-var array<string, int> */
	private array $ids = [];
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $names = [];

	/**
	 * @param string $defaultBlock Java id of the noise settings' default block (MATERIAL_STONE)
	 */
	public function __construct(string $defaultBlock = "minecraft:stone"){
		foreach([
			Aquifer::MATERIAL_AIR => "minecraft:air",
			Aquifer::MATERIAL_STONE => $defaultBlock,
			Aquifer::MATERIAL_WATER => "minecraft:water",
			Aquifer::MATERIAL_LAVA => "minecraft:lava",
		] as $id => $name){
			if($this->register($name) !== $id){
				throw new \LogicException("Terrain material ids must come first");
			}
		}
	}

	public function register(string $blockId) : int{
		$name = "minecraft:" . WorldgenData::stripNamespace($blockId);
		if(!isset($this->ids[$name])){
			$this->ids[$name] = count($this->names);
			$this->names[] = $name;
		}
		return $this->ids[$name];
	}

	public function get(string $blockId) : ?int{
		return $this->ids["minecraft:" . WorldgenData::stripNamespace($blockId)] ?? null;
	}

	/**
	 * @return string[] material id => Java block id
	 * @phpstan-return list<string>
	 */
	public function getNames() : array{
		return $this->names;
	}
}
