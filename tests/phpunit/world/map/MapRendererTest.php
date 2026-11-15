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

namespace pocketmine\world\map;

use PHPUnit\Framework\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\network\mcpe\protocol\types\MapDecorationType;
use pocketmine\world\format\Chunk;
use function hrtime;

class MapRendererTest extends TestCase{

	/**
	 * Flat test terrain: stone at y=60, grass at y=62 for x >= 8, water from y=61 to y=63 for x < 8, glass at y=70.
	 */
	private static function makeChunk() : Chunk{
		$chunk = new Chunk([], false);
		$stone = VanillaBlocks::STONE()->getStateId();
		$grass = VanillaBlocks::GRASS()->getStateId();
		$water = VanillaBlocks::WATER()->getStateId();
		$glass = VanillaBlocks::GLASS()->getStateId();
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$chunk->setBlockStateId($x, 60, $z, $stone);
				if($x < 8){
					for($y = 61; $y <= 63; ++$y){
						$chunk->setBlockStateId($x, $y, $z, $water);
					}
				}else{
					$chunk->setBlockStateId($x, 61, $z, $stone);
					$chunk->setBlockStateId($x, 62, $z, $grass);
				}
			}
		}
		$chunk->setBlockStateId(10, 70, 10, $glass);
		return $chunk;
	}

	private static function makeRenderer() : MapRenderer{
		return new MapRenderer(BlockMapColors::getInstance());
	}

	public function testSampleColumn() : void{
		$renderer = self::makeRenderer();
		$chunk = self::makeChunk();
		self::assertSame([62, 0x7fb238, 0], $renderer->sampleColumn($chunk, 10, 5));
		//glass doesn't show on maps: the grass below it does
		self::assertSame([62, 0x7fb238, 0], $renderer->sampleColumn($chunk, 10, 10));
		//water reports its depth for shading
		self::assertSame([63, BlockMapColors::WATER, 3], $renderer->sampleColumn($chunk, 2, 2));
		self::assertNull($renderer->sampleColumn(new Chunk([], false), 0, 0));
	}

	public function testBrightness() : void{
		//land: higher than the pixel to the north is bright, lower is dark, flat is normal (on the even checker)
		self::assertSame(BlockMapColors::SHADE_HIGH, MapRenderer::getBrightness(65, 64, 0, 0, 0, 0));
		self::assertSame(BlockMapColors::SHADE_LOW, MapRenderer::getBrightness(63, 64, 0, 0, 0, 0));
		self::assertSame(BlockMapColors::SHADE_NORMAL, MapRenderer::getBrightness(64, 64, 0, 0, 0, 0));
		//water: shallow is bright, deep is dark
		self::assertSame(BlockMapColors::SHADE_HIGH, MapRenderer::getBrightness(63, 63, 1, 0, 0, 0));
		self::assertSame(BlockMapColors::SHADE_LOW, MapRenderer::getBrightness(63, 63, 10, 0, 0, 0));
	}

	public function testRenderColumn() : void{
		$renderer = self::makeRenderer();
		$chunk = self::makeChunk();
		//scale 0 map centred on (8, 8): pixel (64, 64) is block (8, 8), so the chunk covers pixels 56..71
		$map = new MapData(1, "world", 8, 8);
		$getChunk = fn(int $chunkX, int $chunkZ) : ?Chunk => $chunkX === 0 && $chunkZ === 0 ? $chunk : null;

		$changed = $renderer->renderColumn($map, $getChunk, 66, 64, 64);
		self::assertSame(16, $changed, "only the pixels over the loaded chunk are drawn");
		self::assertSame(BlockMapColors::shade(0x7fb238, BlockMapColors::SHADE_NORMAL), $map->getPixel(66, 64));
		self::assertSame(-1, $map->getPixel(66, 50), "unloaded chunks are left blank");
		self::assertSame([66, 56, 1, 16], $map->takeDirtyArea());
		self::assertNull($map->takeDirtyArea());

		//drawing the same terrain again changes nothing
		self::assertSame(0, $renderer->renderColumn($map, $getChunk, 66, 64, 64));

		//water column
		$renderer->renderColumn($map, $getChunk, 58, 64, 64);
		self::assertSame(BlockMapColors::shade(BlockMapColors::WATER, BlockMapColors::SHADE_HIGH), $map->getPixel(58, 64), "shallow water is bright");

		//columns out of the radius around the player aren't drawn
		self::assertSame(0, $renderer->renderColumn($map, $getChunk, 66, 64 - 200, 64));
	}

	public function testRenderTime() : void{
		//a full scale 0 map is 128 columns; one column over loaded chunks must stay well under the tick time budget
		$renderer = self::makeRenderer();
		$chunk = self::makeChunk();
		$map = new MapData(1, "world", 64, 64);
		$getChunk = fn(int $chunkX, int $chunkZ) : Chunk => $chunk;
		$renderer->renderColumn($map, $getChunk, 0, 64, 64); //warm the colour cache
		$start = hrtime(true);
		for($column = 0; $column < MapManager::COLUMNS_PER_HOLDER; ++$column){
			$renderer->renderColumn($map, $getChunk, 60 + $column, 64, 64);
		}
		$elapsed = hrtime(true) - $start;
		self::assertLessThan(MapManager::TICK_TIME_BUDGET_NS * 10, $elapsed, "drawing " . MapManager::COLUMNS_PER_HOLDER . " columns took " . ($elapsed / 1_000_000) . " ms");
	}

	public function testAlignCenter() : void{
		self::assertSame([10, -20], MapData::alignCenter(10, -20, 0), "scale 0 maps are centred on the player");
		//a scale 3 map made at (0, 0) covers -64..959 (Minecraft Wiki)
		self::assertSame([448, 448], MapData::alignCenter(0, 0, 3));
		self::assertSame([448 - 1024, 448], MapData::alignCenter(-65, 959, 3));
	}

	public function testPixelArea() : void{
		$map = new MapData(1, "world", 0, 0);
		$map->setPixel(1, 0, 0xff0000);
		$map->setPixel(2, 1, 0x00ff00);
		self::assertSame([1, 0, 2, 2], $map->takeDirtyArea());
		self::assertSame("\xff\x00\x00\xff" . "\x00\x00\x00\x00" . "\x00\x00\x00\x00" . "\x00\xff\x00\xff", $map->getPixelArea(1, 0, 2, 2));
		self::assertFalse($map->setPixel(1, 0, 0xff0000));
	}

	public function testPlayerMarker() : void{
		$map = new MapData(1, "world", 100, 200);
		$center = MapManager::createPlayerMarker($map, 100, 200, 0);
		self::assertNotNull($center);
		self::assertSame([MapDecorationType::MARKER_WHITE, 0, 0, 0], [$center->getIcon(), $center->getRotation(), $center->getXOffset(), $center->getYOffset()]);

		//positions are half pixels from the centre as signed bytes; rotation is in 16ths of a turn
		$marker = MapManager::createPlayerMarker($map, 90, 230, 90);
		self::assertNotNull($marker);
		self::assertSame([MapDecorationType::MARKER_WHITE, 4, (-20) & 0xff, 60], [$marker->getIcon(), $marker->getRotation(), $marker->getXOffset(), $marker->getYOffset()]);

		$offMap = MapManager::createPlayerMarker($map, 100 + 200, 200, 45);
		self::assertNotNull($offMap);
		self::assertSame([MapDecorationType::SQUARE_WHITE, 0, 126], [$offMap->getIcon(), $offMap->getRotation(), $offMap->getXOffset()]);

		self::assertNull(MapManager::createPlayerMarker($map, 100 + 1000, 200, 0));
	}
}
