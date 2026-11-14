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

namespace pocketmine\world\generator\vanilla\native;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\BlockEntityData;
use pocketmine\world\generator\vanilla\feature\FeatureLevel;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use function array_values;
use function chr;
use function count;
use function hrtime;
use function implode;
use function intdiv;
use function is_array;
use function is_int;
use function max;
use function min;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Decoration features placed by the native core (native/vanillagen/decor), on the connection of the generator's
 * {@link NativeTerrainClient}.
 *
 * The native side keeps its own copy of the decoration region (sent by {@link begin()}, as Java block states) and
 * places the features it implements, in the global feature order, in batches of consecutive features
 * ({@link place()}). Both sides apply every write of the other in order: the writes of PHP features and structures are
 * logged by {@link FeatureLevel} and sent with the next batch, and the native writes come back and are applied with
 * FeatureLevel::setState(). The result is exactly the PHP decoration.
 */
final class NativeDecorator{
	private const MSG_INIT = 0x10;
	private const MSG_BEGIN = 0x11;
	private const MSG_PLACE = 0x12;
	private const MSG_RT = 0x14;
	private const MSG_PM = 0x15;
	private const MSG_NEED_PM = 0x95;
	private const MSG_READY = 0x90;
	private const MSG_NEED_RT = 0x94;
	private const MSG_DONE = 0x92;
	private const MSG_FAIL = 0x93;

	/**
	 * Features the native side places, by step and index in the step's global order.
	 * @phpstan-var array<int, array<int, true>>
	 */
	private array $native = [];
	/**
	 * Features left to PHP and why (from the native side), for diagnostics.
	 * @phpstan-var array<string, string>
	 */
	public array $unsupported = [];
	/** @phpstan-var array<int, string> PocketMine state id => packed (dry, waterlogged) Java states */
	private array $palette = [];
	private readonly int $pmWater;
	/** @phpstan-var array<int, true> region chunks whose terrain heights the native side knows (this decoration) */
	private array $terrainKnown = [];
	/**
	 * Time (ns) and counters for benchmarks: begin, place (whole requests), apply (PHP side of the native writes), writes,
	 * batches.
	 * @phpstan-var array<string, int>
	 */
	public array $stats = ["begin" => 0, "place" => 0, "apply" => 0, "writes" => 0, "batches" => 0, "layers" => 0, "single" => 0, "slow" => 0];

	/**
	 * @param string[]   $biomeNames        registry biome names, in registry order
	 * @param int[][]    $bedrockToJava     registry biome indexes per Bedrock biome id
	 * @param int[]      $terrainToRegistry registry biome index per terrain biome (the native core's biome names)
	 * @param string[][] $stepFeatures      placed feature ids per step, in the global feature order
	 * @phpstan-param list<string> $biomeNames
	 * @phpstan-param array<int, list<int>> $bedrockToJava
	 * @phpstan-param array<int, int> $terrainToRegistry
	 * @phpstan-param list<list<string>> $stepFeatures
	 *
	 * @throws NativeBackendException
	 */
	public function __construct(
		private readonly NativeTerrainClient $client,
		private readonly JavaBlocks $blocks,
		private readonly int $minY,
		private readonly int $height,
		int $seaLevel,
		array $biomeNames,
		array $bedrockToJava,
		array $terrainToRegistry,
		array $stepFeatures
	){
		$this->pmWater = $blocks->pmWaterState();
		$payload = chr(self::MSG_INIT) . pack("VVVv", $minY, $height, $seaLevel, count($biomeNames));
		foreach($biomeNames as $name){
			$payload .= pack("v", strlen($name)) . $name;
		}
		$payload .= pack("v", count($bedrockToJava));
		foreach($bedrockToJava as $id => $list){
			$payload .= pack("vv", $id, count($list)) . (count($list) > 0 ? pack("v*", ...$list) : "");
		}
		$payload .= pack("v", count($terrainToRegistry));
		for($i = 0, $n = count($terrainToRegistry); $i < $n; ++$i){
			$payload .= pack("v", $terrainToRegistry[$i] ?? throw new \InvalidArgumentException("Terrain biome $i has no registry biome"));
		}
		$payload .= chr(count($stepFeatures));
		foreach($stepFeatures as $ids){
			$payload .= pack("v", count($ids));
			foreach($ids as $id){
				$payload .= pack("v", strlen($id)) . $id;
			}
		}
		//the PocketMine layer replica of the native side (native/vanillagen/decor/pmlayers.go)
		$payload .= pack("VV", $blocks->pmAirState(), $this->pmWater);
		$client->send($payload);
		$frame = $client->receive();
		$type = ord($frame[0]);
		if($type === self::MSG_FAIL){
			$offset = 1;
			throw new NativeBackendException("native decoration unavailable: " . self::readStr($frame, $offset));
		}
		if($type !== self::MSG_READY){
			throw new NativeBackendException("unexpected decoration INIT reply");
		}
		$offset = 1;
		$steps = ord($frame[$offset++] ?? "\0");
		if($steps !== count($stepFeatures)){
			throw new NativeBackendException("decoration step count mismatch");
		}
		for($step = 0; $step < $steps; ++$step){
			$count = self::u16($frame, $offset);
			if($count !== count($stepFeatures[$step])){
				throw new NativeBackendException("decoration feature count mismatch");
			}
			$bytes = intdiv($count + 7, 8);
			$bitmap = substr($frame, $offset, $bytes);
			$offset += $bytes;
			for($i = 0; $i < $count; ++$i){
				if((ord($bitmap[$i >> 3]) & (1 << ($i & 7))) !== 0){
					$this->native[$step][$i] = true;
				}
			}
		}
		$reasons = self::u16($frame, $offset);
		for($i = 0; $i < $reasons; ++$i){
			$id = self::readStr($frame, $offset);
			$this->unsupported[$id] = self::readStr($frame, $offset);
		}
	}

	public function isNative(int $step, int $index) : bool{
		return isset($this->native[$step][$index]);
	}

	public function nativeCount() : int{
		$n = 0;
		foreach($this->native as $set){
			$n += count($set);
		}
		return $n;
	}

	/**
	 * Sends the decoration region (after FeatureLevel::begin()).
	 *
	 * @param (string|null)[] $recordedHeights packed terrain heights per chunk index (FeatureDecorator::recordTerrain())
	 * @phpstan-param array<int, string|null> $recordedHeights
	 *
	 * @throws NativeBackendException
	 */
	public function begin(FeatureLevel $level, int $chunkX, int $chunkZ, array $recordedHeights) : void{
		$start = hrtime(true);
		$parts = [chr(self::MSG_BEGIN) . pack("VV", $chunkX, $chunkZ)];
		$this->terrainKnown = [];
		$sections = $this->height >> 4;
		for($ci = 0; $ci < 9; ++$ci){
			$heights = $recordedHeights[$ci] ?? null;
			if($heights !== null && strlen($heights) === 1024){
				$parts[] = "\x01" . $heights;
				$this->terrainKnown[$ci] = true;
			}else{
				$parts[] = "\x00";
			}
			for($s = 0; $s < $sections; ++$s){
				$sub = $level->getRegionSubChunk($ci, $s);
				$layers = $sub->getBlockLayers();
				if(count($layers) === 0){
					$parts[] = "\xff\xff";
				}else{
					$layer = $layers[0];
					$bits = $layer->getBitsPerBlock();
					$entries = "";
					foreach($layer->getPalette() as $pm){
						$entries .= $this->palette[$pm] ??= $this->mapPalette($pm);
					}
					$parts[] = chr($bits) . ($bits > 0 ? pack("V", strlen($words = $layer->getWordArray())) . $words : "") . pack("v", strlen($entries) >> 3) . $entries;
					if(isset($layers[1])){
						$liquid = $layers[1];
						$bits = $liquid->getBitsPerBlock();
						$liquidPalette = $liquid->getPalette();
						$parts[] = chr($bits) . ($bits > 0 ? pack("V", strlen($words = $liquid->getWordArray())) . $words : "") . pack("vV*", count($liquidPalette), ...$liquidPalette);
					}else{
						$parts[] = "\xff";
					}
				}
				$biomes = $sub->getBiomeArray();
				$bits = $biomes->getBitsPerBlock();
				$palette = $biomes->getPalette();
				$parts[] = chr($bits) . ($bits > 0 ? pack("V", strlen($words = $biomes->getWordArray())) . $words : "") . pack("v*", count($palette), ...$palette);
			}
		}
		$this->client->send(implode("", $parts));
		$this->stats["begin"] += hrtime(true) - $start;
	}

	private function mapPalette(int $pm) : string{
		$blocks = $this->blocks;
		$dry = $blocks->fromPm($pm);
		$wet = $blocks->hasProperty($dry, "waterlogged") ? $blocks->withBool($dry, "waterlogged", true) : $dry;
		return pack("Vvv", $pm, $dry, $wet);
	}

	/**
	 * Places a batch of consecutive natively placed features (in order) and applies their writes to the level.
	 *
	 * @param int[][] $features [step, index] pairs
	 * @phpstan-param list<array{int, int}> $features
	 *
	 * @throws NativeBackendException
	 */
	public function place(FeatureLevel $level, FeatureRandom $random, int $decorationSeed, array $features) : void{
		$start = hrtime(true);
		++$this->stats["batches"];
		$log = $level->writeLog;
		$level->writeLog = [];
		[$haveGaussian, $gaussian] = $random->getGaussianState();
		$payload = chr(self::MSG_PLACE) . pack("P", $decorationSeed) . ($haveGaussian ? "\x01" : "\x00") . pack("e", $gaussian) .
			pack("V", intdiv(count($log), 3)) . (count($log) > 0 ? pack("V*", ...$log) : "");
		//terrain heights PHP computed since the last request
		$heights = "";
		$count = 0;
		foreach($level->getKnownTerrainHeights() as $ci => $known){
			if(!isset($this->terrainKnown[$ci])){
				$this->terrainKnown[$ci] = true;
				$heights .= chr($ci) . $this->packHeights($known);
				++$count;
			}
		}
		$payload .= chr($count) . $heights . pack("v", count($features));
		foreach($features as [$step, $index]){
			$payload .= pack("Cv", $step, $index);
		}
		$client = $this->client;
		$client->send($payload);
		while(true){
			$frame = $client->receive();
			$type = ord($frame[0]);
			if($type === self::MSG_FAIL){
				$offset = 1;
				throw new NativeBackendException("native decoration failed: " . self::readStr($frame, $offset));
			}
			if($type === self::MSG_NEED_RT){
				$offset = 1;
				$n = self::u16($frame, $offset);
				$states = $n > 0 ? unpack("v$n", $frame, $offset) : [];
				if(!is_array($states) || strlen($frame) !== $offset + 2 * $n){
					throw new NativeBackendException("malformed decoration NEED_RT");
				}
				$blocks = $this->blocks;
				$reply = chr(self::MSG_RT);
				foreach($states as $state){
					//what FeatureDecorator::computeTerrainHeights() reads back from a chunk where the state was written
					$reply .= pack("v", $blocks->blockOf[$blocks->fromPm($blocks->toPm((int) $state))]);
				}
				$client->send($reply);
				continue;
			}
			if($type === self::MSG_NEED_PM){
				$offset = 1;
				$n = self::u16($frame, $offset);
				$states = $n > 0 ? unpack("v$n", $frame, $offset) : [];
				if(!is_array($states) || strlen($frame) !== $offset + 2 * $n){
					throw new NativeBackendException("malformed decoration NEED_PM");
				}
				$blocks = $this->blocks;
				$reply = chr(self::MSG_PM);
				foreach($states as $state){
					//what FeatureLevel::setState() writes for the state
					$reply .= pack("VC", $blocks->toPm((int) $state), $blocks->pmNeedsWater((int) $state) ? 1 : 0);
				}
				$client->send($reply);
				continue;
			}
			if($type !== self::MSG_DONE){
				throw new NativeBackendException("unexpected decoration reply");
			}
			$offset = 1;
			$this->applyWrites($level, $frame, $offset);
			if(strlen($frame) < $offset + 10){
				throw new NativeBackendException("malformed decoration DONE");
			}
			$value = unpack("e", $frame, $offset + 1);
			if(!is_array($value)){
				throw new NativeBackendException("malformed decoration DONE");
			}
			$random->setGaussianState($frame[$offset] !== "\x00", (float) $value[1]);
			$offset += 9;
			$computed = ord($frame[$offset++]);
			if(strlen($frame) !== $offset + 1025 * $computed){
				throw new NativeBackendException("malformed decoration DONE");
			}
			$minY = $this->minY;
			for($i = 0; $i < $computed; ++$i){
				$ci = ord($frame[$offset++]);
				$values = unpack("v512", $frame, $offset);
				$offset += 1024;
				if($ci > 8 || !is_array($values)){
					throw new NativeBackendException("malformed decoration DONE");
				}
				$surface = [];
				$oceanFloor = [];
				for($k = 1; $k <= 256; ++$k){
					$surface[] = (int) $values[$k] + $minY;
					$oceanFloor[] = (int) $values[$k + 256] + $minY;
				}
				$level->setTerrainHeights($ci, [$surface, $oceanFloor]);
				$this->terrainKnown[$ci] = true;
			}
			$this->stats["place"] += hrtime(true) - $start;
			return;
		}
	}

	/**
	 * @phpstan-param array{list<int>, list<int>} $heights
	 */
	private function packHeights(array $heights) : string{
		$minY = $this->minY;
		$out = "";
		foreach($heights as $list){
			foreach($list as $h){
				$out .= pack("v", $h - $minY);
			}
		}
		return $out;
	}

	/**
	 * @throws NativeBackendException
	 */
	private function applyWrites(FeatureLevel $level, string $frame, int &$offset) : void{
		$tiles = [];
		$tileCount = self::u16($frame, $offset);
		for($i = 0; $i < $tileCount; ++$i){
			$length = self::u16($frame, $offset);
			if(strlen($frame) < $offset + $length){
				throw new NativeBackendException("truncated decoration block entity");
			}
			$tiles[] = self::blockEntity(substr($frame, $offset, $length));
			$offset += $length;
		}
		$count = self::u32($frame, $offset);
		if(strlen($frame) < $offset + 6 * $count){
			throw new NativeBackendException("truncated decoration writes");
		}
		$keys = $count > 0 ? unpack("V$count", $frame, $offset) : [];
		$offset += 4 * $count;
		$states = $count > 0 ? unpack("v$count", $frame, $offset) : [];
		$offset += 2 * $count;
		if(!is_array($keys) || !is_array($states)){
			throw new NativeBackendException("malformed decoration writes");
		}
		$start = hrtime(true);
		//the same writes as PocketMine layers (native/vanillagen/decor/pmlayers.go)
		if(strlen($frame) < $offset + 4){
			throw new NativeBackendException("truncated decoration layers");
		}
		$chunkMask = self::u16($frame, $offset);
		$sectionCount = self::u16($frame, $offset);
		$sections = [];
		for($i = 0; $i < $sectionCount; ++$i){
			if(strlen($frame) < $offset + 10){
				throw new NativeBackendException("truncated decoration layers");
			}
			$header = unpack("vsection/Cbefore/Cbits0/vcount0/Cbits1/vcount1/Clayers", $frame, $offset);
			$offset += 10;
			if(!is_array($header) || $header["layers"] < 1 || $header["layers"] > 2 || $header["before"] > 2){
				throw new NativeBackendException("malformed decoration layers");
			}
			$layers = [];
			for($l = 0; $l < $header["layers"]; ++$l){
				$bits = ord($frame[$offset] ?? "\xff");
				$paletteCount = (ord($frame[$offset + 1] ?? "\0") | (ord($frame[$offset + 2] ?? "\0") << 8));
				$offset += 3;
				$blocksPerWord = $bits > 0 && $bits <= 16 ? intdiv(32, $bits) : 0;
				$wordBytes = $bits > 0 ? 4 * intdiv(4096 + $blocksPerWord - 1, max(1, $blocksPerWord)) : 0;
				if($paletteCount === 0 || ($bits === 0 ? $paletteCount !== 1 : ($blocksPerWord === 0 || $paletteCount > min(4096, 1 << $bits))) || strlen($frame) < $offset + 4 * $paletteCount + $wordBytes){
					throw new NativeBackendException("malformed decoration layer");
				}
				$palette = unpack("V$paletteCount", $frame, $offset);
				$offset += 4 * $paletteCount;
				if($palette === false){
					throw new NativeBackendException("malformed decoration layer");
				}
				$layers[] = [$bits, $bits > 0 ? substr($frame, $offset, $wordBytes) : "", array_values($palette)];
				$offset += $wordBytes;
			}
			$sections[] = [$header["section"], $header["before"], $header["bits0"], $header["count0"], $header["bits1"], $header["count1"], $layers];
		}
		$single = self::u32($frame, $offset);
		if(strlen($frame) < $offset + 9 * $single){
			throw new NativeBackendException("truncated decoration writes");
		}
		$singleSections = $single > 0 ? unpack("v$single", $frame, $offset) : [];
		$offset += 2 * $single;
		$singleIndexes = $single > 0 ? unpack("v$single", $frame, $offset) : [];
		$offset += 2 * $single;
		$singleBlocks = $single > 0 ? unpack("V$single", $frame, $offset) : [];
		$offset += 4 * $single;
		$singleWater = substr($frame, $offset, $single);
		$offset += $single;
		if(!is_array($singleSections) || !is_array($singleIndexes) || !is_array($singleBlocks)){
			throw new NativeBackendException("malformed decoration writes");
		}
		/** @phpstan-var array<int, int> $keys */
		/** @phpstan-var array<int, int> $states */
		if($count === 0){
			$fast = true; //nothing written
		}elseif(count($tiles) === 0){
			/** @phpstan-var array<int, int> $singleSections */
			/** @phpstan-var array<int, int> $singleIndexes */
			/** @phpstan-var array<int, int> $singleBlocks */
			/** @phpstan-var list<array{int, int, int, int, int, int, list<array{int, string, list<int>}>}> $sections */
			try{
				$fast = $level->applyNativeLayers($keys, $states, $chunkMask, $sections, $singleSections, $singleIndexes, $singleBlocks, $singleWater);
			}catch(\UnexpectedValueException $e){
				//nothing was applied: the decorator places the batch in PHP and stops using the native side
				throw new NativeBackendException("native decoration layers out of sync: " . $e->getMessage(), 0, $e);
			}
		}else{
			$fast = false;
		}
		if(!$fast){
			$level->applyNativeWrites($keys, $states, $tiles);
			++$this->stats["slow"];
		}else{
			$this->stats["layers"] += count($sections);
			$this->stats["single"] += $single;
		}
		$this->stats["apply"] += hrtime(true) - $start;
		$this->stats["writes"] += $count;
	}

	/**
	 * Block entity data of a native record (decor/region.go: BlockEntity* kinds).
	 * @throws NativeBackendException
	 */
	private static function blockEntity(string $record) : CompoundTag{
		$offset = 1;
		switch(ord($record[0] ?? "\0")){
			case 1:
				$n = ord($record[1] ?? "\0");
				$ticks = $n > 0 ? unpack("v$n", $record, 2) : [];
				if(!is_array($ticks) || strlen($record) !== 2 + 2 * $n){
					throw new NativeBackendException("malformed beehive record");
				}
				$list = [];
				foreach($ticks as $t){
					$list[] = is_int($t) ? $t : throw new NativeBackendException("malformed beehive record");
				}
				return BlockEntityData::beehive($list);
			case 2:
				$tile = self::readStr($record, $offset);
				$table = self::readStr($record, $offset);
				$seed = unpack("P", $record, $offset);
				if(!is_array($seed) || strlen($record) !== $offset + 8){
					throw new NativeBackendException("malformed loot container record");
				}
				return BlockEntityData::lootContainer($tile, $table, (int) $seed[1]);
			case 3:
				return BlockEntityData::spawner(self::readStr($record, $offset));
		}
		throw new NativeBackendException("unknown block entity record");
	}

	/**
	 * @throws NativeBackendException
	 */
	private static function u16(string $data, int &$offset) : int{
		if(strlen($data) < $offset + 2){
			throw new NativeBackendException("truncated message");
		}
		$v = unpack("v", $data, $offset);
		$offset += 2;
		return is_array($v) ? (int) $v[1] : 0;
	}

	/**
	 * @throws NativeBackendException
	 */
	private static function u32(string $data, int &$offset) : int{
		if(strlen($data) < $offset + 4){
			throw new NativeBackendException("truncated message");
		}
		$v = unpack("V", $data, $offset);
		$offset += 4;
		return is_array($v) ? (int) $v[1] : 0;
	}

	/**
	 * @throws NativeBackendException
	 */
	private static function readStr(string $data, int &$offset) : string{
		$length = self::u16($data, $offset);
		if(strlen($data) < $offset + $length){
			throw new NativeBackendException("truncated message");
		}
		$s = substr($data, $offset, $length);
		$offset += $length;
		return $s;
	}
}
