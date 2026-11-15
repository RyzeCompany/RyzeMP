<?php
declare(strict_types=1);
namespace pocketmine\block;
use pocketmine\data\bedrock\BedrockDataFiles;
use pocketmine\utils\Filesystem;
use function is_array;
use function json_decode;
use const JSON_THROW_ON_ERROR;
use const pocketmine\BEDROCK_DATA_PATH;
/**
 * Use the base BDS export and the versioned 1.26.51 export only for new block names.
 * Missing or invalid property data must still fail parity tests, never silently skip.
 */
final class BlockPropertiesTable{
	/** @return array<string, array<string, mixed>> */
	public static function load() : array{
		$base = json_decode(Filesystem::fileGetContents(BedrockDataFiles::BLOCK_PROPERTIES_TABLE_JSON), true, 3, JSON_THROW_ON_ERROR);
		$newer = json_decode(Filesystem::fileGetContents(BEDROCK_DATA_PATH . "/block_properties_table-1.26.51.json"), true, 3, JSON_THROW_ON_ERROR);
		if(!is_array($base) || !is_array($newer)){
			throw new \UnexpectedValueException("Bedrock block properties tables must be JSON objects");
		}
		foreach($newer as $name => $properties){
			if(!isset($base[$name]) && is_array($properties)){
				$base[$name] = $properties;
			}
		}
		return $base;
	}
}
