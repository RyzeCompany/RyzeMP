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

namespace pocketmine\world\generator\vanilla;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\vanilla\native\VanillaNativeBackend;
use pocketmine\world\SimpleChunkManager;
use function array_map;
use function bin2hex;
use function dirname;
use function getenv;
use function implode;
use function is_dir;
use function is_file;
use function putenv;
use function sys_get_temp_dir;

/**
 * The decoration features placed by the native core (native/vanillagen/decor) must produce exactly the PHP
 * decoration. tools/compare-vanilla-decoration.php checks many more seeds and areas.
 */
class NativeDecorationTest extends TestCase{
	private static ?VanillaNativeBackend $backend = null;

	public static function tearDownAfterClass() : void{
		self::$backend?->stop();
		self::$backend = null;
		putenv(VanillaNativeBackend::ENV_ENDPOINT);
	}

	private static function setUpNative() : string{
		$dir = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
		if($dir === false || !is_dir($dir . "/data/minecraft/worldgen/feature")){
			self::markTestSkipped("POCKETMINE_VANILLA_WORLDGEN_DATA is not set or has no feature data");
		}
		if(self::$backend === null){
			$binary = dirname(__DIR__, 5) . "/native/vanillagen/bin/" . VanillaNativeBackend::binaryName();
			if(!is_file($binary)){
				self::markTestSkipped("vanillagen is not built for this platform ($binary)");
			}
			self::$backend = VanillaNativeBackend::start($binary, sys_get_temp_dir() . "/pmmp-vanillagen-decoration-test.log");
		}
		self::$backend->publish();
		return $dir;
	}

	/**
	 * Generates a 4x4 area and populates its inner 2x2 chunks with the given decoration mode.
	 * @phpstan-param \Closure(string) : VanillaGenerator $factory generator for a preset
	 */
	private static function populate(\Closure $factory, string $preset, int $ox, int $oz) : SimpleChunkManager{
		$generator = $factory($preset);
		$world = new SimpleChunkManager($generator->getDecorator()->level->minY, $generator->getDecorator()->level->minY + $generator->getDecorator()->level->height);
		for($x = -1; $x <= 2; ++$x){
			for($z = -1; $z <= 2; ++$z){
				$world->setChunk($ox + $x, $oz + $z, new Chunk([], false));
				$generator->generateChunk($world, $ox + $x, $oz + $z);
			}
		}
		for($x = 0; $x < 2; ++$x){
			for($z = 0; $z < 2; ++$z){
				$generator->populateChunk($world, $ox + $x, $oz + $z);
			}
		}
		return $world;
	}

	/**
	 * @phpstan-param \Closure(string) : VanillaGenerator $factory generator for a preset
	 */
	private function assertSameDecoration(\Closure $factory, int $seed, int $ox, int $oz) : void{
		$dir = self::setUpNative();
		$native = $factory("data=$dir;decoration=native");
		self::assertNotNull($native->getDecorator()->getNative(), "native decoration is available");
		$a = self::populate($factory, "data=$dir;decoration=php", $ox, $oz);
		$b = self::populate($factory, "data=$dir;decoration=native", $ox, $oz);
		$serializer = new LittleEndianNbtSerializer();
		for($x = -1; $x <= 2; ++$x){
			for($z = -1; $z <= 2; ++$z){
				$ca = $a->getChunk($ox + $x, $oz + $z);
				$cb = $b->getChunk($ox + $x, $oz + $z);
				self::assertNotNull($ca);
				self::assertNotNull($cb);
				for($s = $a->getMinY() >> 4; $s < $a->getMaxY() >> 4; ++$s){
					$sa = $ca->getSubChunk($s);
					$sb = $cb->getSubChunk($s);
					for($i = 0; $i < 4096; ++$i){
						$bx = $i >> 8;
						$bz = ($i >> 4) & 15;
						$by = $i & 15;
						if($sa->getBlockStateId($bx, $by, $bz) !== $sb->getBlockStateId($bx, $by, $bz) || $sa->getLiquidLayerStateId($bx, $by, $bz) !== $sb->getLiquidLayerStateId($bx, $by, $bz)){
							self::fail("seed $seed: block " . (($ox + $x) * 16 + $bx) . " " . ($s * 16 + $by) . " " . (($oz + $z) * 16 + $bz) . " differs");
						}
					}
				}
				//the native side sends whole layers it built (pmlayers.go): they must be the arrays PHP builds, byte for byte
				self::assertSame(self::layers($ca), self::layers($cb), "block layers of chunk " . ($ox + $x) . " " . ($oz + $z));
				$tiles = fn(Chunk $c) : array => array_map(fn($nbt) => $serializer->write(new TreeRoot($nbt)), $c->getPendingTileNBT());
				self::assertSame($tiles($ca), $tiles($cb), "block entities of chunk " . ($ox + $x) . " " . ($oz + $z));
			}
		}
	}

	/**
	 * Every block layer of a chunk as stored: bits per block, palette (in order) and word array.
	 * @return string[]
	 * @phpstan-return array<int, string>
	 */
	private static function layers(Chunk $chunk) : array{
		$out = [];
		foreach($chunk->getSubChunks() as $y => $sub){
			$s = "";
			foreach($sub->getBlockLayers() as $layer){
				$s .= "[" . $layer->getBitsPerBlock() . ":" . implode(",", $layer->getPalette()) . ":" . bin2hex($layer->getWordArray()) . "]";
			}
			$out[$y] = $s;
		}
		return $out;
	}

	public function testOverworldMatchesPhp() : void{
		$this->assertSameDecoration(fn(string $preset) : VanillaGenerator => new VanillaGenerator(12345, $preset), 12345, 0, 0);
	}

	public function testNetherMatchesPhp() : void{
		$this->assertSameDecoration(fn(string $preset) : VanillaGenerator => new VanillaNetherGenerator(777, $preset), 777, 3, -2);
	}
}
