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

use pocketmine\block\tile\Note as TileNote;
use pocketmine\block\utils\NoteBlockInstruments;
use pocketmine\entity\animal\Allay;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\NoteInstrument;
use pocketmine\world\sound\NoteSound;
use pocketmine\world\vibration\GameEvent;
use function assert;

class Note extends Opaque{
	public const MIN_PITCH = 0;
	public const MAX_PITCH = 24;

	private int $pitch = self::MIN_PITCH;

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileNote){
			$this->pitch = $tile->getPitch();
		}else{
			$this->pitch = self::MIN_PITCH;
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileNote);
		$tile->setPitch($this->pitch);
	}

	public function getFuelTime() : int{
		return 300;
	}

	public function getPitch() : int{
		return $this->pitch;
	}

	/** @return $this */
	public function setPitch(int $pitch) : self{
		if($pitch < self::MIN_PITCH || $pitch > self::MAX_PITCH){
			throw new \InvalidArgumentException("Pitch must be in range " . self::MIN_PITCH . " - " . self::MAX_PITCH);
		}
		$this->pitch = $pitch;
		return $this;
	}

	/**
	 * Returns the instrument played: the sound of the mob head on top of the note block if there is one, otherwise the
	 * instrument picked by the block below.
	 */
	public function getInstrument() : NoteInstrument{
		return $this->getHeadInstrument($this->getSide(Facing::UP)) ?? $this->getInstrumentBelow();
	}

	private function getHeadInstrument(Block $above) : ?NoteInstrument{
		return $above instanceof MobHead ? NoteBlockInstruments::fromHead($above->getMobHeadType()) : null;
	}

	private function getInstrumentBelow() : NoteInstrument{
		$below = $this->getSide(Facing::DOWN);
		return NoteBlockInstruments::fromBlockBelow($below) ?? self::guessInstrument($below);
	}

	/**
	 * Instrument for block types the BDS table doesn't know (custom blocks...).
	 */
	private static function guessInstrument(Block $below) : NoteInstrument{
		return match(true){
			$below instanceof Wool => NoteInstrument::GUITAR,
			$below instanceof Clay => NoteInstrument::FLUTE,
			$below instanceof PackedIce => NoteInstrument::CHIME,
			$below instanceof BoneBlock => NoteInstrument::XYLOPHONE,
			$below instanceof SoulSand => NoteInstrument::COW_BELL,
			$below instanceof Pumpkin => NoteInstrument::DIDGERIDOO,
			$below instanceof HayBale => NoteInstrument::BANJO,
			$below instanceof Glowstone => NoteInstrument::PLING,
			$below instanceof Sand, $below instanceof Gravel, $below instanceof ConcretePowder => NoteInstrument::SNARE,
			$below instanceof Glass, $below instanceof GlassPane, $below instanceof SeaLantern => NoteInstrument::CLICKS_AND_STICKS,
			$below->hasSameTypeId(VanillaBlocks::GOLD()) => NoteInstrument::BELL,
			$below->hasSameTypeId(VanillaBlocks::IRON()) => NoteInstrument::IRON_XYLOPHONE,
			$below->hasSameTypeId(VanillaBlocks::EMERALD()) => NoteInstrument::BIT,
			($below->getBreakInfo()->getToolType() & BlockToolType::AXE) !== 0 => NoteInstrument::DOUBLE_BASS,
			($below->getBreakInfo()->getToolType() & BlockToolType::PICKAXE) !== 0 => NoteInstrument::BASS_DRUM,
			default => NoteInstrument::PIANO,
		};
	}

	/**
	 * Plays the note, unless the note block is covered. A mob head on top plays its own sound instead, at a fixed note.
	 */
	public function play() : bool{
		$above = $this->getSide(Facing::UP);
		$headInstrument = $this->getHeadInstrument($above);
		if($headInstrument !== null){
			$sound = new NoteSound($headInstrument, NoteBlockInstruments::HEAD_NOTE);
		}elseif($above->getTypeId() === BlockTypeIds::AIR){
			$sound = new NoteSound($this->getInstrumentBelow(), $this->pitch);
		}else{
			return false;
		}
		$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), $sound);
		$this->position->getWorld()->addGameEvent($this->position, GameEvent::NOTE_BLOCK_PLAY);
		Allay::onNoteBlockPlayed($this->position->getWorld(), $this->position);
		return true;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->pitch = $this->pitch >= self::MAX_PITCH ? self::MIN_PITCH : $this->pitch + 1;
		$this->position->getWorld()->setBlock($this->position, $this);
		$this->play();
		return true;
	}

	public function onAttack(Item $item, int $face, ?Player $player = null) : bool{
		$this->play();
		return false;
	}

	public function onNearbyBlockChange() : void{
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone === null){
			return;
		}
		$x = $this->position->getFloorX();
		$y = $this->position->getFloorY();
		$z = $this->position->getFloorZ();
		$powered = $redstone->isPowered($x, $y, $z);
		if($powered !== $redstone->getRememberedPowered($x, $y, $z)){
			$redstone->setRememberedPowered($x, $y, $z, $powered);
			if($powered){
				$this->play();
			}
		}
	}
}
