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
use pocketmine\data\bedrock\block\BlockStateSerializeException;
use pocketmine\data\bedrock\block\BlockStateSerializer;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\network\mcpe\ClientVersionSupport;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * @internal
 */
final class BlockTranslator{
	public static function loadFromProtocolId(int $protocolId) : self{
		$suffix = ClientVersionSupport::getBlockPaletteSuffix($protocolId);
		$networkIdsFile = ClientVersionSupport::getBlockNetworkIdsFile($protocolId);
		$path = \pocketmine\BEDROCK_DATA_PATH;
		$networkIds = $networkIdsFile === null ? null : json_decode(Filesystem::fileGetContents($path . $networkIdsFile), true, flags: JSON_THROW_ON_ERROR);
		if($networkIds !== null){
			if(!is_array($networkIds) || !array_is_list($networkIds)){
				throw new \UnexpectedValueException("Invalid block network ID list for protocol $protocolId");
			}
			foreach($networkIds as $networkId){
				if(!is_int($networkId)){
					throw new \UnexpectedValueException("Invalid block network ID for protocol $protocolId");
				}
			}
		}
		return new self(
			BlockStateDictionary::loadFromString(
				Filesystem::fileGetContents($path . "canonical_block_states$suffix.nbt"),
				Filesystem::fileGetContents($path . "block_state_meta_map$suffix.json"),
				$networkIds,
				$networkIdsFile !== null
			),
			GlobalBlockStateHandlers::getSerializer(),
			$protocolId < ProtocolInfo::CURRENT_PROTOCOL
		);
	}
	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $networkIdCache = [];

	/** Used when a blockstate can't be correctly serialized (e.g. because it's unknown) */
	private BlockStateData $fallbackStateData;
	private int $fallbackStateId;

	public function __construct(
		private BlockStateDictionary $blockStateDictionary,
		private BlockStateSerializer $blockStateSerializer,
		private bool $allowMissingStates = false
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

			$networkId = $this->blockStateDictionary->lookupStateIdFromData($blockStateData);
			if($networkId === null){
				if(!$this->allowMissingStates){
					throw new AssumptionFailedError("Unmapped blockstate returned by blockstate serializer: " . $blockStateData->toNbt());
				}
				$networkId = $this->fallbackStateId;
			}
		}catch(BlockStateSerializeException){
			//TODO: this will swallow any error caused by invalid block properties; this is not ideal, but it should be
			//covered by unit tests, so this is probably a safe assumption.
			$networkId = $this->fallbackStateId;
		}

		return $this->networkIdCache[$internalStateId] = $networkId;
	}

	/**
	 * Looks up the network state data associated with the given internal state ID.
	 */
	public function internalIdToNetworkStateData(int $internalStateId) : BlockStateData{
		//we don't directly use the blockstate serializer here - we can't assume that the network blockstate NBT is the
		//same as the disk blockstate NBT, in case we decide to have different world version than network version (or in
		//case someone wants to implement multi version).
		$networkRuntimeId = $this->internalIdToNetworkId($internalStateId);

		return $this->blockStateDictionary->generateDataFromStateId($networkRuntimeId) ?? throw new AssumptionFailedError("We just looked up this state ID, so it must exist");
	}

	public function getBlockStateDictionary() : BlockStateDictionary{ return $this->blockStateDictionary; }

	public function getFallbackStateData() : BlockStateData{ return $this->fallbackStateData; }
}
