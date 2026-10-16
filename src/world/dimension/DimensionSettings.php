<?php

declare(strict_types=1);

namespace pocketmine\world\dimension;

use InvalidArgumentException;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use function abs;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;

/** Validated settings for the separate-world dimension layout. */
final class DimensionSettings{

	/** @param array<int, string> $worldNames */
	private function __construct(
		private array $worldNames,
		public readonly int $portalLoadTimeoutTicks,
		public readonly bool $endUseWorldSpawn,
		public readonly int $endX,
		public readonly int $endY,
		public readonly int $endZ,
		public readonly bool $endCreatePlatform
	){}

	/** @param mixed[] $config */
	public static function fromArray(array $config) : self{
		$worldNames = [];
		foreach(["nether" => DimensionIds::NETHER, "end" => DimensionIds::THE_END] as $key => $id){
			$section = self::section($config, $key);
			$name = $section["world"] ?? ($key === "nether" ? "world_nether" : "world_the_end");
			if(!is_string($name) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $name) !== 1){
				throw new InvalidArgumentException("dimensions.yml: $key.world must be a world folder name without a path");
			}
			if(self::boolean($section, "enabled", true)){
				$worldNames[$id] = $name;
			}
		}
		if(isset($worldNames[DimensionIds::NETHER], $worldNames[DimensionIds::THE_END]) && $worldNames[DimensionIds::NETHER] === $worldNames[DimensionIds::THE_END]){
			throw new InvalidArgumentException("dimensions.yml: Nether and End must use different world folders");
		}
		$portal = self::section($config, "portal");
		$end = self::section($config, "end");
		$arrival = self::section($end, "arrival");
		$x = self::integer($arrival, "x", 100);
		$y = self::integer($arrival, "y", 49);
		$z = self::integer($arrival, "z", 0);
		$timeout = self::integer($portal, "load-timeout-ticks", 600);
		if(abs($x) > 29999872 || abs($z) > 29999872 || $y < 1 || $y > 253){
			throw new InvalidArgumentException("dimensions.yml: End arrival must have |x|, |z| <= 29999872 and y between 1 and 253");
		}
		if($timeout < 20 || $timeout > 12000){
			throw new InvalidArgumentException("dimensions.yml: portal.load-timeout-ticks must be between 20 and 12000");
		}
		return new self($worldNames, $timeout, self::boolean($arrival, "use-world-spawn", false), $x, $y, $z, self::boolean($arrival, "create-platform", true));
	}

	/** @return array<int, string> */
	public function getWorldNames() : array{
		return $this->worldNames;
	}

	public function validateOverworld(string $worldName) : void{
		foreach($this->worldNames as $name){
			if($name === $worldName){
				throw new InvalidArgumentException("dimensions.yml: a dimension folder cannot be the default Overworld ($worldName)");
			}
		}
	}

	/** @param mixed[] $config @return mixed[] */
	private static function section(array $config, string $key) : array{
		$value = $config[$key] ?? [];
		if(!is_array($value)){
			throw new InvalidArgumentException("dimensions.yml: $key must be a section");
		}
		return $value;
	}

	/** @param mixed[] $config */
	private static function boolean(array $config, string $key, bool $default) : bool{
		$value = $config[$key] ?? $default;
		if(!is_bool($value)){
			throw new InvalidArgumentException("dimensions.yml: $key must be true or false");
		}
		return $value;
	}

	/** @param mixed[] $config */
	private static function integer(array $config, string $key, int $default) : int{
		$value = $config[$key] ?? $default;
		if(!is_int($value)){
			throw new InvalidArgumentException("dimensions.yml: $key must be an integer");
		}
		return $value;
	}
}
