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

namespace pocketmine\block\tile;

use pocketmine\block\trialspawner\TrialSpawnerConfig;
use pocketmine\block\trialspawner\TrialSpawnerConfigs;
use pocketmine\block\trialspawner\TrialSpawnerData;
use pocketmine\block\trialspawner\TrialSpawnerLogic;
use pocketmine\block\trialspawner\TrialSpawnerSpawnData;
use pocketmine\block\trialspawner\WorldTrialSpawnerHost;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\world\World;
use function max;
use function min;

/**
 * Block entity of a trial spawner: its normal and ominous configs (by name, like those of trial chambers, or stored
 * inline), its range and cooldown, and the state of the current fight ({@link TrialSpawnerData}). The spawner block
 * runs it (see {@link \pocketmine\block\TrialSpawner}).
 */
class TrialSpawner extends Spawnable{
	public const TAG_NORMAL_CONFIG = "normal_config"; //TAG_String (config name) or TAG_Compound (inline config)
	public const TAG_OMINOUS_CONFIG = "ominous_config"; //TAG_String or TAG_Compound
	public const TAG_REQUIRED_PLAYER_RANGE = "required_player_range"; //TAG_Int
	public const TAG_TARGET_COOLDOWN_LENGTH = "target_cooldown_length"; //TAG_Int

	private ?string $normalConfigName = null;
	private ?TrialSpawnerConfig $normalConfig = null;
	private ?string $ominousConfigName = null;
	private ?TrialSpawnerConfig $ominousConfig = null;
	private int $requiredPlayerRange = TrialSpawnerLogic::DEFAULT_PLAYER_SCAN_RANGE;
	private int $targetCooldownLength = TrialSpawnerLogic::DEFAULT_TARGET_COOLDOWN_LENGTH;
	private TrialSpawnerData $data;
	private int $lastUpdateTick = -1;
	private ?WorldTrialSpawnerHost $host = null;

	public function __construct(World $world, Vector3 $pos){
		$this->data = new TrialSpawnerData();
		parent::__construct($world, $pos);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		[$this->normalConfigName, $this->normalConfig] = self::readConfig($nbt, self::TAG_NORMAL_CONFIG);
		[$this->ominousConfigName, $this->ominousConfig] = self::readConfig($nbt, self::TAG_OMINOUS_CONFIG);
		$this->requiredPlayerRange = max(1, min(128, $nbt->getInt(self::TAG_REQUIRED_PLAYER_RANGE, TrialSpawnerLogic::DEFAULT_PLAYER_SCAN_RANGE)));
		$this->targetCooldownLength = max(0, $nbt->getInt(self::TAG_TARGET_COOLDOWN_LENGTH, TrialSpawnerLogic::DEFAULT_TARGET_COOLDOWN_LENGTH));
		$this->data->readSaveData($nbt, $this->position->getWorld()->getServer()->getTick());
	}

	/**
	 * @phpstan-return array{?string, ?TrialSpawnerConfig}
	 */
	private static function readConfig(CompoundTag $nbt, string $tagName) : array{
		$tag = $nbt->getTag($tagName);
		if($tag instanceof StringTag && $tag->getValue() !== ""){
			return [$tag->getValue(), null];
		}
		if($tag instanceof CompoundTag){
			return [null, TrialSpawnerConfig::fromNbt($tag)];
		}
		return [null, null];
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		self::writeConfig($nbt, self::TAG_NORMAL_CONFIG, $this->normalConfigName, $this->normalConfig);
		self::writeConfig($nbt, self::TAG_OMINOUS_CONFIG, $this->ominousConfigName, $this->ominousConfig);
		$nbt->setInt(self::TAG_REQUIRED_PLAYER_RANGE, $this->requiredPlayerRange);
		$nbt->setInt(self::TAG_TARGET_COOLDOWN_LENGTH, $this->targetCooldownLength);
		$this->data->writeSaveData($nbt, $this->position->getWorld()->getServer()->getTick());
	}

	private static function writeConfig(CompoundTag $nbt, string $tagName, ?string $name, ?TrialSpawnerConfig $config) : void{
		if($name !== null){
			$nbt->setString($tagName, $name);
		}elseif($config !== null){
			$nbt->setTag($tagName, $config->toNbt());
		}
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		//the mob turning inside the spawner
		$spawnData = $this->data->nextSpawnData;
		$entityId = $spawnData?->getEntityId();
		if($spawnData !== null && $entityId !== null){
			$nbt->setTag(TrialSpawnerData::TAG_SPAWN_DATA, $spawnData->toNbt()->setString("TypeId", $entityId));
		}
	}

	/**
	 * Name of the normal config ("minecraft:trial_chamber/breeze/normal"), or null if the config is stored inline or
	 * the spawner has none.
	 */
	public function getNormalConfigName() : ?string{ return $this->normalConfigName; }

	public function getOminousConfigName() : ?string{ return $this->ominousConfigName; }

	/**
	 * Sets the configs by name (see {@link TrialSpawnerConfigs}); null for none.
	 */
	public function setConfigNames(?string $normal, ?string $ominous) : void{
		$this->normalConfigName = $normal;
		$this->normalConfig = null;
		$this->ominousConfigName = $ominous;
		$this->ominousConfig = null;
		$this->resetFight();
	}

	public function getNormalConfig() : TrialSpawnerConfig{
		return $this->normalConfig ??= ($this->normalConfigName !== null ? TrialSpawnerConfigs::get($this->normalConfigName) : null) ?? TrialSpawnerConfigs::getDefault();
	}

	public function getOminousConfig() : TrialSpawnerConfig{
		return $this->ominousConfig ??= ($this->ominousConfigName !== null ? TrialSpawnerConfigs::get($this->ominousConfigName) : null) ?? TrialSpawnerConfigs::getDefault();
	}

	/**
	 * Makes the spawner spawn the given mob ("minecraft:zombie") in both modes, like a spawn egg used on it. The
	 * fight is reset.
	 */
	public function setEntityToSpawn(string $entityId) : void{
		$this->normalConfig = $this->getNormalConfig()->withSpawning($entityId);
		$this->ominousConfig = $this->getOminousConfig()->withSpawning($entityId);
		$this->normalConfigName = null;
		$this->ominousConfigName = null;
		$this->resetFight();
	}

	private function resetFight() : void{
		$this->data->reset();
		$this->clearSpawnCompoundCache();
	}

	/**
	 * The mob the spawner spawns next and shows, if it was picked already.
	 */
	public function getNextSpawnData() : ?TrialSpawnerSpawnData{
		return $this->data->nextSpawnData;
	}

	public function getRequiredPlayerRange() : int{ return $this->requiredPlayerRange; }

	public function setRequiredPlayerRange(int $range) : void{ $this->requiredPlayerRange = max(1, min(128, $range)); }

	/**
	 * Ticks between the end of a fight and the next one (vanilla: 36000, 30 minutes).
	 */
	public function getTargetCooldownLength() : int{ return $this->targetCooldownLength; }

	public function setTargetCooldownLength(int $ticks) : void{ $this->targetCooldownLength = max(0, $ticks); }

	/**
	 * @internal
	 */
	public function getData() : TrialSpawnerData{
		return $this->data;
	}

	/**
	 * @internal
	 */
	public function getHost() : WorldTrialSpawnerHost{
		return $this->host ??= new WorldTrialSpawnerHost($this);
	}

	/**
	 * Server tick of the last spawner update, to keep a single update chain (not saved).
	 */
	public function getLastUpdateTick() : int{ return $this->lastUpdateTick; }

	public function setLastUpdateTick(int $tick) : void{ $this->lastUpdateTick = $tick; }

	/**
	 * Sends the mob shown in the spawner to the players who can see it.
	 */
	public function broadcastSpawnData() : void{
		$this->clearSpawnCompoundCache();
		$this->position->getWorld()->broadcastPacketToViewers($this->position, BlockActorDataPacket::create(
			BlockPosition::fromVector3($this->position),
			$this->getSerializedSpawnCompound()
		));
	}
}
