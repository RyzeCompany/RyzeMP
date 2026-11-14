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

use pocketmine\block\tile\Conduit as TileConduit;
use pocketmine\entity\Location;
use pocketmine\entity\Zombie;
use pocketmine\math\Vector3;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\redstone\RedstoneTestCase;
use function abs;

/**
 * Conduit frame detection, activation state and attacks. Uses the in-memory world of the redstone tests; the world
 * is not ticked, so the water doesn't flow.
 */
final class ConduitTest extends RedstoneTestCase{
	private const X = 0;
	private const Y = 20;
	private const Z = 0;

	/**
	 * @return Vector3[] the 42 frame positions around the conduit
	 * @phpstan-return list<Vector3>
	 */
	private static function framePositions() : array{
		$result = [];
		for($ox = -2; $ox <= 2; ++$ox){
			for($oy = -2; $oy <= 2; ++$oy){
				for($oz = -2; $oz <= 2; ++$oz){
					$ax = abs($ox);
					$ay = abs($oy);
					$az = abs($oz);
					if($ax <= 1 && $ay <= 1 && $az <= 1){
						continue;
					}
					if(($ox === 0 && ($ay === 2 || $az === 2)) || ($oy === 0 && ($ax === 2 || $az === 2)) || ($oz === 0 && ($ax === 2 || $ay === 2))){
						$result[] = new Vector3(self::X + $ox, self::Y + $oy, self::Z + $oz);
					}
				}
			}
		}
		return $result;
	}

	private function fillWater() : void{
		for($ox = -1; $ox <= 1; ++$ox){
			for($oy = -1; $oy <= 1; ++$oy){
				for($oz = -1; $oz <= 1; ++$oz){
					if($ox !== 0 || $oy !== 0 || $oz !== 0){
						$this->set(self::X + $ox, self::Y + $oy, self::Z + $oz, VanillaBlocks::WATER());
					}
				}
			}
		}
	}

	private function buildFrame(int $blocks) : void{
		$types = [VanillaBlocks::PRISMARINE(), VanillaBlocks::PRISMARINE_BRICKS(), VanillaBlocks::DARK_PRISMARINE(), VanillaBlocks::SEA_LANTERN()];
		foreach(self::framePositions() as $i => $pos){
			if($i >= $blocks){
				break;
			}
			$this->set($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $types[$i % 4]);
		}
	}

	private function placeConduit(bool $waterlogged = true) : Conduit{
		$this->set(self::X, self::Y, self::Z, VanillaBlocks::CONDUIT()->setWaterlogged($waterlogged));
		$conduit = $this->get(self::X, self::Y, self::Z);
		self::assertInstanceOf(Conduit::class, $conduit);
		return $conduit;
	}

	private function tile() : TileConduit{
		$tile = $this->world->getTileAt(self::X, self::Y, self::Z);
		self::assertInstanceOf(TileConduit::class, $tile);
		return $tile;
	}

	public function testFrameHas42Positions() : void{
		self::assertCount(Conduit::MIN_ATTACK_FRAME_BLOCKS, self::framePositions());
	}

	public function testWaterloggedFlagLivesInTheLiquidLayer() : void{
		$conduit = $this->placeConduit();
		self::assertTrue($conduit->isWaterlogged(), "read back from the liquid layer");
		$chunk = $this->world->getChunk(self::X >> Chunk::COORD_BIT_SIZE, self::Z >> Chunk::COORD_BIT_SIZE);
		self::assertNotNull($chunk);
		$liquid = $chunk->getSubChunk(self::Y >> SubChunk::COORD_BIT_SIZE)->getLiquidLayerStateId(self::X & 15, self::Y & 15, self::Z & 15);
		self::assertInstanceOf(Water::class, RuntimeBlockStateRegistry::getInstance()->fromStateId($liquid));

		$dry = $this->placeConduit(false);
		self::assertFalse($dry->isWaterlogged());
	}

	public function testCountsFrameBlocksOnlyUnderwater() : void{
		$this->buildFrame(42);
		$this->placeConduit();
		self::assertSame(0, Conduit::countFrameBlocks($this->world, self::X, self::Y, self::Z), "no water around");
		$this->fillWater();
		self::assertSame(42, Conduit::countFrameBlocks($this->world, self::X, self::Y, self::Z));

		$this->placeConduit(false);
		self::assertSame(0, Conduit::countFrameBlocks($this->world, self::X, self::Y, self::Z), "the conduit itself must be waterlogged");
	}

	public function testEffectRange() : void{
		self::assertSame(32, Conduit::getEffectRange(16));
		self::assertSame(80, Conduit::getEffectRange(35));
		self::assertSame(96, Conduit::getEffectRange(42));
	}

	public function testActivation() : void{
		$this->fillWater();
		$this->buildFrame(15);
		$conduit = $this->placeConduit();
		$conduit->updateConduit($this->tile());
		self::assertFalse($this->tile()->isActive(), "15 frame blocks are not enough");

		$this->buildFrame(16);
		$conduit->updateConduit($this->tile());
		self::assertTrue($this->tile()->isActive());
		self::assertSame(16, $this->tile()->getFrameBlocks());
		self::assertSame(1, $this->tile()->getSpawnCompound()->getByte("Active"));

		//a frame block removed: inactive again
		$first = self::framePositions()[0];
		$this->set($first->getFloorX(), $first->getFloorY(), $first->getFloorZ(), VanillaBlocks::WATER());
		$conduit->updateConduit($this->tile());
		self::assertFalse($this->tile()->isActive());
	}

	public function testScheduledUpdateActivates() : void{
		$this->fillWater();
		$this->buildFrame(20);
		$this->placeConduit();
		$this->runTicks(2);
		self::assertTrue($this->tile()->isActive(), "the tile schedules the first update");
	}

	public function testAttacksHostileMobsInWaterWithTheFullFrame() : void{
		$this->fillWater();
		$this->buildFrame(41);
		$conduit = $this->placeConduit();
		$zombie = new Zombie(Location::fromObject(new Vector3(self::X + 1.5, self::Y - 1, self::Z + 0.5), $this->world));
		$health = $zombie->getHealth();

		$conduit->updateConduit($this->tile());
		self::assertTrue($this->tile()->isActive());
		self::assertSame(-1, $this->tile()->getTargetId(), "no attacks without the full frame");
		self::assertSame($health, $zombie->getHealth());

		$this->buildFrame(42);
		$conduit->updateConduit($this->tile());
		self::assertSame($zombie->getId(), $this->tile()->getTargetId());
		self::assertSame($zombie->getId(), $this->tile()->getSpawnCompound()->getLong("Target"));
		self::assertEqualsWithDelta($health - Conduit::ATTACK_DAMAGE, $zombie->getHealth(), 0.001);
	}

	public function testIgnoresHostileMobsOutOfWater() : void{
		$this->fillWater();
		$this->buildFrame(42);
		$conduit = $this->placeConduit();
		$this->floor(3, -1, 5, 1);
		$zombie = new Zombie(Location::fromObject(new Vector3(self::X + 4.5, self::Y, self::Z + 0.5), $this->world));
		$health = $zombie->getHealth();
		$conduit->updateConduit($this->tile());
		self::assertSame(-1, $this->tile()->getTargetId());
		self::assertSame($health, $zombie->getHealth());
	}
}
