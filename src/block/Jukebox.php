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

use pocketmine\block\tile\Jukebox as JukeboxTile;
use pocketmine\block\utils\ComparatorReadable;
use pocketmine\block\utils\ItemTransfer;
use pocketmine\entity\animal\Allay;
use pocketmine\entity\animal\Parrot;
use pocketmine\item\Item;
use pocketmine\item\Record;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\RecordSound;
use pocketmine\world\sound\RecordStopSound;

/**
 * A playing jukebox emits a redstone signal of 15 until the track ends; a comparator reads a value per disc for as
 * long as the disc is inside.
 */
class Jukebox extends Opaque implements ComparatorReadable{

	private ?Record $record = null;
	private int $playingUntilTick = 0;

	public function getFuelTime() : int{
		return 300;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){
			if($this->record !== null){
				$this->ejectRecord();
			}elseif($item instanceof Record){
				$player->sendJukeboxPopup(KnownTranslationFactory::record_nowPlaying($item->getRecordType()->getTranslatableName()));
				$this->insertRecord($item->pop());
			}
		}

		$this->position->getWorld()->setBlock($this->position, $this);
		$this->notifyRedstone();
		$this->notifyHoppers();

		return true;
	}

	/**
	 * @internal Puts a disc in (which starts playing) or takes it out (which stops it) for a hopper, dropper or crafter,
	 * without dropping it (vanilla JukeboxBlockEntity.setTheItem()). Does nothing if a disc is put into a full jukebox.
	 */
	public function setRecordFromItemTransfer(?Record $record) : void{
		$world = $this->position->getWorld();
		if($record !== null){
			if($this->record !== null){
				return;
			}
			$this->insertRecord($record);
		}else{
			if($this->record === null){
				return;
			}
			$this->stopSound();
			$this->record = null;
			$this->playingUntilTick = 0;
			Parrot::onJukeboxChange($world, $this->position, false);
		}
		$world->setBlock($this->position, $this);
		$this->notifyRedstone();
	}

	/**
	 * Wakes up the hoppers around, which may now pull the disc or push one in.
	 */
	private function notifyHoppers() : void{
		ItemTransfer::notifyContainerChanged($this->position->getWorld(), $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ());
	}

	public function getRecord() : ?Record{
		return $this->record;
	}

	public function ejectRecord() : void{
		if($this->record !== null){
			$this->position->getWorld()->dropItem($this->position->add(0.5, 1, 0.5), $this->record);
			$this->stopSound();
			$this->record = null;
			$this->playingUntilTick = 0;
			$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, 1);
			Parrot::onJukeboxChange($this->position->getWorld(), $this->position, false);
		}
	}

	public function insertRecord(Record $record) : void{
		if($this->record === null){
			$this->record = $record;
			$world = $this->position->getWorld();
			$this->playingUntilTick = $world->getServer()->getTick() + $record->getRecordType()->getDurationSeconds() * 20;
			$this->startSound();
			$world->scheduleDelayedBlockUpdate($this->position, 1);
			Allay::onJukeboxPlay($world, $this->position);
			Parrot::onJukeboxChange($world, $this->position, true);
		}
	}

	/**
	 * Returns whether the inserted disc is still playing (the jukebox emits redstone power meanwhile).
	 */
	public function isPlaying() : bool{
		return $this->record !== null && $this->playingUntilTick > $this->position->getWorld()->getServer()->getTick();
	}

	public function getComparatorOutput() : int{
		return $this->record?->getRecordType()->getComparatorSignal() ?? 0;
	}

	public function isRedstoneSource() : bool{
		return true;
	}

	public function getWeakPower(int $face) : int{
		return $this->isPlaying() ? 15 : 0;
	}

	private function notifyRedstone() : void{
		$redstone = $this->position->getWorld()->getRedstone();
		if($redstone !== null){
			$x = $this->position->getFloorX();
			$y = $this->position->getFloorY();
			$z = $this->position->getFloorZ();
			$redstone->updateAround($x, $y, $z);
			$redstone->updateComparatorsReading($x, $y, $z);
		}
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$now = $world->getServer()->getTick();
		if($this->playingUntilTick !== 0 && $this->playingUntilTick <= $now){
			$this->playingUntilTick = 0; //the track is over
			$world->setBlock($this->position, $this);
			Parrot::onJukeboxChange($world, $this->position, false);
		}
		$this->notifyRedstone();
		if($this->isPlaying()){
			$world->scheduleDelayedBlockUpdate($this->position, $this->playingUntilTick - $now);
		}
	}

	public function startSound() : void{
		if($this->record !== null){
			$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), new RecordSound($this->record->getRecordType()));
		}
	}

	public function stopSound() : void{
		$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), new RecordStopSound($this->record?->getRecordType()));
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->stopSound();
		$wasPowering = $this->isPlaying();
		$result = parent::onBreak($item, $player, $returnedItems);
		if($wasPowering || $this->record !== null){
			$this->notifyRedstone();
		}
		return $result;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$drops = parent::getDropsForCompatibleTool($item);
		if($this->record !== null){
			$drops[] = $this->record;
		}
		return $drops;
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$jukebox = $this->position->getWorld()->getTile($this->position);
		if($jukebox instanceof JukeboxTile){
			$this->record = $jukebox->getRecord();
			$this->playingUntilTick = $jukebox->getPlayingUntilTick();
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$jukebox = $this->position->getWorld()->getTile($this->position);
		if($jukebox instanceof JukeboxTile){
			$jukebox->setRecord($this->record);
			$jukebox->setPlayingUntilTick($this->playingUntilTick);
		}
	}
}
