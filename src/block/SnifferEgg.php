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

namespace pocketmine\block;

use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\animal\Sniffer;
use pocketmine\entity\Location;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\utils\Utils;
use pocketmine\world\sound\MobSound;
use function mt_rand;

/**
 * Sniffer egg (https://minecraft.wiki/w/Sniffer_Egg). Cracks twice and then hatches into a baby sniffer. Vanilla
 * takes about 20 minutes (10 on moss) in three scheduled steps; here each random tick (one every ~1365 ticks on
 * average) advances a step with a 1 in 6 chance (1 in 3 on moss), which gives about the same time.
 */
class SnifferEgg extends Transparent{
	public const MAX_CRACKS = 2;

	protected int $cracks = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_CRACKS, $this->cracks);
	}

	/** Returns how cracked the egg is: 0 (no cracks), 1 (cracked) or 2 (about to hatch). */
	public function getCracks() : int{ return $this->cracks; }

	/** @return $this */
	public function setCracks(int $cracks) : self{
		if($cracks < 0 || $cracks > self::MAX_CRACKS){
			throw new \InvalidArgumentException("Cracks must be in range 0 ... " . self::MAX_CRACKS);
		}
		$this->cracks = $cracks;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(1 / 16, 0, 2 / 16)];
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$onMoss = $this->getSide(Facing::DOWN)->getTypeId() === BlockTypeIds::MOSS_BLOCK;
		if(mt_rand(0, $onMoss ? 2 : 5) !== 0){
			return;
		}
		$world = $this->position->getWorld();
		if($this->cracks < self::MAX_CRACKS){
			$world->setBlock($this->position, (clone $this)->setCracks($this->cracks + 1));
			$world->addSound($this->position->add(0.5, 0.5, 0.5), new MobSound(LevelSoundEvent::BLOCK_SNIFFER_EGG_CRACK, ""));
			return;
		}
		$world->setBlock($this->position, VanillaBlocks::AIR());
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new MobSound(LevelSoundEvent::BLOCK_SNIFFER_EGG_HATCH, ""));
		$sniffer = new Sniffer(Location::fromObject($this->position->add(0.5, 0, 0.5), $world, Utils::getRandomFloat() * 360, 0));
		$sniffer->setAge(Sniffer::SNIFFLET_AGE);
		$sniffer->setPersistent(true);
		$sniffer->spawnToAll();
	}
}
