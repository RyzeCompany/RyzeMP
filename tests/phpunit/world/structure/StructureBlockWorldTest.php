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

namespace pocketmine\world\structure;

use pocketmine\block\Chest;
use pocketmine\block\Furnace;
use pocketmine\block\ShulkerBox;
use pocketmine\block\StructureBlock;
use pocketmine\block\tile\Chest as TileChest;
use pocketmine\block\tile\Jigsaw as TileJigsaw;
use pocketmine\block\tile\StructureBlock as TileStructureBlock;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\utils\StructureBlockType;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\entity\object\Painting;
use pocketmine\entity\object\PaintingMotive;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\redstone\RedstoneTestCase;
use function hrtime;

/**
 * Structure blocks in a world: saving, loading rotated (with block entities), redstone, corner detection, loads
 * spread over ticks, and the block entity data compared with BDS 1.26.52.
 */
final class StructureBlockWorldTest extends RedstoneTestCase{

	private function structureBlock(int $x, int $y, int $z, StructureBlockType $mode, string $name, Vector3 $offset, ?Vector3 $size = null) : TileStructureBlock{
		$this->set($x, $y, $z, VanillaBlocks::STRUCTURE_BLOCK()->setMode($mode));
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(TileStructureBlock::class, $tile);
		self::assertSame($mode, $tile->getMode(), "the block entity follows the block's mode");
		$tile->setStructureName($name);
		$tile->setOffset($offset->getFloorX(), $offset->getFloorY(), $offset->getFloorZ());
		if($size !== null){
			$tile->setSize($size->getFloorX(), $size->getFloorY(), $size->getFloorZ());
		}
		return $tile;
	}

	/**
	 * A 3x3x3 build: stone floor, a furnace facing east, a chest with diamonds facing south.
	 */
	private function build(int $x, int $y, int $z) : void{
		for($dx = 0; $dx < 3; ++$dx){
			for($dz = 0; $dz < 3; ++$dz){
				$this->set($x + $dx, $y, $z + $dz, VanillaBlocks::STONE());
			}
		}
		$this->set($x, $y + 1, $z, VanillaBlocks::FURNACE()->setFacing(Facing::EAST));
		$this->set($x + 1, $y + 2, $z + 2, VanillaBlocks::CHEST()->setFacing(Facing::SOUTH));
		$chest = $this->world->getTileAt($x + 1, $y + 2, $z + 2);
		self::assertInstanceOf(TileChest::class, $chest);
		$chest->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(5));
	}

	public function testSaveAndLoadRotated() : void{
		$this->build(0, 1, 0);
		$save = $this->structureBlock(-1, 1, -1, StructureBlockType::SAVE, "test:house", new Vector3(1, 0, 1), new Vector3(3, 3, 3));
		self::assertNull($save->saveStructure(false));
		$template = StructureTemplateStorage::get($this->world)->load("test:house");
		self::assertNotNull($template);
		self::assertSame(27, $template->getVolume());

		$load = $this->structureBlock(10, 1, 10, StructureBlockType::LOAD, "test:house", new Vector3(1, 0, 1));
		$load->setRotation(StructureRotation::CLOCKWISE_90);
		$this->set(12, 2, 12, VanillaBlocks::DIRT());
		self::assertNull($load->loadStructure());
		self::assertFalse($load->isLoadingStructure(), "a small structure loads at once");
		self::assertSame(3, $load->getSize()->getFloorX(), "the size becomes the template's");

		//90 degrees clockwise: (x, z) -> (2 - z, x), from (11, 1, 11)
		self::assertTrue($this->get(11, 1, 11)->isSameState(VanillaBlocks::STONE()));
		$furnace = $this->get(13, 2, 11);
		self::assertInstanceOf(Furnace::class, $furnace);
		self::assertSame(Facing::SOUTH, $furnace->getFacing());
		$chest = $this->get(11, 3, 12);
		self::assertInstanceOf(Chest::class, $chest);
		self::assertSame(Facing::WEST, $chest->getFacing());
		$chestTile = $this->world->getTileAt(11, 3, 12);
		self::assertInstanceOf(TileChest::class, $chestTile);
		self::assertSame(5, $chestTile->getInventory()->getItem(0)->getCount(), "block entity data comes with the block");
		//air of the template replaces what was there
		self::assertSame(VanillaBlocks::AIR()->getStateId(), $this->get(12, 2, 12)->getStateId());
	}

	public function testErrors() : void{
		$save = $this->structureBlock(0, 1, 0, StructureBlockType::SAVE, "", new Vector3(1, 0, 1));
		self::assertNotNull($save->saveStructure(false), "a name is needed");
		$save->setStructureName("test:empty");
		$save->setRemoveBlocks(true);
		$save->setIgnoreEntities(true);
		self::assertSame("commands.structure.empty.save", $save->saveStructure(false)?->getText());

		$load = $this->structureBlock(5, 1, 5, StructureBlockType::LOAD, "test:missing", new Vector3(1, 0, 1));
		self::assertSame("commands.structure.notFound", $load->loadStructure()?->getText());
	}

	public function testRedstoneSignalLoads() : void{
		$this->build(0, 1, 0);
		$save = $this->structureBlock(-1, 1, -1, StructureBlockType::SAVE, "test:redstone", new Vector3(1, 0, 1), new Vector3(3, 3, 3));
		$save->setRedstoneSaveMode(TileStructureBlock::REDSTONE_SAVE_MEMORY);
		//a redstone signal turning on saves in the redstone save mode (memory here)
		$this->set(-2, 1, -1, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		self::assertNotNull(StructureTemplateStorage::get($this->world)->load("test:redstone"));

		$this->structureBlock(10, 1, 10, StructureBlockType::LOAD, "test:redstone", new Vector3(1, 0, 1));
		self::assertFalse($this->get(11, 1, 11)->isSameState(VanillaBlocks::STONE()));
		$this->set(9, 1, 10, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		self::assertTrue($this->get(11, 1, 11)->isSameState(VanillaBlocks::STONE()), "the load block loaded when powered");

		//staying powered doesn't load again
		$this->set(11, 1, 11, VanillaBlocks::DIRT());
		$this->set(10, 2, 10, VanillaBlocks::GLASS());
		$this->runTicks(2);
		self::assertTrue($this->get(11, 1, 11)->isSameState(VanillaBlocks::DIRT()));
	}

	public function testDetectCorners() : void{
		$this->structureBlock(20, 1, 20, StructureBlockType::CORNER, "test:corners", new Vector3(0, 0, 0));
		$this->structureBlock(25, 5, 26, StructureBlockType::CORNER, "test:corners", new Vector3(0, 0, 0));
		$this->structureBlock(22, 3, 22, StructureBlockType::CORNER, "test:other", new Vector3(0, 0, 0));
		$save = $this->structureBlock(19, 1, 19, StructureBlockType::SAVE, "test:corners", new Vector3(0, -1, 0));
		self::assertTrue($save->detectSize());
		self::assertTrue($save->getOffset()->equals(new Vector3(2, 1, 2)));
		self::assertTrue($save->getSize()->equals(new Vector3(4, 3, 5)), "the inside of the box between the corners");

		$alone = $this->structureBlock(-20, 1, -20, StructureBlockType::SAVE, "test:nothing", new Vector3(0, -1, 0));
		self::assertFalse($alone->detectSize());
	}

	public function testCornerAreaWithOneCorner() : void{
		//one corner: the box between it and the structure block
		$area = TileStructureBlock::computeCornerArea(new Vector3(0, 0, 0), [new Vector3(4, 4, 4)]);
		self::assertNotNull($area);
		self::assertTrue($area[0]->equals(new Vector3(1, 1, 1)));
		self::assertTrue($area[1]->equals(new Vector3(3, 3, 3)));
		self::assertNull(TileStructureBlock::computeCornerArea(new Vector3(0, 0, 0), [new Vector3(1, 4, 4)]), "nothing inside");
		self::assertNull(TileStructureBlock::computeCornerArea(new Vector3(0, 0, 0), []));
	}

	public function testBigLoadSpansTicks() : void{
		$builder = new StructureTemplateBuilder(48, 4, 48);
		$stone = VanillaBlocks::STONE()->getStateId();
		for($x = 0; $x < 48; ++$x){
			for($y = 0; $y < 4; ++$y){
				for($z = 0; $z < 48; ++$z){
					$builder->setBlock($x, $y, $z, $stone);
				}
			}
		}
		StructureTemplateStorage::get($this->world)->save("test:big", $builder->build(), false);
		$load = $this->structureBlock(-24, 10, -24, StructureBlockType::LOAD, "test:big", new Vector3(0, 1, 0));
		self::assertNull($load->loadStructure());
		self::assertTrue($load->isLoadingStructure(), "9216 blocks take several ticks");
		self::assertTrue($this->get(-24, 11, -24)->isSameState(VanillaBlocks::STONE()), "the bottom layers come first");
		self::assertFalse($this->get(-24, 14, -24)->isSameState(VanillaBlocks::STONE()));
		//1024 blocks placed per tick, then 512 updated per tick
		$this->runTicks(40);
		self::assertFalse($load->isLoadingStructure());
		self::assertTrue($this->get(23, 14, 23)->isSameState(VanillaBlocks::STONE()));
	}

	private function saveFilled(string $name, int $sizeX, int $sizeY, int $sizeZ) : void{
		$builder = new StructureTemplateBuilder($sizeX, $sizeY, $sizeZ);
		$stone = VanillaBlocks::STONE()->getStateId();
		for($x = 0; $x < $sizeX; ++$x){
			for($y = 0; $y < $sizeY; ++$y){
				for($z = 0; $z < $sizeZ; ++$z){
					$builder->setBlock($x, $y, $z, $stone);
				}
			}
		}
		StructureTemplateStorage::get($this->world)->save($name, $builder->build(), false);
	}

	/**
	 * Runs ticks until none of the blocks is loading a structure any more (at most $maxTicks).
	 */
	private function runUntilLoaded(int $maxTicks, TileStructureBlock ...$tiles) : void{
		for($i = 0; $i < $maxTicks; ++$i){
			$loading = false;
			foreach($tiles as $tile){
				$loading = $loading || $tile->isLoadingStructure();
			}
			if(!$loading){
				return;
			}
			$this->runTicks(1);
		}
		self::fail("The structures were not loaded after $maxTicks ticks");
	}

	/**
	 * The default offset (0, -1, 0) puts the template's cell (0, 1, 0) on the load block. The block is replaced last,
	 * so the load stays spread over ticks (reaching that cell used to finish the whole load in one tick).
	 */
	public function testLoadCoveringItsBlockStaysSpread() : void{
		$this->saveFilled("test:cover", 32, 8, 32);
		$load = $this->structureBlock(0, 5, 0, StructureBlockType::LOAD, "test:cover", new Vector3(0, -1, 0));
		self::assertNull($load->loadStructure());
		self::assertTrue($load->isLoadingStructure());
		$this->runTicks(2);
		self::assertTrue($load->isLoadingStructure(), "the block's own cell doesn't finish the load at once");
		self::assertFalse($this->get(31, 11, 31)->isSameState(VanillaBlocks::STONE()), "the top layer is not placed yet");
		self::assertInstanceOf(StructureBlock::class, $this->get(0, 5, 0), "the block stays until the end");
		$this->runUntilLoaded(400, $load);
		self::assertTrue($this->get(31, 11, 31)->isSameState(VanillaBlocks::STONE()));
		self::assertTrue($this->get(0, 5, 0)->isSameState(VanillaBlocks::STONE()), "then the structure replaces the block");
		self::assertNull($this->world->getTileAt(0, 5, 0));
	}

	/**
	 * Animated by layer, a layer bigger than the budget of a tick takes several ticks.
	 */
	public function testAnimationByLayerKeepsTheBudget() : void{
		$this->saveFilled("test:layers", 48, 3, 48);
		$load = $this->structureBlock(-24, 10, -24, StructureBlockType::LOAD, "test:layers", new Vector3(0, 1, 0));
		$load->setAnimationMode(StructurePlaceSettings::ANIMATION_BY_LAYER);
		$load->setAnimationSeconds(0.05); //one tick: the three layers at once
		self::assertNull($load->loadStructure());
		self::assertTrue($load->isLoadingStructure());
		self::assertTrue($this->get(-24, 11, -24)->isSameState(VanillaBlocks::STONE()));
		self::assertFalse($this->get(23, 11, 23)->isSameState(VanillaBlocks::STONE()), "2304 blocks don't fit in one tick");
		$this->runUntilLoaded(400, $load);
		self::assertTrue($this->get(23, 13, 23)->isSameState(VanillaBlocks::STONE()));
	}

	/**
	 * All the loads running share the budget of a tick.
	 */
	public function testLoadsShareTheTickBudget() : void{
		//the first load of the process sets up the block state upgrader, which can take longer than a tick
		$this->saveFilled("test:warmup", 1, 1, 1);
		self::assertNull($this->structureBlock(-32, 10, 40, StructureBlockType::LOAD, "test:warmup", new Vector3(0, 1, 0))->loadStructure());
		$this->runTicks(1);

		$this->saveFilled("test:shared", 32, 2, 32);
		$first = $this->structureBlock(-30, 10, -30, StructureBlockType::LOAD, "test:shared", new Vector3(0, 1, 0));
		$second = $this->structureBlock(0, 10, 0, StructureBlockType::LOAD, "test:shared", new Vector3(0, 1, 0));
		$start = hrtime(true);
		self::assertNull($first->loadStructure());
		self::assertNull($second->loadStructure());
		$elapsed = hrtime(true) - $start;
		self::assertTrue($this->get(-30, 11, -30)->isSameState(VanillaBlocks::STONE()));
		//a budget older than a tick (50 ms) belongs to an earlier tick: on a busy machine (CI runners, tests in parallel)
		//the two loads can take that long, and then the second one rightly gets a budget of its own
		if($elapsed < 40_000_000){
			self::assertFalse($this->get(0, 11, 0)->isSameState(VanillaBlocks::STONE()), "the first load used the budget of the tick");
		}
		$this->runUntilLoaded(400, $first, $second);
		self::assertTrue($this->get(1, 12, 31)->isSameState(VanillaBlocks::STONE()));
		self::assertTrue($this->get(1, 12, 1)->isSameState(VanillaBlocks::STONE()));
	}

	/**
	 * The redstone state is saved: a block still powered when its block entity loads again doesn't see a new signal
	 * (which would load once more on the next neighbour update, e.g. when its settings are edited).
	 */
	public function testPoweredStateIsSaved() : void{
		$this->build(0, 1, 0);
		$save = $this->structureBlock(-1, 1, -1, StructureBlockType::SAVE, "test:powered", new Vector3(1, 0, 1), new Vector3(3, 3, 3));
		self::assertNull($save->saveStructure(false));
		$load = $this->structureBlock(10, 1, 10, StructureBlockType::LOAD, "test:powered", new Vector3(1, 0, 1));
		$this->set(9, 1, 10, VanillaBlocks::REDSTONE());
		$this->runTicks(2);
		self::assertTrue($this->get(11, 1, 11)->isSameState(VanillaBlocks::STONE()), "the signal loads");
		$this->set(11, 1, 11, VanillaBlocks::DIRT());

		$nbt = $load->saveNBT();
		self::assertSame(1, $nbt->getByte(TileStructureBlock::TAG_IS_POWERED));
		self::assertSame(0, $load->getSpawnCompound()->getByte(TileStructureBlock::TAG_IS_POWERED), "clients get false, like BDS sends");

		//the block entity loads again (as when its chunk does) while the block is still powered
		$load->close();
		$reloaded = TileFactory::getInstance()->createFromData($this->world, $nbt);
		self::assertInstanceOf(TileStructureBlock::class, $reloaded);
		$this->world->addTile($reloaded);
		$this->set(10, 2, 10, VanillaBlocks::GLASS());
		$this->runTicks(2);
		self::assertTrue($this->get(11, 1, 11)->isSameState(VanillaBlocks::DIRT()), "a signal that was already there doesn't load again");
	}

	/**
	 * The facing of a shulker box (kept by its block entity only) turns with the structure.
	 */
	public function testShulkerBoxTurns() : void{
		$this->set(0, 1, 0, VanillaBlocks::SHULKER_BOX()->setFacing(Facing::NORTH));
		$save = $this->structureBlock(-1, 1, -1, StructureBlockType::SAVE, "test:shulker", new Vector3(1, 0, 1), new Vector3(1, 1, 1));
		self::assertNull($save->saveStructure(false));

		$rotated = $this->structureBlock(10, 1, 10, StructureBlockType::LOAD, "test:shulker", new Vector3(1, 0, 1));
		$rotated->setRotation(StructureRotation::CLOCKWISE_90);
		self::assertNull($rotated->loadStructure());
		$box = $this->get(11, 1, 11);
		self::assertInstanceOf(ShulkerBox::class, $box);
		self::assertSame(Facing::EAST, $box->getFacing());

		$mirrored = $this->structureBlock(20, 1, 20, StructureBlockType::LOAD, "test:shulker", new Vector3(1, 0, 1));
		$mirrored->setMirror(StructureMirror::X);
		self::assertNull($mirrored->loadStructure());
		$box = $this->get(21, 1, 21);
		self::assertInstanceOf(ShulkerBox::class, $box);
		self::assertSame(Facing::SOUTH, $box->getFacing());
	}

	private function findPainting(string $motive, int $facing, AxisAlignedBB $within) : ?Painting{
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Painting && !$entity->isClosed() && $entity->getMotive()->getName() === $motive && $entity->getFacing() === $facing){
				$bb = $entity->getBoundingBox();
				if($bb->minX >= $within->minX && $bb->maxX <= $within->maxX && $bb->minY >= $within->minY && $bb->maxY <= $within->maxY && $bb->minZ >= $within->minZ && $bb->maxZ <= $within->maxZ){
					return $entity;
				}
			}
		}
		return null;
	}

	/**
	 * Paintings are drawn from the block they hang on: it moves and turns with the structure.
	 */
	public function testPaintingsMoveWithTheirWall() : void{
		//a stone wall along z = 0 with a 1x1 painting and a 2x1 painting (which goes east of its block) facing south
		for($x = 0; $x < 3; ++$x){
			for($y = 1; $y < 4; ++$y){
				$this->set($x, $y, 0, VanillaBlocks::STONE());
			}
		}
		$kebab = PaintingMotive::getMotiveByName("Kebab");
		$courbet = PaintingMotive::getMotiveByName("Courbet");
		self::assertNotNull($kebab);
		self::assertNotNull($courbet);
		new Painting(Location::fromObject(new Vector3(1, 3, 1), $this->world), new Vector3(1, 3, 0), Facing::SOUTH, $kebab);
		new Painting(Location::fromObject(new Vector3(0, 1, 1), $this->world), new Vector3(0, 1, 0), Facing::SOUTH, $courbet);
		$save = $this->structureBlock(-1, 1, -1, StructureBlockType::SAVE, "test:paintings", new Vector3(1, 0, 1), new Vector3(3, 3, 3));
		self::assertNull($save->saveStructure(false));
		self::assertCount(2, StructureTemplateStorage::get($this->world)->load("test:paintings")?->getEntities() ?? []);

		//90 degrees clockwise from (11, 1, 11): (x, z) -> (2 - z, x), the wall is now along x = 13, facing west
		$rotated = $this->structureBlock(10, 1, 10, StructureBlockType::LOAD, "test:paintings", new Vector3(1, 0, 1));
		$rotated->setRotation(StructureRotation::CLOCKWISE_90);
		self::assertNull($rotated->loadStructure());
		//mirrored along x from (21, 1, 21): x -> 2 - x, the 2x1 painting now goes west of its block
		$mirrored = $this->structureBlock(20, 1, 20, StructureBlockType::LOAD, "test:paintings", new Vector3(1, 0, 1));
		$mirrored->setMirror(StructureMirror::Z);
		self::assertNull($mirrored->loadStructure());
		//mirrored along both axes from (31, 1, 31): a half turn, the wall is along z = 33, facing north
		$halfTurn = $this->structureBlock(30, 1, 30, StructureBlockType::LOAD, "test:paintings", new Vector3(1, 0, 1));
		$halfTurn->setMirror(StructureMirror::XZ);
		self::assertNull($halfTurn->loadStructure());
		//the paintings check their wall on the neighbour updates of the load
		$this->runTicks(2);

		self::assertNotNull($this->findPainting("Kebab", Facing::SOUTH, new AxisAlignedBB(1, 3, 1, 2, 4, 2)), "the saved painting stays");
		self::assertNotNull($this->findPainting("Kebab", Facing::WEST, new AxisAlignedBB(12, 3, 12, 13, 4, 13)), "rotated 1x1 painting");
		self::assertNotNull($this->findPainting("Courbet", Facing::WEST, new AxisAlignedBB(12, 1, 11, 13, 2, 13)), "rotated 2x1 painting");
		self::assertNotNull($this->findPainting("Kebab", Facing::SOUTH, new AxisAlignedBB(22, 3, 22, 23, 4, 23)), "mirrored 1x1 painting");
		self::assertNotNull($this->findPainting("Courbet", Facing::SOUTH, new AxisAlignedBB(22, 1, 22, 24, 2, 23)), "mirrored 2x1 painting");
		self::assertNotNull($this->findPainting("Kebab", Facing::NORTH, new AxisAlignedBB(32, 3, 32, 33, 4, 33)), "1x1 painting mirrored along both axes");
		self::assertNotNull($this->findPainting("Courbet", Facing::NORTH, new AxisAlignedBB(32, 1, 32, 34, 2, 33)), "2x1 painting mirrored along both axes");
		$count = 0;
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof Painting && !$entity->isClosed()){
				++$count;
			}
		}
		self::assertSame(8, $count, "no painting was killed");
	}

	public function testOutsideLoadedChunksFails() : void{
		$builder = new StructureTemplateBuilder(1, 1, 1);
		$builder->setBlock(0, 0, 0, VanillaBlocks::STONE()->getStateId());
		StructureTemplateStorage::get($this->world)->save("test:far", $builder->build(), false);
		$load = $this->structureBlock(0, 1, 0, StructureBlockType::LOAD, "test:far", new Vector3(64, 0, 0));
		self::assertSame("commands.structure.unloadedChunks", $load->loadStructure()?->getText());
	}

	/**
	 * The block entity data of a structure block and a jigsaw block saved by BDS reads and writes back the same.
	 */
	public function testBlockEntityDataMatchesBds() : void{
		$template = StructureTemplateTest::bds("sbtpl");
		$structureBlockData = $template->getBlockEntity(0);
		$jigsawData = $template->getBlockEntity(1);
		self::assertNotNull($structureBlockData);
		self::assertNotNull($jigsawData);

		$this->set(0, 1, 0, VanillaBlocks::STRUCTURE_BLOCK());
		$tile = $this->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(TileStructureBlock::class, $tile);
		self::assertBdsFields($structureBlockData, $tile->getCleanedNBT(), "a new structure block has the BDS defaults");
		$tile->readSaveData($structureBlockData);
		self::assertBdsFields($structureBlockData, $tile->getCleanedNBT(), "structure block");

		$this->set(1, 1, 0, VanillaBlocks::JIGSAW());
		$jigsaw = $this->world->getTileAt(1, 1, 0);
		self::assertInstanceOf(TileJigsaw::class, $jigsaw);
		self::assertBdsFields($jigsawData, $jigsaw->getCleanedNBT(), "a new jigsaw has the BDS defaults");
		self::assertTrue($jigsaw->readJigsawData(CompoundTag::create()->setString(TileJigsaw::TAG_NAME, "minecraft:bottom")->setString(TileJigsaw::TAG_JOINT, "aligned")));
		self::assertSame("minecraft:bottom", $jigsaw->getName());
		self::assertSame(TileJigsaw::JOINT_ALIGNED, $jigsaw->getJoint());
		self::assertFalse($jigsaw->readJigsawData(CompoundTag::create()->setString(TileJigsaw::TAG_JOINT, "sideways")), "invalid joints are ignored");
	}

	private static function assertBdsFields(CompoundTag $bds, ?CompoundTag $ours, string $message) : void{
		self::assertNotNull($ours);
		$expected = clone $bds;
		foreach(["id", "x", "y", "z", "BlockEntityVersion"] as $generic){
			$expected->removeTag($generic);
		}
		self::assertTrue($expected->equals($ours), $message . ": expected " . $expected . ", got " . $ours);
	}

	public function testBlockStates() : void{
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$data = $serializer->serialize(VanillaBlocks::STRUCTURE_BLOCK()->setMode(StructureBlockType::CORNER)->getStateId());
		self::assertSame("minecraft:structure_block", $data->getName());
		self::assertSame("corner", $data->getState("structure_block_type")?->getValue());

		$data = $serializer->serialize(VanillaBlocks::JIGSAW()->setFacing(Facing::NORTH)->setRotation(1)->getStateId());
		self::assertSame("minecraft:jigsaw", $data->getName());
		self::assertSame(2, $data->getState("facing_direction")?->getValue());
		self::assertSame(1, $data->getState("rotation")?->getValue());

		$structureBlock = VanillaBlocks::STRUCTURE_BLOCK();
		self::assertSame(StructureBlockType::SAVE, $structureBlock->getMode(), "new structure blocks save, like BDS");
		self::assertFalse($structureBlock->getBreakInfo()->isBreakable());
		self::assertFalse(VanillaBlocks::JIGSAW()->getBreakInfo()->isBreakable());
	}
}
