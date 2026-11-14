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

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\entity\riding\RideableSeat;
use pocketmine\entity\Zombie;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\world\World;

/**
 * Bedrock values (cave_spider.json): a small spider (12 health, 0.7x0.5, movement 0.3, attack 2) whose bites poison
 * for 7 seconds on normal and 15 on hard (none on easy). Loot as spiders.
 */
class CaveSpider extends Spider{
	public static function getNetworkTypeId() : string{ return EntityIds::CAVE_SPIDER; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.5, 0.7, 0.45);
	}

	public function getName() : string{
		return "Cave Spider";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(12);
		if($this->getHealth() > 12){
			$this->setHealth(12);
		}
	}

	public function getRideableSeats() : array{
		//vanilla cave_spider.json: skeletons sit further back than baby zombies
		return [($this->passengers[0] ?? null) instanceof Zombie ? RideableSeat::at(0, 0.325, 0) : RideableSeat::at(0, 0.325, -0.1)];
	}

	/**
	 * Returns the poison duration in ticks of a bite on the given difficulty.
	 */
	public static function getPoisonTicks(int $difficulty) : int{
		return match($difficulty){
			World::DIFFICULTY_NORMAL => 7 * 20,
			World::DIFFICULTY_HARD => 15 * 20,
			default => 0
		};
	}

	public function attackMelee(Living $target, float $baseDamage) : bool{
		$hit = parent::attackMelee($target, $baseDamage);
		$ticks = self::getPoisonTicks($this->getWorld()->getDifficulty());
		if($hit && $ticks > 0 && $target->isAlive()){
			$target->getEffects()->add(new EffectInstance(VanillaEffects::POISON(), $ticks));
		}
		return $hit;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::CAVE_SPIDER_SPAWN_EGG();
	}
}
