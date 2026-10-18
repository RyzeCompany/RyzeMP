<?php

declare(strict_types=1);

namespace pocketmine\world\format\io\leveldb;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\StringTag;
use function in_array;
use function str_ends_with;
use function str_starts_with;

/** Adapts derived geometry saved by other PM forks, without relaxing unknown-state validation. */
final class ImportedBlockStateNormalizer{

	public static function normalize(BlockStateData $data) : BlockStateData{
		$name = $data->getName();
		if(!str_starts_with($name, "minecraft:")){
			return $data;
		}
		$states = $data->getStates();
		$changed = false;
		if(str_ends_with($name, "_stairs")){
			$corner = $states["minecraft:corner"] ?? null;
			if($corner instanceof StringTag && in_array($corner->getValue(), ["none", "inner_left", "inner_right", "outer_left", "outer_right"], true)){
				// Stair::readStateFromWorld() derives this from neighbours; facing and upside-down stay intact.
				unset($states["minecraft:corner"]);
				$changed = true;
			}
		}elseif(str_ends_with($name, "_fence") || str_ends_with($name, "_glass_pane") || $name === "minecraft:glass_pane" || $name === "minecraft:iron_bars"){
			foreach(["north", "south", "west", "east"] as $face){
				$key = "minecraft:connection_" . $face;
				$value = $states[$key] ?? null;
				if($value instanceof ByteTag && ($value->getValue() === 0 || $value->getValue() === 1)){
					// Fence/Thin derive connections from neighbours as well.
					unset($states[$key]);
					$changed = true;
				}
			}
		}
		return $changed ? new BlockStateData($name, $states, $data->getVersion()) : $data;
	}
}
