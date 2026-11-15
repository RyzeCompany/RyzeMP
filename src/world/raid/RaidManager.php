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

namespace pocketmine\world\raid;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\RaidOmenEffect;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Mob;
use pocketmine\entity\monster\Raider;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\RaidOmenSound;
use pocketmine\world\World;
use pocketmine\YmlServerProperties;
use function count;

/**
 * The raids of a world: turns Bad Omen into Raid Omen when a player enters a village, starts a raid when Raid Omen
 * runs out, ticks the raids ({@link Raid}) and keeps them in {@link RaidStore} so that they survive restarts.
 */
final class RaidManager{
	/** Ticks between two checks of whether players with Bad Omen are in a village. */
	public const OMEN_CHECK_INTERVAL = 20;
	/** Raids are saved this often while they last (and whenever a wave starts or ends). */
	private const SAVE_INTERVAL = 600;
	/** Two raids can't be closer than this (blocks between centres). */
	public const MIN_RAID_DISTANCE = 96.0;

	private bool $enabled;
	private RaidStore $store;
	private int $nextId = 1;
	/**
	 * @var Raid[]
	 * @phpstan-var array<int, Raid>
	 */
	private array $raids = [];
	/**
	 * Village centre where each player got Raid Omen, by UUID.
	 * @var Vector3[]
	 * @phpstan-var array<string, Vector3>
	 */
	private array $omenCenters = [];
	/**
	 * Raid Omens that ran out this tick: [player, amplifier].
	 * @phpstan-var list<array{Player, int}>
	 */
	private array $expiredOmens = [];
	private bool $dirty = false;

	public function __construct(
		private World $world,
		?RaidStore $store = null,
		?bool $enabled = null
	){
		$this->enabled = $enabled ?? $world->getServer()->getConfigGroup()->getPropertyBool(YmlServerProperties::MOBS_RAIDS_ENABLED, true);
		$this->store = $store ?? RaidStore::forDataPath($world->getServer()->getDataPath());
		$saved = $this->store->get($world->getFolderName());
		$this->nextId = $saved["nextId"];
		foreach($saved["raids"] as $data){
			$raid = Raid::fromArray($data);
			if($raid !== null){
				$this->raids[$raid->getId()] = $raid;
				if($raid->getId() >= $this->nextId){
					$this->nextId = $raid->getId() + 1;
				}
			}
		}
	}

	public function isEnabled() : bool{
		return $this->enabled;
	}

	/**
	 * @return Raid[]
	 * @phpstan-return array<int, Raid>
	 */
	public function getRaids() : array{
		return $this->raids;
	}

	public function getRaid(int $id) : ?Raid{
		return $this->raids[$id] ?? null;
	}

	/**
	 * Returns the ongoing raid whose village is around the position, if any.
	 */
	public function getRaidAt(Vector3 $pos, float $range = self::MIN_RAID_DISTANCE) : ?Raid{
		foreach($this->raids as $raid){
			if($raid->isOngoing() && $raid->getCenter()->distanceSquared($pos) <= $range * $range){
				return $raid;
			}
		}
		return null;
	}

	/**
	 * Starts a raid on the village (as if a Raid Omen of the given level ran out there).
	 */
	public function startRaid(Village $village, int $omenLevel = 1) : Raid{
		$raid = new Raid($this->nextId++, $village->getCenter(), $omenLevel, $this->world->getDifficulty(), $village->getVillagerCount() > 0);
		$raid->setBeds($village->getBeds());
		$this->raids[$raid->getId()] = $raid;
		$this->save();
		return $raid;
	}

	/**
	 * Ends every raid of the world at once (bars hidden, raiders back to normal).
	 */
	public function stopAll() : void{
		foreach($this->raids as $raid){
			$raid->finish($this->world);
		}
		$this->raids = [];
		$this->save();
	}

	/**
	 * Called by {@link RaidOmenEffect} when a player's Raid Omen runs out.
	 */
	public function onRaidOmenExpired(Player $player, int $amplifier) : void{
		if($this->enabled){
			$this->expiredOmens[] = [$player, $amplifier];
		}
	}

	/**
	 * Called when a raider taking part in a raid of this world dies.
	 *
	 * @param Raider&Mob $raider
	 */
	public function onRaiderDeath(Raider $raider, ?Player $killer) : void{
		$id = $raider->getRaidId();
		if($id !== null && isset($this->raids[$id])){
			$this->raids[$id]->onRaiderDeath($killer);
		}
	}

	public function tick(int $currentTick) : void{
		if(!$this->enabled){
			return;
		}
		if(count($this->expiredOmens) > 0){
			$expired = $this->expiredOmens;
			$this->expiredOmens = [];
			foreach($expired as [$player, $amplifier]){
				$this->startRaidFromOmen($player, $amplifier);
			}
		}
		if($currentTick % self::OMEN_CHECK_INTERVAL === 0){
			$this->checkBadOmens();
		}
		foreach($this->raids as $id => $raid){
			$wave = $raid->getWave();
			$status = $raid->getStatus();
			$cooldown = $raid->getCooldown();
			if(!$raid->tick($this->world)){
				unset($this->raids[$id]);
				$this->dirty = true;
			}elseif($raid->getWave() !== $wave || $raid->getStatus() !== $status || ($cooldown === -1) !== ($raid->getCooldown() === -1) || $raid->getTicks() % self::SAVE_INTERVAL === 0){
				$this->dirty = true;
			}
		}
		if($this->dirty){
			$this->save();
		}
	}

	/**
	 * Players with Bad Omen in a village get Raid Omen instead (not in peaceful, not if the village is already being
	 * raided).
	 */
	private function checkBadOmens() : void{
		if($this->world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return;
		}
		foreach($this->world->getPlayers() as $player){
			if(!$player->isAlive() || $player->isSpectator()){
				continue;
			}
			$effects = $player->getEffects();
			$badOmen = $effects->get(VanillaEffects::BAD_OMEN());
			if($badOmen === null || $effects->has(VanillaEffects::RAID_OMEN())){
				continue;
			}
			$pos = $player->getPosition();
			$village = VillageScanner::findVillageForOmen($this->world, $pos);
			if($village === null || $this->getRaidAt($village->getCenter()) !== null){
				continue;
			}
			$effects->remove(VanillaEffects::BAD_OMEN());
			$effects->add(new EffectInstance(VanillaEffects::RAID_OMEN(), RaidOmenEffect::DURATION, $badOmen->getAmplifier()));
			$this->omenCenters[$player->getUniqueId()->toString()] = $village->getCenter();
			$this->world->addSound($pos, new RaidOmenSound(), [$player]);
		}
	}

	private function startRaidFromOmen(Player $player, int $amplifier) : void{
		$uuid = $player->getUniqueId()->toString();
		$center = $this->omenCenters[$uuid] ?? $player->getPosition();
		unset($this->omenCenters[$uuid]);
		if(!$player->isConnected() || $player->getWorld() !== $this->world || $this->world->getDifficulty() === World::DIFFICULTY_PEACEFUL){
			return;
		}
		$village = VillageScanner::findVillageAt($this->world, $center) ?? VillageScanner::findVillageAt($this->world, $player->getPosition());
		if($village === null || $this->getRaidAt($village->getCenter()) !== null){
			return;
		}
		$this->startRaid($village, $amplifier + 1);
	}

	public function save() : void{
		$this->dirty = false;
		$data = [];
		foreach($this->raids as $raid){
			$data[] = $raid->toArray();
		}
		$this->store->set($this->world->getFolderName(), $this->nextId, $data);
	}

	/**
	 * Called when the world is unloaded: saves the raids and hides their bars.
	 */
	public function close() : void{
		$this->save();
		foreach($this->raids as $raid){
			$raid->getBossBar()->hideAll();
		}
	}
}
