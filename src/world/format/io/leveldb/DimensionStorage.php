<?php

declare(strict_types=1);

namespace pocketmine\world\format\io\leveldb;

use InvalidArgumentException;
use pocketmine\utils\Binary;
use pocketmine\world\format\io\exception\UnsupportedWorldFormatException;
use function strlen;
use function substr;

/** Bedrock uses no dimension suffix for Overworld, and a little-endian ID for Nether/End. */
final class DimensionStorage{

	public static function validate(int $dimension) : void{
		if($dimension < 0 || $dimension > 2){
			throw new InvalidArgumentException("LevelDB storage dimension must be 0, 1 or 2");
		}
	}

	public static function chunkIndex(int $x, int $z, int $dimension) : string{
		self::validate($dimension);
		return Binary::writeLInt($x) . Binary::writeLInt($z) . ($dimension === 0 ? "" : Binary::writeLInt($dimension));
	}

	/** Identifies only exact chunk-version keys; unrelated DB entries are ignored. */
	public static function versionKeyDimension(string $key) : ?int{
		$length = strlen($key);
		if($length === 9){
			$dimension = 0;
		}elseif($length === 13){
			$dimension = Binary::readLInt(substr($key, 8, 4));
			if($dimension !== 1 && $dimension !== 2){ return null; }
		}else{
			return null;
		}
		return $key[$length - 1] === ChunkDataKey::NEW_VERSION || $key[$length - 1] === ChunkDataKey::OLD_VERSION ? $dimension : null;
	}

	/**
	 * Prefer the target's native data over old accidentally generated Overworld chunks.
	 * Existing PM separate-world maps keep using storage 0; an empty DB also keeps that layout.
	 * @param array<int, true> $available
	 */
	public static function select(array $available, int $target, ?int $requested = null) : int{
		self::validate($target);
		if($requested !== null){
			self::validate($requested);
			if($available !== [] && !isset($available[$requested])){
				throw new UnsupportedWorldFormatException("Requested LevelDB source dimension $requested has no chunks; check dimensions.yml source-dimension");
			}
			return $requested;
		}
		if(isset($available[$target])){ return $target; }
		if($available === [] || isset($available[0])){ return 0; }
		throw new UnsupportedWorldFormatException("No LevelDB chunks for target dimension $target or separate-world storage 0; select source-dimension explicitly");
	}
}
