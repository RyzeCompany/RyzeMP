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

use pocketmine\nbt\tag\CompoundTag;

/**
 * Block entity of beacons ("Beacon").
 *
 * Saved and sent to the client: "primary" and "secondary" (TAG_Int, Bedrock effect IDs, 0 = none), the same keys as
 * GeyserMC BeaconInventoryTranslator and PowerNukkitX. The Bedrock client works the pyramid level out by itself
 * from the blocks it sees, so the level is not saved; it is recomputed every 80 ticks.
 */
final class Beacon extends Spawnable implements Nameable{
	use NameableTrait{
		addAdditionalSpawnData as addNameSpawnData;
	}

	private const TAG_PRIMARY = "primary"; //TAG_Int
	private const TAG_SECONDARY = "secondary"; //TAG_Int

	private int $primaryEffect = 0;
	private int $secondaryEffect = 0;

	/** Pyramid level found by the last update (0-4); not saved */
	private int $pyramidLevel = 0;
	/** Whether the beam was active (pyramid + sky access) at the last update; not saved */
	private bool $active = false;
	/** Server tick of the last update, or -1 if it never ran since the tile was created or loaded */
	private int $lastUpdateTick = -1;
	/** Loaded from disk: the first update does not play the activation sound (the beam was already on) */
	private bool $loadedFromSave = false;

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->addNameSpawnData($nbt);
		$nbt->setInt(self::TAG_PRIMARY, $this->primaryEffect);
		$nbt->setInt(self::TAG_SECONDARY, $this->secondaryEffect);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		//TODO: PC uses Primary and Secondary (capitalized first letter), we don't read them here because the IDs would be different
		$this->loadName($nbt);
		$this->primaryEffect = $nbt->getInt(self::TAG_PRIMARY, 0);
		$this->secondaryEffect = $nbt->getInt(self::TAG_SECONDARY, 0);
		$this->loadedFromSave = true;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveName($nbt);
		$nbt->setInt(self::TAG_PRIMARY, $this->primaryEffect);
		$nbt->setInt(self::TAG_SECONDARY, $this->secondaryEffect);
	}

	public function getDefaultName() : string{
		return "Beacon";
	}

	public function getPrimaryEffect() : int{ return $this->primaryEffect; }

	public function setPrimaryEffect(int $primaryEffect) : void{ $this->primaryEffect = $primaryEffect; }

	public function getSecondaryEffect() : int{ return $this->secondaryEffect; }

	public function setSecondaryEffect(int $secondaryEffect) : void{ $this->secondaryEffect = $secondaryEffect; }

	public function getPyramidLevel() : int{ return $this->pyramidLevel; }

	public function setPyramidLevel(int $pyramidLevel) : void{ $this->pyramidLevel = $pyramidLevel; }

	public function isActive() : bool{ return $this->active; }

	public function setActive(bool $active) : void{ $this->active = $active; }

	public function getLastUpdateTick() : int{ return $this->lastUpdateTick; }

	public function setLastUpdateTick(int $lastUpdateTick) : void{ $this->lastUpdateTick = $lastUpdateTick; }

	public function isLoadedFromSave() : bool{ return $this->loadedFromSave; }

	public function setLoadedFromSave(bool $loadedFromSave) : void{ $this->loadedFromSave = $loadedFromSave; }
}
