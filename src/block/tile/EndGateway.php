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

use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use function count;

/**
 * Block entity of an End gateway: where it leads. Without an exit, a gateway in the End looks for one on the outer
 * islands the first time it is used (see {@link \pocketmine\world\dimension\EndGatewayTravel}).
 */
class EndGateway extends Spawnable{
	public const TAG_EXIT_PORTAL = "ExitPortal"; //TAG_List<TAG_Int> x, y, z (Bedrock)
	public const TAG_EXACT_TELEPORT = "ExactTeleport"; //TAG_Byte (not in Bedrock: entities arrive next to the exit)
	public const TAG_AGE = "Age"; //TAG_Int, ticks; the client shows the purple beam while it is below 200

	/** Age sent to clients: an old gateway, without the spawn beam. */
	private const SPAWNED_AGE = 200;

	private ?Vector3 $exitPortal = null;
	private bool $exactTeleport = false;

	public function getExitPortal() : ?Vector3{
		return $this->exitPortal?->asVector3();
	}

	/**
	 * Sets where the gateway leads. With $exact the entities arrive at that very block, otherwise on top of the
	 * highest block around it.
	 */
	public function setExitPortal(?Vector3 $exitPortal, bool $exact = false) : void{
		$this->exitPortal = $exitPortal?->floor();
		$this->exactTeleport = $exact;
	}

	public function isExactTeleport() : bool{
		return $this->exactTeleport;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->exitPortal = null;
		$exit = $nbt->getTag(self::TAG_EXIT_PORTAL);
		if($exit instanceof ListTag && $exit->count() === 3){
			$coords = [];
			foreach($exit as $tag){
				if($tag instanceof IntTag){
					$coords[] = $tag->getValue();
				}
			}
			if(count($coords) === 3){
				$this->exitPortal = new Vector3($coords[0], $coords[1], $coords[2]);
			}
		}
		$this->exactTeleport = $nbt->getByte(self::TAG_EXACT_TELEPORT, 0) !== 0;
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->exitPortal !== null){
			$nbt->setTag(self::TAG_EXIT_PORTAL, self::exitTag($this->exitPortal));
			$nbt->setByte(self::TAG_EXACT_TELEPORT, $this->exactTeleport ? 1 : 0);
		}
		$nbt->setInt(self::TAG_AGE, self::SPAWNED_AGE);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$nbt->setInt(self::TAG_AGE, self::SPAWNED_AGE);
		$nbt->setTag(self::TAG_EXIT_PORTAL, self::exitTag($this->exitPortal ?? $this->position));
	}

	/**
	 * @phpstan-return ListTag<IntTag>
	 */
	private static function exitTag(Vector3 $pos) : ListTag{
		return new ListTag([new IntTag($pos->getFloorX()), new IntTag($pos->getFloorY()), new IntTag($pos->getFloorZ())]);
	}
}
