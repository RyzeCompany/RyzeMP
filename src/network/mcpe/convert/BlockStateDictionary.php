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

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_key_first;
use function array_map;
use function count;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function str_ends_with;
use const JSON_THROW_ON_ERROR;

/**
 * Handles translation of network block runtime IDs into blockstate data, and vice versa
 */
final class BlockStateDictionary{
	/**
	 * @var int[][]|int[]
	 * @phpstan-var array<string, array<string, int>|int>
	 */
	private array $stateDataToStateIdLookup = [];

	/**
	 * @var int[][]|null
	 * @phpstan-var array<string, array<int, int>|int>|null
	 */
	private ?array $idMetaToStateIdLookupCache = null;

	/**
	 * @param BlockStateDictionaryEntry[] $states
	 *
	 * @phpstan-param list<BlockStateDictionaryEntry> $states
	 */
	public function __construct(
		private array $states
	){
		$table = [];
		foreach($this->states as $stateId => $stateNbt){
			$table[$stateNbt->getStateName()][$stateNbt->getRawStateProperties()] = $stateId;
		}

		//setup fast path for stateless blocks
		foreach(Utils::stringifyKeys($table) as $name => $stateIds){
			if(count($stateIds) === 1){
				$this->stateDataToStateIdLookup[$name] = $stateIds[array_key_first($stateIds)];
			}else{
				$this->stateDataToStateIdLookup[$name] = $stateIds;
			}
		}

		$standardSkull = $this->stateDataToStateIdLookup[BlockTypeNames::SKELETON_SKULL];
		foreach([
			BlockTypeNames::WITHER_SKELETON_SKULL,
			BlockTypeNames::ZOMBIE_HEAD,
			BlockTypeNames::PLAYER_HEAD,
			BlockTypeNames::CREEPER_HEAD,
			BlockTypeNames::DRAGON_HEAD,
			BlockTypeNames::PIGLIN_HEAD
		] as $skull){
			if(!isset($this->stateDataToStateIdLookup[$skull])){
				$this->stateDataToStateIdLookup[$skull] = $standardSkull;
			}
		}
	}

	/**
	 * @return int[][]
	 * @phpstan-return array<string, array<int, int>|int>
	 */
	private function getIdMetaToStateIdLookup() : array{
		if($this->idMetaToStateIdLookupCache === null){
			$table = [];
			//TODO: if we ever allow mutating the dictionary, this would need to be rebuilt on modification

			foreach($this->states as $i => $state){
				$table[$state->getStateName()][$state->getMeta()] = $i;
			}

			$this->idMetaToStateIdLookupCache = [];
			foreach(Utils::stringifyKeys($table) as $name => $metaToStateId){
				//if only one meta value exists
				if(count($metaToStateId) === 1){
					$this->idMetaToStateIdLookupCache[$name] = $metaToStateId[array_key_first($metaToStateId)];
				}else{
					$this->idMetaToStateIdLookupCache[$name] = $metaToStateId;
				}
			}
		}

		return $this->idMetaToStateIdLookupCache;
	}

	public function generateDataFromStateId(int $networkRuntimeId) : ?BlockStateData{
		return ($this->states[$networkRuntimeId] ?? null)?->generateStateData();
	}

	public function generateCurrentDataFromStateId(int $networkRuntimeId) : ?BlockStateData{
		return ($this->states[$networkRuntimeId] ?? null)?->generateCurrentStateData();
	}

	/**
	 * Searches for the appropriate state ID which matches the given blockstate NBT.
	 * Returns null if there were no matches.
	 */
	public function lookupStateIdFromData(BlockStateData $data) : ?int{
		$name = $data->getName();

		$lookup = $this->stateDataToStateIdLookup[$name] ?? null;
		if($lookup === null){
			return null;
		}
		if(is_int($lookup)){
			return $lookup;
		}

		$rawProperties = BlockStateDictionaryEntry::encodeStateProperties($data->getStates());
		if(isset($lookup[$rawProperties])){
			return $lookup[$rawProperties];
		}

		// Bedrock 1.26.51 adds the minecraft:corner=none state to straight stair variants.
		// PocketMine's stair serializer has no neighbour information and intentionally omits this property.
		// It also uses the legacy 0,1,2,3 encoding for weirdo_direction, while the canonical
		// 1.26.51 palette uses 0,-1,1,-2. Match the canonical state semantically without changing
		// the serializer or the network representation.
		if(str_ends_with($name, '_stairs') && isset($data->getStates()['upside_down_bit'], $data->getStates()['weirdo_direction'])){
			$properties = $data->getStates();
			$direction = $properties['weirdo_direction']->getValue();
			$directionMap = [0 => 0, 1 => -1, 2 => 1, 3 => -2];
			$canonicalDirection = $directionMap[$direction] ?? null;
			if($canonicalDirection !== null){
				foreach($lookup as $stateId){
					$candidate = $this->states[$stateId]->generateCurrentStateData()->getStates();
					$corner = $candidate['minecraft:corner'] ?? null;
					if($corner !== null && $corner->getValue() !== 'none'){
						continue;
					}
					if(!isset($candidate['weirdo_direction'], $candidate['upside_down_bit']) ||
						$candidate['weirdo_direction']->getValue() !== $canonicalDirection ||
						$candidate['upside_down_bit']->getValue() !== $properties['upside_down_bit']->getValue()){
						continue;
					}
					return $stateId;
				}
			}
		}

		// Bedrock 1.26.51 represents the stair weirdo_direction enum as 0, -1, 1, -2,
		// while PocketMine's serializer uses the legacy 0, 1, 2, 3 encoding.
		// Convert only this affected 1.26.51 state family; the wire value itself is not changed.
		if(str_ends_with($name, '_stairs') && isset($data->getStates()['weirdo_direction'])){
			$properties = $data->getStates();
			$direction = $properties['weirdo_direction']->getValue();
			$directionMap = [0 => 0, 1 => -1, 2 => 1, 3 => -2];
			if(isset($directionMap[$direction])){
				$properties['weirdo_direction'] = new \pocketmine\nbt\tag\IntTag($directionMap[$direction]);
				$mappedRawProperties = BlockStateDictionaryEntry::encodeStateProperties($properties);
				if(isset($lookup[$mappedRawProperties])){
					return $lookup[$mappedRawProperties];
				}
			}
		}

		// Bedrock 1.26.51 represents the trapdoor direction enum as 0, -1, 1, -2,
		// while PocketMine's serializer uses the legacy 0, 1, 2, 3 encoding.
		// Convert only this affected 1.26.51 state family; the wire value itself is not changed.
		if($name === BlockTypeNames::WAXED_COPPER_TRAPDOOR && isset($data->getStates()['direction'])){
			$properties = $data->getStates();
			$direction = $properties['direction']->getValue();
			$directionMap = [0 => 0, 1 => -1, 2 => 1, 3 => -2];
			if(isset($directionMap[$direction])){
				$properties['direction'] = new \pocketmine\nbt\tag\IntTag($directionMap[$direction]);
				$mappedRawProperties = BlockStateDictionaryEntry::encodeStateProperties($properties);
				if(isset($lookup[$mappedRawProperties])){
					return $lookup[$mappedRawProperties];
				}
			}
		}

		// Bedrock 1.26.40+ exposes cardinal connection states for fences and thin blocks.
		// Item/blockstate serialization has no world neighbours, so these blocks serialize without
		// connection properties. Resolve that stateless representation to the canonical no-connection state.
		if($data->getStates() === []){
			foreach($lookup as $stateId){
				$candidate = $this->states[$stateId]->generateCurrentStateData()->getStates();
				if($candidate === []){
					continue;
				}
				$allConnectionsFalse = true;
				foreach($candidate as $propertyName => $value){
					if(!str_starts_with($propertyName, "minecraft:connection_") || $value->getValue() !== 0){
						$allConnectionsFalse = false;
						break;
					}
				}
				if($allConnectionsFalse){
					return $stateId;
				}
			}
		}

		return null;
	}

	/**
	 * Returns the blockstate meta value associated with the given blockstate runtime ID.
	 * This is used for serializing crafting recipe inputs.
	 */
	public function getMetaFromStateId(int $networkRuntimeId) : ?int{
		return ($this->states[$networkRuntimeId] ?? null)?->getMeta();
	}

	/**
	 * Returns the blockstate data associated with the given block ID and meta value.
	 * This is used for deserializing crafting recipe inputs.
	 */
	public function lookupStateIdFromIdMeta(string $id, int $meta) : ?int{
		$metas = $this->getIdMetaToStateIdLookup()[$id] ?? null;
		return match(true){
			$metas === null => null,
			is_int($metas) => $metas,
			is_array($metas) => $metas[$meta] ?? null
		};
	}

	/**
	 * Returns an array mapping runtime ID => blockstate data.
	 * @return BlockStateDictionaryEntry[]
	 * @phpstan-return array<int, BlockStateDictionaryEntry>
	 */
	public function getStates() : array{ return $this->states; }

	/**
	 * @return BlockStateData[]
	 * @phpstan-return list<BlockStateData>
	 *
	 * @throws NbtDataException
	 */
	public static function loadPaletteFromString(string $blockPaletteContents) : array{
		return array_map(
			fn(TreeRoot $root) => BlockStateData::fromNbt($root->mustGetCompoundTag()),
			(new NetworkNbtSerializer())->readMultiple($blockPaletteContents)
		);
	}

	public static function loadFromString(string $blockPaletteContents, string $metaMapContents, bool $upgrade = true) : self{
		$metaMap = json_decode($metaMapContents, flags: JSON_THROW_ON_ERROR);
		if(!is_array($metaMap)){
			throw new \InvalidArgumentException("Invalid metaMap, expected array for root type, got " . get_debug_type($metaMap));
		}

		$entries = [];

		$uniqueNames = [];

		//this hack allows the internal cache index to use interned strings which are already available in the
		//core code anyway, saving around 40 KB of memory
		foreach((new \ReflectionClass(BlockTypeNames::class))->getConstants() as $value){
			if(is_string($value)){
				$uniqueNames[$value] = $value;
			}
		}

		foreach(self::loadPaletteFromString($blockPaletteContents) as $i => $state){
			$meta = $metaMap[$i] ?? null;
			if($meta === null){
				throw new \InvalidArgumentException("Missing associated meta value for state $i (" . $state->toNbt() . ")");
			}
			if(!is_int($meta)){
				throw new \InvalidArgumentException("Invalid metaMap offset $i, expected int, got " . get_debug_type($meta));
			}
			$newState = $upgrade && $state->getVersion() !== BlockStateData::CURRENT_VERSION ? GlobalBlockStateHandlers::getUpgrader()->getBlockStateUpgrader()->upgrade($state) : $state;
			$uniqueName = $uniqueNames[$newState->getName()] ??= $newState->getName();
			$entries[$i] = new BlockStateDictionaryEntry($uniqueName, $newState->getStates(), $meta, $newState->equals($state) ? null : $state);
		}

		return new self($entries);
	}
}
