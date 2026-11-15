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

namespace pocketmine\entity\monster;

use function count;
use function max;
use function min;
use const PHP_INT_MAX;

/**
 * The warden's anger towards each suspect (vanilla minecraft:anger_level): every disturbance adds anger to the entity
 * which caused it, anger decays by 1 every second, and a suspect with 80 or more anger is a target. The anger level
 * of the warden is the highest anger towards any suspect (it drives the heartbeat and the ambient sounds).
 *
 * Values from Mojang's bedrock-samples behavior_pack/entities/warden.json: angry_threshold 80, angry_boost 20 (added
 * when the warden roars at a new target), default_annoyingness 35, default_projectile_annoyingness 10, max_anger
 * 150, anger_decrement_interval 1 s; agitated sounds from 40.
 */
final class WardenAnger{
	public const MAX_ANGER = 150;
	public const ANGRY_THRESHOLD = 80;
	public const AGITATED_THRESHOLD = 40;
	public const ANGRY_BOOST = 20;
	public const DEFAULT_ANNOYINGNESS = 35;
	public const PROJECTILE_ANNOYINGNESS = 10;
	/** Anger added by hurting the warden (vanilla Java: 100). */
	public const HURT_ANNOYINGNESS = 100;
	/** Most suspects remembered (vanilla Java keeps at most 100). */
	public const MAX_SUSPECTS = 100;

	/**
	 * @var int[][] entity ID => [anger, 1 if a player else 0]
	 * @phpstan-var array<int, array{int, int}>
	 */
	private array $suspects = [];

	/**
	 * Adds anger towards the entity and returns its new anger, capped at {@link WardenAnger::MAX_ANGER}.
	 */
	public function increase(int $entityId, bool $isPlayer, int $amount) : int{
		if(!isset($this->suspects[$entityId]) && count($this->suspects) >= self::MAX_SUSPECTS){
			$this->removeLeastAngry();
		}
		$anger = min(self::MAX_ANGER, max(0, ($this->suspects[$entityId][0] ?? 0) + $amount));
		if($anger <= 0){
			unset($this->suspects[$entityId]);
		}else{
			$this->suspects[$entityId] = [$anger, $isPlayer ? 1 : 0];
		}
		return $anger;
	}

	private function removeLeastAngry() : void{
		$least = null;
		$leastAnger = PHP_INT_MAX;
		foreach($this->suspects as $id => [$anger]){
			if($anger < $leastAnger){
				$least = $id;
				$leastAnger = $anger;
			}
		}
		if($least !== null){
			unset($this->suspects[$least]);
		}
	}

	/**
	 * Lowers every anger by the given amount, forgetting suspects whose anger reaches 0.
	 */
	public function decay(int $amount = 1) : void{
		foreach($this->suspects as $id => [$anger, $player]){
			$anger -= $amount;
			if($anger <= 0){
				unset($this->suspects[$id]);
			}else{
				$this->suspects[$id] = [$anger, $player];
			}
		}
	}

	public function get(int $entityId) : int{
		return $this->suspects[$entityId][0] ?? 0;
	}

	public function forget(int $entityId) : void{
		unset($this->suspects[$entityId]);
	}

	public function clear() : void{
		$this->suspects = [];
	}

	/**
	 * Returns the warden's anger level: the highest anger towards any suspect.
	 */
	public function getAngerLevel() : int{
		$level = 0;
		foreach($this->suspects as [$anger]){
			if($anger > $level){
				$level = $anger;
			}
		}
		return $level;
	}

	public function isAngryAt(int $entityId) : bool{
		return $this->get($entityId) >= self::ANGRY_THRESHOLD;
	}

	/**
	 * Returns the suspects the warden is angry at (anger >= 80), best target first: players before other mobs, then
	 * the angriest first (vanilla priority).
	 *
	 * @param \Closure|null $isValid filter for suspects which can be targeted (e.g. still alive and nearby)
	 * @phpstan-param (\Closure(int $entityId) : bool)|null $isValid
	 */
	public function getAngriest(?\Closure $isValid = null) : ?int{
		$best = null;
		$bestPlayer = -1;
		$bestAnger = -1;
		foreach($this->suspects as $id => [$anger, $player]){
			if($anger < self::ANGRY_THRESHOLD){
				continue;
			}
			if($player > $bestPlayer || ($player === $bestPlayer && $anger > $bestAnger)){
				if($isValid !== null && !$isValid($id)){
					continue;
				}
				$best = $id;
				$bestPlayer = $player;
				$bestAnger = $anger;
			}
		}
		return $best;
	}

	/**
	 * @return int[][]
	 * @phpstan-return array<int, array{int, int}>
	 */
	public function getAll() : array{
		return $this->suspects;
	}
}
