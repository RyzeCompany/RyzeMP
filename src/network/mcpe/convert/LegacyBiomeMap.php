<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\data\bedrock\BiomeIds;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\utils\Filesystem;

/** Project server biome IDs onto definitions that actually exist in 1.16.100. */
final class LegacyBiomeMap{
	/** @var array<int, true>|null */
	private static ?array $supported = null;

	public static function toLegacyId(int $biomeId) : int{
		if(self::$supported === null){
			$definitions = (new NetworkNbtSerializer())->read(Filesystem::fileGetContents(
				\pocketmine\BEDROCK_DATA_PATH . 'legacy/1.16.100/biome_definitions.nbt'
			))->mustGetCompoundTag();
			$ids = json_decode(Filesystem::fileGetContents(BedrockDataFiles::BIOME_ID_MAP_JSON), true, flags: JSON_THROW_ON_ERROR);
			if(!is_array($ids)){
				throw new \UnexpectedValueException('Invalid biome ID map');
			}
			$supported = [];
			foreach($ids as $name => $id){
				if(is_int($id) && $id >= 0 && $id <= 255 && $definitions->getTag($name) !== null){
					$supported[$id] = true;
				}
			}
			self::$supported = $supported;
		}
		return isset(self::$supported[$biomeId]) ? $biomeId : BiomeIds::OCEAN;
	}
}
