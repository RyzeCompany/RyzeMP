<?php

declare(strict_types=1);

namespace pocketmine\world;

use InvalidArgumentException;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\utils\Config;
use function is_bool;
use function is_file;
use function strtolower;

/** Rules whose effects are implemented by RyzeMP. Stored independently per world. */
final class GameRules{
	public const SHOW_COORDINATES = "showcoordinates";
	/** @var array<string, bool> */
	private array $values;

	public function __construct(private string $path, bool $defaultShowCoordinates = false){
		$this->values = [self::SHOW_COORDINATES => $defaultShowCoordinates];
		if(is_file($path)){
			$config = new Config($path, Config::YAML);
			foreach($this->values as $name => $default){
				$value = $config->get($name, $default);
				if(!is_bool($value)){ throw new InvalidArgumentException("$path: $name must be true or false"); }
				$this->values[$name] = $value;
			}
		}
	}

	/** @return array<string, bool> */
	public function getAll() : array{ return $this->values; }

	public function getBool(string $name) : bool{
		$name = strtolower($name);
		return $this->values[$name] ?? throw new InvalidArgumentException("Unsupported gamerule: $name");
	}

	/** Persist first; a failed write must not change the running rule. */
	public function setBool(string $name, bool $value) : void{
		$name = strtolower($name);
		$this->getBool($name);
		$next = $this->values;
		$next[$name] = $value;
		$config = new Config($this->path, Config::YAML, $this->values);
		$config->setAll($next);
		$config->save();
		$this->values = $next;
	}

	/** @return array<string, BoolGameRule> */
	public function toNetwork() : array{
		return [
			"naturalregeneration" => new BoolGameRule(false, false),
			"locatorbar" => new BoolGameRule(false, false),
			self::SHOW_COORDINATES => new BoolGameRule($this->getBool(self::SHOW_COORDINATES), false)
		];
	}
}
