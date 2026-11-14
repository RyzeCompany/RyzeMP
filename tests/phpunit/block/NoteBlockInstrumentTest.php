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

use pocketmine\block\tile\MobHead as TileMobHead;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\utils\MobHeadType;
use pocketmine\block\utils\NoteBlockInstruments;
use pocketmine\data\bedrock\NoteInstrumentIdMap;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\world\redstone\RedstoneTestCase;
use pocketmine\world\sound\NoteInstrument;
use pocketmine\world\sound\NoteSound;

/**
 * Note block instruments, mob heads on note blocks and powered dragon/piglin heads, checked against what BDS 1.26.52
 * sends.
 */
final class NoteBlockInstrumentTest extends RedstoneTestCase{

	private function noteOver(Block $below, ?Block $above = null) : Note{
		$this->set(0, 1, 0, $below);
		$this->set(0, 2, 0, VanillaBlocks::NOTE_BLOCK());
		$this->set(0, 3, 0, $above ?? VanillaBlocks::AIR());
		$note = $this->get(0, 2, 0);
		self::assertInstanceOf(Note::class, $note);
		return $note;
	}

	public function testInstrumentIdsRoundTrip() : void{
		$map = NoteInstrumentIdMap::getInstance();
		$ids = [];
		foreach(NoteInstrument::cases() as $instrument){
			$id = $map->toId($instrument);
			self::assertSame($instrument, $map->fromId($id));
			$ids[$id] = true;
		}
		self::assertCount(26, $ids);
		self::assertSame(16, $map->toId(NoteInstrument::TRUMPET));
		self::assertSame(20, $map->toId(NoteInstrument::ZOMBIE));
		self::assertSame(25, $map->toId(NoteInstrument::PIGLIN));
	}

	public function testInstrumentTable() : void{
		$this->floor(-1, -1, 1, 1);
		foreach([
			[VanillaBlocks::STONE(), NoteInstrument::BASS_DRUM],
			[VanillaBlocks::OAK_PLANKS(), NoteInstrument::DOUBLE_BASS],
			[VanillaBlocks::IRON(), NoteInstrument::IRON_XYLOPHONE],
			[VanillaBlocks::GOLD(), NoteInstrument::BELL],
			[VanillaBlocks::EMERALD(), NoteInstrument::BIT],
			//metal blocks other than iron, gold and emerald play the harp in Bedrock
			[VanillaBlocks::DIAMOND(), NoteInstrument::PIANO],
			[VanillaBlocks::NETHERITE(), NoteInstrument::PIANO],
			[VanillaBlocks::LAPIS_LAZULI(), NoteInstrument::PIANO],
			[VanillaBlocks::REDSTONE(), NoteInstrument::PIANO],
			[VanillaBlocks::IRON_BARS(), NoteInstrument::PIANO],
			[VanillaBlocks::RAW_IRON(), NoteInstrument::BASS_DRUM],
			[VanillaBlocks::AMETHYST(), NoteInstrument::BASS_DRUM],
			[VanillaBlocks::BEACON(), NoteInstrument::CLICKS_AND_STICKS],
			[VanillaBlocks::TINTED_GLASS(), NoteInstrument::CLICKS_AND_STICKS],
			[VanillaBlocks::SEA_LANTERN(), NoteInstrument::CLICKS_AND_STICKS],
			[VanillaBlocks::SAND(), NoteInstrument::SNARE],
			[VanillaBlocks::HONEYCOMB(), NoteInstrument::FLUTE],
			[VanillaBlocks::PACKED_ICE(), NoteInstrument::CHIME],
			[VanillaBlocks::BLUE_ICE(), NoteInstrument::PIANO],
			[VanillaBlocks::CARVED_PUMPKIN(), NoteInstrument::PIANO],
			[VanillaBlocks::PUMPKIN(), NoteInstrument::DIDGERIDOO],
		] as [$below, $expected]){
			self::assertSame($expected, $this->noteOver($below)->getInstrument(), $below->getName());
		}
	}

	public function testCopperPlaysTrumpetsByOxidation() : void{
		$this->floor(-1, -1, 1, 1);
		foreach([
			[CopperOxidation::NONE, NoteInstrument::TRUMPET],
			[CopperOxidation::EXPOSED, NoteInstrument::TRUMPET_EXPOSED],
			[CopperOxidation::WEATHERED, NoteInstrument::TRUMPET_WEATHERED],
			[CopperOxidation::OXIDIZED, NoteInstrument::TRUMPET_OXIDIZED],
		] as [$oxidation, $expected]){
			self::assertSame($expected, $this->noteOver(VanillaBlocks::COPPER()->setOxidation($oxidation)->setWaxed(true))->getInstrument());
			self::assertSame($expected, $this->noteOver(VanillaBlocks::CUT_COPPER_STAIRS()->setOxidation($oxidation))->getInstrument());
		}
		self::assertSame(NoteInstrument::PIANO, $this->noteOver(VanillaBlocks::COPPER_GRATE())->getInstrument(), "grates aren't trumpets");
		self::assertSame(NoteInstrument::BASS_DRUM, $this->noteOver(VanillaBlocks::COPPER_ORE())->getInstrument());
	}

	public function testMobHeadOnTopPlaysItsSound() : void{
		$this->floor(-1, -1, 1, 1);
		$head = VanillaBlocks::MOB_HEAD()->setFacing(1);
		$note = $this->noteOver(VanillaBlocks::GOLD(), (clone $head)->setMobHeadType(MobHeadType::ZOMBIE));
		self::assertSame(NoteInstrument::ZOMBIE, $note->getInstrument(), "the head wins over the block below");
		self::assertTrue($note->play());

		self::assertSame(NoteInstrument::ENDER_DRAGON, $this->noteOver(VanillaBlocks::STONE(), (clone $head)->setMobHeadType(MobHeadType::DRAGON))->getInstrument());
		self::assertSame(NoteInstrument::PIGLIN, $this->noteOver(VanillaBlocks::STONE(), (clone $head)->setMobHeadType(MobHeadType::PIGLIN))->getInstrument());

		$note = $this->noteOver(VanillaBlocks::STONE(), (clone $head)->setMobHeadType(MobHeadType::PLAYER));
		self::assertFalse($note->play(), "player heads cover the note block");
		self::assertSame(NoteInstrument::BASS_DRUM, $note->getInstrument());
		self::assertFalse($this->noteOver(VanillaBlocks::STONE(), VanillaBlocks::STONE())->play());
		self::assertTrue($this->noteOver(VanillaBlocks::STONE())->play());
	}

	public function testHeadSoundUsesFixedNote() : void{
		$packets = (new NoteSound(NoteInstrument::ZOMBIE, NoteBlockInstruments::HEAD_NOTE))->encode(new Vector3(0, 0, 0));
		self::assertCount(1, $packets);
		$packet = $packets[0];
		self::assertInstanceOf(LevelSoundEventPacket::class, $packet);
		self::assertSame(5129, $packet->extraData, "what BDS sends for a zombie head on a note block");
	}

	private function headTile(int $x, int $y, int $z) : TileMobHead{
		$tile = $this->world->getTile(new Vector3($x, $y, $z));
		self::assertInstanceOf(TileMobHead::class, $tile);
		return $tile;
	}

	public function testPoweredDragonHeadMovesItsMouth() : void{
		$this->floor(-3, -3, 3, 3);
		$this->set(0, 1, 0, VanillaBlocks::MOB_HEAD()->setFacing(1)->setMobHeadType(MobHeadType::DRAGON));
		$this->set(2, 1, 0, VanillaBlocks::MOB_HEAD()->setFacing(1)->setMobHeadType(MobHeadType::ZOMBIE));
		self::assertFalse($this->headTile(0, 1, 0)->isDoingAnimation());

		$this->set(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		$dragon = $this->headTile(0, 1, 0);
		self::assertTrue($dragon->isDoingAnimation());
		self::assertSame(1, $dragon->getSpawnCompound()->getByte("DoingAnimation"));
		self::assertFalse($this->headTile(2, 1, 0)->isDoingAnimation(), "only dragon and piglin heads animate");

		$this->runTicks(20);
		$this->set(1, 1, 0, VanillaBlocks::AIR());
		$this->runTicks(2);
		$dragon = $this->headTile(0, 1, 0);
		self::assertFalse($dragon->isDoingAnimation());
		$nbt = $dragon->getSpawnCompound();
		self::assertSame(0, $nbt->getByte("DoingAnimation"));
		self::assertGreaterThanOrEqual(20, $nbt->getInt("MouthTickCount"), "the ticks spent animating are kept, like BDS");
	}
}
