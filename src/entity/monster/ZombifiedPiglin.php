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

use pocketmine\entity\ai\goal\LookAtPlayerGoal;
use pocketmine\entity\ai\goal\MeleeAttackGoal;
use pocketmine\entity\ai\goal\OpenDoorGoal;
use pocketmine\entity\ai\goal\RandomLookAroundGoal;
use pocketmine\entity\ai\goal\RandomStrollGoal;
use pocketmine\entity\ai\hostile\goal\AngerTargetGoal;
use pocketmine\entity\Living;
use pocketmine\entity\Zombie;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use function mt_rand;

/**
 * Zombified piglin (minecraft:zombie_pigman). Bedrock values (zombie_pigman.json): 20 health, movement 0.23 (chases at
 * 1.5x), attack 5, fire immune, golden sword (5% golden spear). Neutral: when hurt, it and every zombified piglin
 * within 20 blocks get angry at the attacker for 25 seconds.
 * Like zombies, some pick up items within 3 blocks, whole stacks (see {@link MobShareables::zombifiedPiglin()}).
 * Loot: 0-1 rotten flesh, 0-1 gold nugget (+0-1 each per Looting level), 2.5% (+1% per Looting level) gold ingot when
 * killed by a player.
 */
class ZombifiedPiglin extends Zombie implements Angerable{
	use AngerTrait;

	public const ATTACK_DAMAGE = 5.0;
	public const ANGER_TICKS = 500;
	public const ANGER_RANGE = 20.0;

	public static function getNetworkTypeId() : string{ return EntityIds::ZOMBIE_PIGMAN; }

	public function getName() : string{
		return "Zombified Piglin";
	}

	/**
	 * zombie_pigman_gear: a golden sword (5% a golden spear), 25% enchanted x regional difficulty; no armor.
	 */
	protected function getSpawnEquipmentTable() : ?string{
		return "zombie_pigman_gear";
	}

	protected function registerGoals() : void{
		//navigation can_open_doors: opens the wooden doors on its paths
		$this->goalSelector->addGoal(0, new OpenDoorGoal($this));
		$this->goalSelector->addGoal(5, new MeleeAttackGoal($this, 1.5, self::ATTACK_DAMAGE, 40.0));
		$this->goalSelector->addGoal(8, new RandomStrollGoal($this, 1.0));
		$this->goalSelector->addGoal(9, new LookAtPlayerGoal($this, 8.0));
		$this->goalSelector->addGoal(9, new RandomLookAroundGoal($this));

		$this->targetSelector->addGoal(1, new AngerTargetGoal($this, $this->getAngerTarget(...)));
	}

	public function burnsInDaylight() : bool{
		return false;
	}

	public function isFireProof() : bool{
		return true;
	}

	public function canBreathe() : bool{
		return true;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(!$source->isCancelled() && $source instanceof EntityDamageByEntityEvent){
			$damager = $source->getDamager();
			if($damager instanceof Living && !$damager instanceof ZombifiedPiglin && (!$damager instanceof Player || !$damager->isCreative())){
				$this->setAngryAt($damager, self::ANGER_TICKS);
				$this->alertNearby($damager, self::ANGER_RANGE, ZombifiedPiglin::class, self::ANGER_TICKS);
			}
		}
	}

	protected function onAngerChange(bool $angry) : void{
		if($angry){
			$this->playMobSound(LevelSoundEvent::ANGRY);
		}
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->isAlive()){
			$this->tickAnger($tickDiff);
		}
		return $hasUpdate;
	}

	public function getDrops() : array{
		$drops = [
			VanillaItems::ROTTEN_FLESH()->setCount(mt_rand(0, 1) + $this->lootingBonus()),
			VanillaItems::GOLD_NUGGET()->setCount(mt_rand(0, 1) + $this->lootingBonus())
		];
		if($this->wasRecentlyHitByPlayer() && mt_rand(0, 999) < 25 + 10 * $this->getLootingLevel()){
			$drops[] = VanillaItems::GOLD_INGOT();
		}
		return [...$drops, ...$this->getEquipmentDrops()];
	}

	public function getXpDropAmount() : int{
		return $this->equipmentXp($this->isBaby() ? 12 : 5);
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::ZOMBIE_PIGMAN_SPAWN_EGG();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setGenericFlag(EntityMetadataFlags::ANGRY, $this->isAngry());
	}
}
