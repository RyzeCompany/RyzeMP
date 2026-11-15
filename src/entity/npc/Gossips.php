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

namespace pocketmine\entity\npc;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\utils\Utils;
use function count;
use function max;
use function min;

/**
 * What a villager has heard about players (per player UUID): the gossip values of each {@link GossipType}. The
 * reputation of a player is the sum of value x weight. Gossip decays every 20 minutes and is passed on (minus its share
 * cost) when villagers meet at the bell.
 */
final class Gossips{
	/** Ticks between two decays (20 minutes). */
	public const DECAY_INTERVAL = 24000;
	/** Players remembered at most; the ones with the least gossip are forgotten first. */
	public const MAX_TARGETS = 64;

	/**
	 * @var int[][] player UUID => gossip type => value
	 * @phpstan-var array<string, array<string, int>>
	 */
	private array $values = [];

	public function add(string $target, GossipType $type, int $amount) : void{
		$value = min($type->getMax(), max(0, ($this->values[$target][$type->value] ?? 0) + $amount));
		$this->set($target, $type, $value);
	}

	private function set(string $target, GossipType $type, int $value) : void{
		if($value <= 0){
			unset($this->values[$target][$type->value]);
			if(isset($this->values[$target]) && count($this->values[$target]) === 0){
				unset($this->values[$target]);
			}
			return;
		}
		$this->values[$target][$type->value] = $value;
		if(count($this->values) > self::MAX_TARGETS){
			$this->forgetLeastKnown();
		}
	}

	private function forgetLeastKnown() : void{
		$worst = null;
		$worstTotal = 0;
		foreach(Utils::stringifyKeys($this->values) as $target => $types){
			$total = 0;
			foreach($types as $value){
				$total += $value;
			}
			if($worst === null || $total < $worstTotal){
				$worst = $target;
				$worstTotal = $total;
			}
		}
		if($worst !== null){
			unset($this->values[$worst]);
		}
	}

	public function get(string $target, GossipType $type) : int{
		return $this->values[$target][$type->value] ?? 0;
	}

	/**
	 * Sum of the gossip values about the player times their weights (negative when the player hurt villagers).
	 */
	public function getReputation(string $target) : int{
		$reputation = 0;
		foreach(Utils::stringifyKeys($this->values[$target] ?? []) as $type => $value){
			$reputation += $value * GossipType::from($type)->getWeight();
		}
		return $reputation;
	}

	public function isEmpty() : bool{
		return count($this->values) === 0;
	}

	/**
	 * Lowers every gossip by its decay (called every {@link Gossips::DECAY_INTERVAL} ticks).
	 */
	public function decay() : void{
		foreach(Utils::stringifyKeys($this->values) as $target => $types){
			foreach(Utils::stringifyKeys($types) as $type => $value){
				$gossipType = GossipType::from($type);
				$this->set($target, $gossipType, $value - $gossipType->getDecay());
			}
		}
	}

	/**
	 * Tells the other villager what this one knows: it keeps the higher of its own value and this one minus the share
	 * cost.
	 */
	public function shareWith(Gossips $other) : void{
		foreach(Utils::stringifyKeys($this->values) as $target => $types){
			foreach(Utils::stringifyKeys($types) as $type => $value){
				$gossipType = GossipType::from($type);
				$shared = $value - $gossipType->getShareCost();
				if($shared > $other->get($target, $gossipType)){
					$other->set($target, $gossipType, $shared);
				}
			}
		}
	}

	public function toNbt() : ListTag{
		$list = new ListTag([], NBT::TAG_Compound);
		foreach(Utils::stringifyKeys($this->values) as $target => $types){
			foreach(Utils::stringifyKeys($types) as $type => $value){
				$list->push(CompoundTag::create()
					->setString("Target", $target)
					->setString("Type", $type)
					->setInt("Value", $value));
			}
		}
		return $list;
	}

	public static function fromNbt(?ListTag $list) : self{
		$gossips = new self();
		if($list === null){
			return $gossips;
		}
		foreach($list as $tag){
			if(!$tag instanceof CompoundTag){
				continue;
			}
			$type = GossipType::tryFrom($tag->getString("Type", ""));
			$target = $tag->getString("Target", "");
			if($type !== null && $target !== ""){
				$gossips->add($target, $type, $tag->getInt("Value", 0));
			}
		}
		return $gossips;
	}
}
