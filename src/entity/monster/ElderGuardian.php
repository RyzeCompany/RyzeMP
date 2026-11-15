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

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\world\sound\MobSound;
use function mt_rand;

/**
 * Bedrock values (elder_guardian.json): 80 health, 1.99x1.99, movement 0.3, swims at 0.15 (underwater_movement 0.3 x
 * random_swim speed_multiplier 0.5), follow range 16, attack 5, laser charged in 60 ticks. Unlike guardians it keeps
 * charging when its target comes close (no target_nearby_sensor). Every minute it curses the survival players within
 * 50 blocks: Mining Fatigue III for 5 minutes, with the elder guardian ghost (LevelEvent GUARDIAN_CURSE) and sound.
 * Loot: 0-2 prismarine shards (+0-1 per Looting level); raw cod (3/6), prismarine crystals (2/6) or nothing (1/6);
 * a wet sponge when killed by a player; 2.5% (+1% per Looting level) a fish from fishing/fish.json; 20% a tide armor
 * trim. Experience 10.
 */
class ElderGuardian extends Guardian{
	public const ATTACK_DURATION = 60;
	public const ELDER_SWIM_SPEED = 0.15;
	public const CURSE_INTERVAL = 1200;
	public const CURSE_RANGE = 50;
	public const CURSE_TICKS = 6000;
	public const CURSE_AMPLIFIER = 2;

	public static function getNetworkTypeId() : string{ return EntityIds::ELDER_GUARDIAN; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.99, 1.99, 0.995);
	}

	public function getName() : string{
		return "Elder Guardian";
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setMaxHealth(80);
		$this->setHealth($nbt->getTag("Health") !== null ? $this->getHealth() : 80);
		$this->setMovementSpeed(0.3);
	}

	public function isElder() : bool{
		return true;
	}

	public function getAttackDuration() : int{
		return self::ATTACK_DURATION;
	}

	protected function getSwimSpeed() : float{
		return self::ELDER_SWIM_SPEED;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive() && ($this->server->getTick() + $this->id) % self::CURSE_INTERVAL === 0){
			$this->curseNearbyPlayers();
		}
		return $hasUpdate;
	}

	/**
	 * Gives Mining Fatigue III to the survival players within 50 blocks that don't have it yet (or will lose it within
	 * a minute), showing them the elder guardian ghost. Returns the players cursed.
	 *
	 * @return Player[]
	 */
	public function curseNearbyPlayers() : array{
		$cursed = [];
		$fatigue = VanillaEffects::MINING_FATIGUE();
		foreach($this->getWorld()->getPlayers() as $player){
			if(!Sensing::isTargetablePlayer($player) || $player->getLocation()->distanceSquared($this->location) > self::CURSE_RANGE ** 2){
				continue;
			}
			$current = $player->getEffects()->get($fatigue);
			if($current !== null && $current->getAmplifier() >= self::CURSE_AMPLIFIER && $current->getDuration() >= self::CURSE_INTERVAL){
				continue;
			}
			$player->getEffects()->add(new EffectInstance($fatigue, self::CURSE_TICKS, self::CURSE_AMPLIFIER));
			$player->getNetworkSession()->sendDataPacket(LevelEventPacket::create(LevelEvent::GUARDIAN_CURSE, 0, $player->getPosition()));
			$player->getWorld()->addSound($player->getPosition(), new MobSound(LevelSoundEvent::ELDERGUARDIAN_CURSE, self::getNetworkTypeId()), [$player]);
			$cursed[] = $player;
		}
		return $cursed;
	}

	public function getDrops() : array{
		$drops = [VanillaItems::PRISMARINE_SHARD()->setCount(mt_rand(0, 2) + $this->lootingBonus())];
		$roll = mt_rand(0, 5);
		if($roll < 3){
			$drops[] = VanillaItems::RAW_FISH()->setCount(1 + $this->lootingBonus());
		}elseif($roll < 5){
			$drops[] = VanillaItems::PRISMARINE_CRYSTALS()->setCount(1 + $this->lootingBonus());
		}
		if($this->wasRecentlyHitByPlayerDirectly()){
			$drops[] = VanillaBlocks::SPONGE()->setWet(true)->asItem();
			$this->addPlayerKillFish($drops);
		}
		if(mt_rand(0, 4) === 0){
			$drops[] = VanillaItems::TIDE_ARMOR_TRIM_SMITHING_TEMPLATE();
		}
		return $drops;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ELDER_GUARDIAN_SPAWN_EGG();
	}
}
