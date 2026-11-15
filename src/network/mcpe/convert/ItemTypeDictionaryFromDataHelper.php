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

use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use function base64_decode;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;

final class ItemTypeDictionaryFromDataHelper{
	public static function loadFromProtocolId(int $protocolId) : ItemTypeDictionary{
		$suffix = match($protocolId){
			ProtocolInfo::PROTOCOL_1_26_60 => "-1.26.60",
			ProtocolInfo::PROTOCOL_1_26_52 => "-1.26.51",
			ProtocolInfo::PROTOCOL_1_26_40, ProtocolInfo::PROTOCOL_1_26_45 => "",
			default => throw new \InvalidArgumentException("Unsupported item palette protocol $protocolId")
		};
		return self::loadFromString(Filesystem::fileGetContents(\pocketmine\BEDROCK_DATA_PATH . "required_item_list$suffix.json"));
	}

	public static function loadFromString(string $data) : ItemTypeDictionary{
		$table = json_decode($data, true);
		if(!is_array($table)){
			throw new AssumptionFailedError("Invalid item list format");
		}

		$emptyNBT = new CacheableNbt(new CompoundTag());
		$nbtSerializer = new LittleEndianNbtSerializer();

		$params = [];
		foreach(Utils::promoteKeys($table) as $name => $entry){
			if(!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"], $entry["version"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"]) || !is_int($entry["version"]) || !(is_string($componentNbt = $entry["component_nbt"] ?? null) || $componentNbt === null)){
				throw new AssumptionFailedError("Invalid item list format");
			}
			$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"], $entry["version"], $componentNbt === null ? $emptyNBT : new CacheableNbt($nbtSerializer->read(ErrorToExceptionHandler::trapAndRemoveFalse(fn() => base64_decode($componentNbt, true)))->mustGetCompoundTag()));
		}
		return new ItemTypeDictionary($params);
	}
}
