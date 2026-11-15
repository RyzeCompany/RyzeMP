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
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\utils\Binary;
use pocketmine\utils\Utils;
use function array_key_first;
use function array_map;
use function count;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function ksort;
use function ord;
use function strlen;
use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

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
	 * FNV-1a 32-bit parameters, used by the vanilla client to compute hashed block network IDs
	 * (StartGamePacket::blockNetworkIdsAreHashes).
	 */
	private const FNV1_32_OFFSET = 0x811c9dc5;
	private const FNV1_32_PRIME = 0x01000193;

	/**
	 * Network ID the vanilla client uses for an unknown block when hashed IDs are in use.
	 */
	public const UNKNOWN_BLOCK_NETWORK_ID = -2;

	/**
	 * @param BlockStateDictionaryEntry[] $states keyed by network runtime ID
	 *
	 * @phpstan-param array<int, BlockStateDictionaryEntry> $states
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

	/**
	 * Searches for the appropriate state ID which matches the given blockstate NBT.
	 * Returns null if there were no matches.
	 */
	public function lookupStateIdFromData(BlockStateData $data) : ?int{
		$name = $data->getName();

		$lookup = $this->stateDataToStateIdLookup[$name] ?? null;
		return match(true){
			$lookup === null => null,
			is_int($lookup) => $lookup,
			is_array($lookup) => $lookup[BlockStateDictionaryEntry::encodeStateProperties($data->getStates())] ?? null
		};
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

	/**
	 * @param list<int>|null $networkIds Explicit IDs for palettes using hashed network IDs.
	 *                           Older palettes use their position in the list instead.
	 */
	public static function loadFromString(string $blockPaletteContents, string $metaMapContents, ?array $networkIds = null, bool $useHashedIds = true) : self{
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

		$palette = self::loadPaletteFromString($blockPaletteContents);
		if(count($palette) !== count($metaMap) || ($networkIds !== null && count($palette) !== count($networkIds))){
			throw new \InvalidArgumentException("Palette, metadata and network IDs must have matching lengths");
		}
		foreach($palette as $i => $state){
			$meta = $metaMap[$i] ?? null;
			if($meta === null){
				throw new \InvalidArgumentException("Missing associated meta value for state $i (" . $state->toNbt() . ")");
			}
			if(!is_int($meta)){
				throw new \InvalidArgumentException("Invalid metaMap offset $i, expected int, got " . get_debug_type($meta));
			}
			$uniqueName = $uniqueNames[$state->getName()] ??= $state->getName();

			//Network IDs are hashes of the blockstate NBT rather than positions in the palette. This makes the server
			//independent of the (unpublished) order of Mojang's internal block registry: the palette file only needs to
			//contain the right set of states, which can be regenerated from Mojang's public vanilla data.
			$networkId = $networkIds !== null ? ($networkIds[$i] ?? null) : ($useHashedIds ? self::computeNetworkId($uniqueName, $state->getStates()) : $i);
			if(!is_int($networkId)){
				throw new \InvalidArgumentException("Invalid network ID for state $i");
			}
			if(isset($entries[$networkId])){
				throw new \InvalidArgumentException("Duplicate network ID $networkId for state $i");
			}
			$entries[$networkId] = new BlockStateDictionaryEntry($uniqueName, $state->getStates(), $meta);
		}

		return new self($entries);
	}

	/**
	 * Computes the hashed network runtime ID of a blockstate, as the vanilla client does when
	 * StartGamePacket::blockNetworkIdsAreHashes is true: FNV-1a 32-bit over the little-endian NBT encoding of
	 * TAG_Compound{name: TAG_String, states: TAG_Compound} with the state properties sorted by name.
	 * The result is returned as a signed 32-bit integer.
	 *
	 * @param Tag[] $stateProperties
	 * @phpstan-param array<string, Tag> $stateProperties
	 */
	public static function computeNetworkId(string $name, array $stateProperties) : int{
		if($name === BlockTypeNames::UNKNOWN){
			return self::UNKNOWN_BLOCK_NETWORK_ID;
		}
		ksort($stateProperties, SORT_STRING);
		$statesTag = CompoundTag::create();
		foreach(Utils::stringifyKeys($stateProperties) as $k => $v){
			$statesTag->setTag($k, $v);
		}
		$nbt = (new LittleEndianNbtSerializer())->write(new TreeRoot(CompoundTag::create()
			->setString(BlockStateData::TAG_NAME, $name)
			->setTag(BlockStateData::TAG_STATES, $statesTag)
		));

		$hash = self::FNV1_32_OFFSET;
		for($i = 0, $len = strlen($nbt); $i < $len; ++$i){
			$hash = (($hash ^ ord($nbt[$i])) * self::FNV1_32_PRIME) & 0xffffffff;
		}
		return Binary::signInt($hash);
	}
}
