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

namespace pocketmine\world\generator\vanilla\feature\type;

use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureContext;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;
use pocketmine\world\generator\vanilla\feature\PlacedFeature;
use pocketmine\world\generator\vanilla\feature\Providers;
use pocketmine\world\generator\vanilla\math\FloatMath;
use pocketmine\world\generator\vanilla\WorldgenData;
use function count;
use function is_array;

/**
 * Features that pick or chain other placed features: random_selector, simple_random_selector,
 * weighted_random_selector, random_boolean_selector and sequence.
 */
final class SelectorFeature implements Feature{
	public const RANDOM = 0;
	public const SIMPLE_RANDOM = 1;
	public const WEIGHTED = 2;
	public const BOOLEAN = 3;
	public const SEQUENCE = 4;

	/** @phpstan-var list<PlacedFeature> */
	private array $features = [];
	/** @phpstan-var list<float> chances (random_selector) */
	private array $chances = [];
	/** @phpstan-var list<int> weights (weighted_random_selector) */
	private array $weights = [];
	private int $totalWeight = 0;
	private ?PlacedFeature $default = null;

	/**
	 * @param mixed[] $json
	 */
	public function __construct(FeatureRegistry $registry, private readonly int $mode, array $json){
		switch($mode){
			case self::RANDOM:
				foreach(WorldgenData::array($json, "features") as $entry){
					if(!is_array($entry)){
						throw new \RuntimeException("Invalid random_selector entry");
					}
					$this->features[] = $registry->placedRef($entry["feature"] ?? null);
					$this->chances[] = FloatMath::fround(WorldgenData::float($entry, "chance"));
				}
				$this->default = $registry->placedRef($json["default"] ?? null);
				break;
			case self::SIMPLE_RANDOM:
			case self::SEQUENCE:
				$list = $json["features"] ?? null;
				foreach(is_array($list) ? $list : [$list] as $entry){
					$this->features[] = $registry->placedRef($entry);
				}
				break;
			case self::WEIGHTED:
				[$this->features, $this->weights, $this->totalWeight] = Providers::weightedList($json["features"] ?? null, fn(mixed $data) : PlacedFeature => $registry->placedRef($data));
				break;
			case self::BOOLEAN:
				$this->features = [$registry->placedRef($json["feature_true"] ?? null), $registry->placedRef($json["feature_false"] ?? null)];
				break;
		}
	}

	public function place(FeatureContext $c, int $x, int $y, int $z) : bool{
		$random = $c->random;
		switch($this->mode){
			case self::RANDOM:
				foreach($this->features as $i => $feature){
					if($random->nextFloat() < $this->chances[$i]){
						return $feature->place($c, $x, $y, $z);
					}
				}
				return $this->default !== null && $this->default->place($c, $x, $y, $z);
			case self::SIMPLE_RANDOM:
				return $this->features[$random->nextBoundedInt(count($this->features))]->place($c, $x, $y, $z);
			case self::WEIGHTED:
				if($this->totalWeight <= 0){
					return false;
				}
				return Providers::pick($random, $this->features, $this->weights, $this->totalWeight)->place($c, $x, $y, $z);
			case self::BOOLEAN:
				return $this->features[$random->nextBoolean() ? 0 : 1]->place($c, $x, $y, $z);
			default: //sequence
				foreach($this->features as $feature){
					if(!$feature->place($c, $x, $y, $z)){
						return false;
					}
				}
				return true;
		}
	}
}
