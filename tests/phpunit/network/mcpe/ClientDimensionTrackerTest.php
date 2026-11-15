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

namespace pocketmine\network\mcpe;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\format\Chunk;

class ClientDimensionTrackerTest extends TestCase{

	/**
	 * Tracker of a client that spawned in the given dimension and received terrain there.
	 */
	private static function spawnedIn(Dimension $dimension, bool $clear = true) : ClientDimensionTracker{
		$tracker = new ClientDimensionTracker($clear);
		$tracker->onStartGame($dimension);
		$tracker->onTerrainSent();
		return $tracker;
	}

	private static function assertChange(?ClientDimensionChange $change, Dimension $dimension, bool $detour, bool $respawn = false) : ClientDimensionChange{
		self::assertNotNull($change, "expected a ChangeDimension to " . $dimension->name);
		self::assertSame($dimension, $change->dimension);
		self::assertSame($detour, $change->detour, "detour flag");
		self::assertSame($respawn, $change->respawn, "respawn flag");
		self::assertSame($respawn, $change->loadingScreenId === null, "loading screen ID only while alive");
		return $change;
	}

	public function testNothingBeforeStartGame() : void{
		$tracker = new ClientDimensionTracker();
		self::assertNull($tracker->onEnterWorld(Dimension::NETHER, false, true));
		self::assertNull($tracker->getClientDimension());
	}

	public function testOtherDimensionIsASingleChange() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		$change = self::assertChange($tracker->onEnterWorld(Dimension::NETHER, false, true), Dimension::NETHER, false);
		self::assertTrue($tracker->isChanging());
		self::assertFalse($tracker->holdsChunks(), "the new dimension's chunks end the loading screen");
		self::assertSame(Dimension::NETHER, $tracker->getClientDimension());

		self::assertNull($tracker->onClientChangeDone($change->loadingScreenId, false, true));
		self::assertFalse($tracker->isChanging());
	}

	public function testSameDimensionDetoursThroughAnotherDimension() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		$detour = self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		self::assertTrue($tracker->isChanging());
		self::assertTrue($tracker->isDetour());
		self::assertTrue($tracker->holdsChunks(), "no world chunk may reach the client in the detour dimension");

		//the client acknowledges the detour: back to the Overworld, world chunks flow again
		$back = self::assertChange($tracker->onClientChangeDone(null, false, true), Dimension::OVERWORLD, false);
		self::assertNotSame($detour->loadingScreenId, $back->loadingScreenId);
		self::assertTrue($tracker->isChanging());
		self::assertFalse($tracker->isDetour());
		self::assertFalse($tracker->holdsChunks());

		//late end of the detour's loading screen is not the end of the change back
		self::assertNull($tracker->onClientChangeDone($detour->loadingScreenId, false, true));
		self::assertTrue($tracker->isChanging());

		$tracker->onTerrainSent();
		self::assertNull($tracker->onClientChangeDone($back->loadingScreenId, false, true));
		self::assertFalse($tracker->isChanging());
		self::assertSame(Dimension::OVERWORLD, $tracker->getClientDimension());
	}

	public function testLateAckAfterLoadingScreenEndIsIgnored() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		$detour = self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		//the loading screen end arrives before the client's ack this time
		$back = self::assertChange($tracker->onClientChangeDone($detour->loadingScreenId, false, true), Dimension::OVERWORLD, false);
		//the detour's ack must not end the change back
		self::assertNull($tracker->onClientChangeDone(null, false, true));
		self::assertTrue($tracker->isChanging());
		//the change back's own ack does
		self::assertNull($tracker->onClientChangeDone(null, false, true));
		self::assertFalse($tracker->isChanging());
		self::assertNotNull($back->loadingScreenId);
	}

	public function testDetourDimensions() : void{
		self::assertSame(Dimension::NETHER, ClientDimensionTracker::getDetourDimension(Dimension::OVERWORLD));
		self::assertSame(Dimension::OVERWORLD, ClientDimensionTracker::getDetourDimension(Dimension::NETHER));
		self::assertSame(Dimension::OVERWORLD, ClientDimensionTracker::getDetourDimension(Dimension::END));

		$tracker = self::spawnedIn(Dimension::NETHER);
		self::assertChange($tracker->onEnterWorld(Dimension::NETHER, false, true), Dimension::OVERWORLD, true);
		self::assertChange($tracker->onClientChangeDone(null, false, true), Dimension::NETHER, false);
	}

	public function testSameDimensionWithoutTerrainSendsNothing() : void{
		$tracker = new ClientDimensionTracker();
		$tracker->onStartGame(Dimension::OVERWORLD);
		self::assertNull($tracker->onEnterWorld(Dimension::OVERWORLD, false, true));
		self::assertFalse($tracker->isChanging());
		self::assertFalse($tracker->holdsChunks());
	}

	public function testDisabledKeepsThePlainSwitch() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD, false);
		self::assertNull($tracker->onEnterWorld(Dimension::OVERWORLD, false, true));
		self::assertFalse($tracker->isChanging());

		$tracker->setClearSameDimensionTerrain(true);
		self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
	}

	public function testNoDetourBeforeSpawn() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		self::assertNull($tracker->onEnterWorld(Dimension::OVERWORLD, false, false));
		self::assertFalse($tracker->isChanging());
		//a different dimension still changes, as before
		self::assertChange($tracker->onEnterWorld(Dimension::END, false, false), Dimension::END, false);
	}

	public function testDeadPlayerDetourHasNoLoadingScreen() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, true, true), Dimension::NETHER, true, true);
		//respawned by the time the client acknowledges the detour
		$back = self::assertChange($tracker->onClientChangeDone(null, false, true), Dimension::OVERWORLD, false);
		self::assertNull($tracker->onClientChangeDone($back->loadingScreenId, false, true));
		self::assertFalse($tracker->isChanging());
	}

	public function testLoadingScreenEndThenRespawnChangeStillEndsOnAck() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		$detour = self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		//died during the detour: the change back has no loading screen, so its only end signal is the ack
		self::assertChange($tracker->onClientChangeDone($detour->loadingScreenId, true, true), Dimension::OVERWORLD, false, true);
		self::assertNull($tracker->onClientChangeDone(null, true, true));
		self::assertFalse($tracker->isChanging());
	}

	public function testSwitchDuringDetourUsesTheLatestWorld() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		//third world, this time in the End
		self::assertNull($tracker->onEnterWorld(Dimension::END, false, true));
		self::assertTrue($tracker->holdsChunks());
		self::assertChange($tracker->onClientChangeDone(null, false, true), Dimension::END, false);
		self::assertFalse($tracker->holdsChunks());
	}

	public function testSwitchDuringDetourToTheDetourDimension() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		self::assertNull($tracker->onEnterWorld(Dimension::NETHER, false, true));
		//the client is already in the Nether with empty terrain only: nothing else to send
		self::assertNull($tracker->onClientChangeDone(null, false, true));
		self::assertFalse($tracker->isChanging());
		self::assertFalse($tracker->holdsChunks());
		self::assertSame(Dimension::NETHER, $tracker->getClientDimension());
	}

	public function testSameDimensionSwitchDuringTheChangeBack() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		$back = self::assertChange($tracker->onClientChangeDone(null, false, true), Dimension::OVERWORLD, false);
		$tracker->onTerrainSent(); //chunks of the second world arrived
		//third Overworld world before the client finished loading the second one
		self::assertNull($tracker->onEnterWorld(Dimension::OVERWORLD, false, true));
		self::assertTrue($tracker->isChanging());
		self::assertTrue($tracker->holdsChunks(), "chunks of the third world must wait");
		$detour = self::assertChange($tracker->onClientChangeDone($back->loadingScreenId, false, true), Dimension::NETHER, true);
		self::assertNull($tracker->onClientChangeDone(null, false, true), "late ack of the change back");
		self::assertChange($tracker->onClientChangeDone($detour->loadingScreenId, false, true), Dimension::OVERWORLD, false);
	}

	public function testSwitchDuringChangeBeforeAnyChunkNeedsNoDetour() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		$change = self::assertChange($tracker->onEnterWorld(Dimension::NETHER, false, true), Dimension::NETHER, false);
		//no Nether chunk sent yet, and the player goes to another Nether world
		self::assertNull($tracker->onEnterWorld(Dimension::NETHER, false, true));
		self::assertNull($tracker->onClientChangeDone($change->loadingScreenId, false, true));
		self::assertFalse($tracker->isChanging());
		self::assertFalse($tracker->holdsChunks());
	}

	public function testOtherDimensionSwitchDuringChange() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		$first = self::assertChange($tracker->onEnterWorld(Dimension::NETHER, false, true), Dimension::NETHER, false);
		//unchanged behaviour: another dimension is sent right away
		$second = self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::OVERWORLD, false);
		self::assertFalse($tracker->holdsChunks());
		self::assertNull($tracker->onClientChangeDone($first->loadingScreenId, false, true), "end of the first loading screen");
		self::assertTrue($tracker->isChanging());
		self::assertNull($tracker->onClientChangeDone($second->loadingScreenId, false, true));
		self::assertFalse($tracker->isChanging());
	}

	public function testOtherDimensionSwitchDuringDetourWaits() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		self::assertNull($tracker->onEnterWorld(Dimension::END, false, true), "never during the detour");
		self::assertChange($tracker->onClientChangeDone(null, false, true), Dimension::END, false);
	}

	public function testForcedEndContinuesTheSwitch() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		self::assertChange($tracker->forceChangeDone(false, true), Dimension::OVERWORLD, false);
		self::assertNull($tracker->forceChangeDone(false, true));
		self::assertFalse($tracker->isChanging());
		self::assertNull($tracker->forceChangeDone(false, true));
	}

	public function testStartGameResetsState() : void{
		$tracker = self::spawnedIn(Dimension::OVERWORLD);
		self::assertChange($tracker->onEnterWorld(Dimension::OVERWORLD, false, true), Dimension::NETHER, true);
		$tracker->onStartGame(Dimension::END);
		self::assertFalse($tracker->isChanging());
		self::assertFalse($tracker->holdsChunks());
		self::assertSame(Dimension::END, $tracker->getClientDimension());
	}

	/**
	 * The detour sends empty chunks of the detour dimension; they must serialize for every dimension.
	 */
	public function testEmptyChunkSerializesInEveryDimension() : void{
		$translator = TypeConverter::getInstance()->getBlockTranslator();
		foreach(Dimension::cases() as $dimension){
			$chunk = new Chunk([], false);
			self::assertSame(0, ChunkSerializer::getSubChunkCount($chunk, $dimension->getNetworkId()));
			self::assertNotSame("", ChunkSerializer::serializeFullChunk($chunk, $dimension->getNetworkId(), $translator));
		}
	}
}
