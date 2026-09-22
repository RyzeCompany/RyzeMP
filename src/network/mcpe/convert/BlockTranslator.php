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

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\BlockStateSerializeException;
use pocketmine\data\bedrock\block\BlockStateSerializer;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function hexdec;
use function hash;
use function intval;
use function ksort;
use function str_replace;

/**
 * @internal
 */
final class BlockTranslator{
	public const CANONICAL_BLOCK_STATES_PATH = 0;
	public const BLOCK_STATE_META_MAP_PATH = 1;

	private const PATHS = [
		ProtocolInfo::PROTOCOL_1_26_51 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.51',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.51',
		],
		ProtocolInfo::PROTOCOL_1_26_45 => [
			self::CANONICAL_BLOCK_STATES_PATH => '',
			self::BLOCK_STATE_META_MAP_PATH => '',
		],
		ProtocolInfo::PROTOCOL_1_26_40 => [
			self::CANONICAL_BLOCK_STATES_PATH => '',
			self::BLOCK_STATE_META_MAP_PATH => '',
		],
		ProtocolInfo::PROTOCOL_1_26_30 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.30',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.30',
		],
		ProtocolInfo::PROTOCOL_1_26_20 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.20',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.20',
		],
		ProtocolInfo::PROTOCOL_1_26_10 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.10',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.10',
		],
		ProtocolInfo::PROTOCOL_1_26_0 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.0',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.0',
		],
		ProtocolInfo::PROTOCOL_1_21_130 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.0',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.0',
		],
		ProtocolInfo::PROTOCOL_1_21_124 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.0',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.0',
		],
		ProtocolInfo::PROTOCOL_1_21_120 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.0',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.0',
		],
		ProtocolInfo::PROTOCOL_1_21_111 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.26.0',
			self::BLOCK_STATE_META_MAP_PATH => '-1.26.0',
		],
		ProtocolInfo::PROTOCOL_1_21_100 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.100',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.100',
		],
		ProtocolInfo::PROTOCOL_1_21_93 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.93',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.93',
		],
		ProtocolInfo::PROTOCOL_1_21_90 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.93',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.93',
		],
		ProtocolInfo::PROTOCOL_1_21_80 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.93',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.93',
		],
		ProtocolInfo::PROTOCOL_1_21_70 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.70',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.70',
		],
		ProtocolInfo::PROTOCOL_1_21_60 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.60',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.60',
		],
		ProtocolInfo::PROTOCOL_1_21_50 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.50',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.50',
		],
		ProtocolInfo::PROTOCOL_1_21_40 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.40',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.40',
		],
		ProtocolInfo::PROTOCOL_1_21_30 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.30',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.30',
		],
		ProtocolInfo::PROTOCOL_1_21_20 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.20',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.20',
		],
		ProtocolInfo::PROTOCOL_1_21_2 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.2',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.2',
		],
		ProtocolInfo::PROTOCOL_1_21_0 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.21.2',
			self::BLOCK_STATE_META_MAP_PATH => '-1.21.2',
		],
		ProtocolInfo::PROTOCOL_1_20_80 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.20.80',
			self::BLOCK_STATE_META_MAP_PATH => '-1.20.80',
		],
		ProtocolInfo::PROTOCOL_1_20_70 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.20.70',
			self::BLOCK_STATE_META_MAP_PATH => '-1.20.70',
		],
		ProtocolInfo::PROTOCOL_1_20_60 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.20.60',
			self::BLOCK_STATE_META_MAP_PATH => '-1.20.60',
		],
		ProtocolInfo::PROTOCOL_1_20_50 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.20.50',
			self::BLOCK_STATE_META_MAP_PATH => '-1.20.50',
		],
		ProtocolInfo::PROTOCOL_1_20_40 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.20.40',
			self::BLOCK_STATE_META_MAP_PATH => '-1.20.40',
		],
		ProtocolInfo::PROTOCOL_1_20_30 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.20.30',
			self::BLOCK_STATE_META_MAP_PATH => '-1.20.30',
		],
		ProtocolInfo::PROTOCOL_1_20_10 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.20.10',
			self::BLOCK_STATE_META_MAP_PATH => '-1.20.10',
		],
		ProtocolInfo::PROTOCOL_1_20_0 => [
			self::CANONICAL_BLOCK_STATES_PATH => '-1.20.0',
			self::BLOCK_STATE_META_MAP_PATH => '-1.20.0',
		]
	];

	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $networkIdCache = [];

	private bool $useHashedNetworkIds = false;

	/**
	 * These properties exist in the 1.26.50+ network blockstate palette but are not part of
	 * PocketMine's server-side block model. They must not participate in the 1.26.50+ hashed
	 * block network ID calculation.
	 */
	private const NETWORK_ONLY_PROPERTIES = [
		"minecraft:corner" => true,
		"minecraft:connection_north" => true,
		"minecraft:connection_south" => true,
		"minecraft:connection_east" => true,
		"minecraft:connection_west" => true,
	];


	/** Used when a blockstate can't be correctly serialized (e.g. because it's unknown) */
	private BlockStateData $fallbackStateData;
	private int $fallbackStateId;

	public static function loadFromProtocolId(int $protocolId) : BlockTranslator{
		$canonicalBlockStatesRaw = Filesystem::fileGetContents(str_replace(".nbt", self::PATHS[$protocolId][self::CANONICAL_BLOCK_STATES_PATH] . ".nbt", BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT));
		$metaMappingRaw = Filesystem::fileGetContents(str_replace(".json", self::PATHS[$protocolId][self::BLOCK_STATE_META_MAP_PATH] . ".json", BedrockDataFiles::BLOCK_STATE_META_MAP_JSON));
		$translator = new self(
			BlockStateDictionary::loadFromString($canonicalBlockStatesRaw, $metaMappingRaw),
			GlobalBlockStateHandlers::getSerializer(),
		);
		$translator->useHashedNetworkIds = $protocolId >= ProtocolInfo::PROTOCOL_1_26_50;
		return $translator;
	}

	public function __construct(
		private BlockStateDictionary $blockStateDictionary,
		private BlockStateSerializer $blockStateSerializer
	){
		$this->fallbackStateData = BlockStateData::current(BlockTypeNames::INFO_UPDATE, []);
		$this->fallbackStateId = $this->blockStateDictionary->lookupStateIdFromData($this->fallbackStateData) ??
			throw new AssumptionFailedError(BlockTypeNames::INFO_UPDATE . " should always exist");
	}

	public function internalIdToNetworkId(int $internalStateId) : int{
		if(isset($this->networkIdCache[$internalStateId])){
			return $this->networkIdCache[$internalStateId];
		}

		try{
			$blockStateData = $this->blockStateSerializer->serialize($internalStateId);
			$networkId = $this->internalStateDataToNetworkId($blockStateData);
		}catch(BlockStateSerializeException){
			//TODO: this will swallow any error caused by invalid block properties; this is not ideal, but it should be
			//covered by unit tests, so this is probably a safe assumption.
			$networkId = $this->useHashedNetworkIds ? $this->hashBlockStateData($this->fallbackStateData) : $this->fallbackStateId;
		}

		return $this->networkIdCache[$internalStateId] = $networkId;
	}

	private function internalStateDataToNetworkId(BlockStateData $blockStateData) : int{
		if($this->useHashedNetworkIds){
			return $this->hashBlockStateData($blockStateData);
		}

		$networkId = $this->blockStateDictionary->lookupStateIdFromData($blockStateData);
		if($networkId === null){
			throw new BlockStateSerializeException("Unmapped blockstate returned by blockstate serializer: " . $blockStateData->toNbt());
		}
		return $networkId;
	}

	private function hashBlockStateData(BlockStateData $blockStateData) : int{
		$states = $blockStateData->getStates();
		if($this->useHashedNetworkIds){
			foreach($states as $property => $_){
				if(isset(self::NETWORK_ONLY_PROPERTIES[$property])){
					unset($states[$property]);
				}
			}
		}
		ksort($states, SORT_STRING);
		$statesTag = CompoundTag::create();
		foreach(Utils::stringifyKeys($states) as $key => $state){
			$statesTag->setTag($key, $state);
		}
		$tag = CompoundTag::create()
			->setString(BlockStateData::TAG_NAME, $blockStateData->getName())
			->setTag(BlockStateData::TAG_STATES, $statesTag);
		$binaryNbt = (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));
		$hash = intval(hexdec(hash("fnv1a32", $binaryNbt)));
		return $hash > 0x7fffffff ? $hash - 0x100000000 : $hash;
	}

	/**
	 * Looks up the network state data associated with the given internal state ID.
	 */
	private function getCanonicalNetworkId(int $internalStateId) : int{
		try{
			$blockStateData = $this->blockStateSerializer->serialize($internalStateId);
			$networkId = $this->blockStateDictionary->lookupStateIdFromData($blockStateData);
			if($networkId === null){
				throw new BlockStateSerializeException("Unmapped blockstate returned by blockstate serializer: " . $blockStateData->toNbt());
			}
			return $networkId;
		}catch(BlockStateSerializeException){
			return $this->fallbackStateId;
		}
	}

	public function internalIdToNetworkStateData(int $internalStateId) : BlockStateData{
		//we don't directly use the blockstate serializer here - we can't assume that the network blockstate NBT is the
		//same as the disk blockstate NBT, in case we decide to have different world version than network version (or in
		//case someone wants to implement multi version).
		$networkRuntimeId = $this->getCanonicalNetworkId($internalStateId);

		return $this->blockStateDictionary->generateDataFromStateId($networkRuntimeId) ?? throw new AssumptionFailedError("We just looked up this state ID, so it must exist");
	}

	/**
	 * Looks up the current network state data associated with the given internal state ID.
	 */
	public function internalIdToCurrentNetworkStateData(int $internalStateId) : BlockStateData{
		//we don't directly use the blockstate serializer here - we can't assume that the network blockstate NBT is the
		//same as the disk blockstate NBT, in case we decide to have different world version than network version (or in
		//case someone wants to implement multi version).
		$networkRuntimeId = $this->getCanonicalNetworkId($internalStateId);

		return $this->blockStateDictionary->generateCurrentDataFromStateId($networkRuntimeId) ?? throw new AssumptionFailedError("We just looked up this state ID, so it must exist");
	}

	public function getBlockStateDictionary() : BlockStateDictionary{ return $this->blockStateDictionary; }

	public function getFallbackStateData() : BlockStateData{ return $this->fallbackStateData; }
}
