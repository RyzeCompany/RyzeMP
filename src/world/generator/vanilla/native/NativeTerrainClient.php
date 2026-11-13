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

use pocketmine\world\generator\vanilla\structure\Beardifier;
use pocketmine\world\generator\vanilla\TerrainChunk;
use function array_fill;
use function array_merge;
use function array_values;
use function chr;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function intdiv;
use function is_array;
use function is_int;
use function is_resource;
use function ord;
use function pack;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;
use function unpack;
use const STREAM_CLIENT_CONNECT;

/**
 * One connection to the vanillagen helper, bound to a seed (protocol: native/vanillagen/protocol.go).
 * Not thread-safe: every generator instance (one per async worker) owns its own client.
 */
final class NativeTerrainClient{
	private const MSG_HELLO = 0x01;
	private const MSG_FILL = 0x02;
	private const MSG_CLASSES = 0x06;
	private const MSG_READY = 0x81;
	private const MSG_CHUNK = 0x82;
	private const MSG_ERROR = 0xff;

	public const FLAG_NO_AQUIFERS = 1;
	public const FLAG_DEEPSLATE_FLOATS = 2;
	/** Also apply the material rules: chunks come back in the program's own material palette. */
	public const FLAG_MATERIALS = 4;
	/** Also run the carvers (needs FLAG_MATERIALS). */
	public const FLAG_CARVERS = 8;
	/**
	 * Chunks come with their subchunks already packed for PalettedBlockArray::fromData() (TerrainChunk::$sections) and
	 * the materials as a byte string (TerrainChunk::$materialBytes) instead of a PHP array: no per-block PHP loop.
	 */
	public const FLAG_PACKED_SECTIONS = 16;
	/**
	 * Chunks also come with the biome arrays of their subchunks packed for PalettedBlockArray::fromData()
	 * (TerrainChunk::$biomeSections) and, once {@link sendHeightClasses()} was called, with the terrain heights
	 * (TerrainChunk::$terrainHeights). Needs FLAG_PACKED_SECTIONS and the Bedrock biome ids in the constructor.
	 */
	public const FLAG_PACKED_BIOMES = 32;

	/** Largest frame accepted (decoration requests carry a whole 3x3 chunk region). */
	public const MAX_FRAME = 64 << 20;

	/** @var resource|null */
	private $socket;

	public readonly string $dataFingerprint;
	public readonly string $version;
	public readonly int $minY;
	public readonly int $height;
	/** FLAG_* given to the server in HELLO */
	private int $flags;
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public readonly array $biomeNames;
	/**
	 * Java block ids of the material ids of chunks past the terrain stage (empty without FLAG_MATERIALS).
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public readonly array $palette;

	/**
	 * @throws NativeBackendException
	 */
	/**
	 * @param string $settings      noise settings: the dimension ("overworld", "nether", "end")
	 * @param int[]  $bedrockBiomes Bedrock biome id of every biome of the generator, in the order of its biome names
	 *                              (sent with FLAG_PACKED_BIOMES)
	 * @phpstan-param list<int> $bedrockBiomes
	 */
	public function __construct(string $host, int $port, string $token, string $dataDir, int $seed, int $flags = 0, float $timeout = 60.0, string $settings = "overworld", array $bedrockBiomes = []){
		if(($flags & self::FLAG_PACKED_BIOMES) !== 0 && (($flags & self::FLAG_PACKED_SECTIONS) === 0 || count($bedrockBiomes) === 0)){
			$flags &= ~self::FLAG_PACKED_BIOMES;
		}
		$this->flags = $flags;
		$errno = 0;
		$errstr = "";
		//no Nagle: requests are often written right after a large frame whose last segment is not acknowledged yet
		$socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, stream_context_create(["socket" => ["tcp_nodelay" => true]]));
		if($socket === false){
			throw new NativeBackendException("cannot connect to $host:$port: $errstr ($errno)");
		}
		stream_set_timeout($socket, (int) $timeout);
		$this->socket = $socket;

		$this->send(pack("Ca4v", self::MSG_HELLO, "PMVG", VanillaNativeBackend::PROTOCOL_VERSION) .
			self::str($token) . self::str($dataDir) . self::str($settings) . pack("PV", $seed, $flags) .
			(($flags & self::FLAG_PACKED_BIOMES) !== 0 ? pack("v", count($bedrockBiomes)) . pack("V*", ...$bedrockBiomes) : ""));
		$frame = $this->receive();
		$offset = 1;
		if(ord($frame[0]) !== self::MSG_READY){
			throw new NativeBackendException("unexpected handshake reply");
		}
		$algorithm = self::u32($frame, $offset);
		if($algorithm !== VanillaNativeBackend::ALGORITHM_VERSION){
			$this->close();
			throw new NativeBackendException("algorithm version mismatch (program $algorithm, server " . VanillaNativeBackend::ALGORITHM_VERSION . "): update the vanillagen program");
		}
		$this->dataFingerprint = self::readStr($frame, $offset);
		$this->version = self::readStr($frame, $offset);
		$this->minY = self::i32($frame, $offset);
		$this->height = self::i32($frame, $offset);
		$count = self::u16($frame, $offset);
		$names = [];
		for($i = 0; $i < $count; ++$i){
			$names[] = self::readStr($frame, $offset);
		}
		$this->biomeNames = $names;
		$count = self::u16($frame, $offset);
		$palette = [];
		for($i = 0; $i < $count; ++$i){
			$palette[] = self::readStr($frame, $offset);
		}
		$this->palette = $palette;
	}

	public function __destruct(){
		$this->close();
	}

	public function close() : void{
		if(is_resource($this->socket)){
			@fclose($this->socket);
		}
		$this->socket = null;
	}

	/**
	 * @throws NativeBackendException
	 */
	public function fill(int $chunkX, int $chunkZ, ?Beardifier $beardifier = null) : TerrainChunk{
		$this->send(pack("CVV", self::MSG_FILL, $chunkX & 0xffffffff, $chunkZ & 0xffffffff) . ($beardifier !== null ? $beardifier->encode() : ""));
		$frame = $this->receive();
		if(ord($frame[0]) !== self::MSG_CHUNK){
			throw new NativeBackendException("unexpected reply");
		}
		$offset = 1;
		$cx = self::i32($frame, $offset);
		$cz = self::i32($frame, $offset);
		if($cx !== $chunkX || $cz !== $chunkZ){
			throw new NativeBackendException("reply for the wrong chunk");
		}
		$count = self::u32($frame, $offset);
		if($count !== 256 * $this->height){
			throw new NativeBackendException("bad chunk size");
		}
		$packed = ($this->flags & self::FLAG_PACKED_SECTIONS) !== 0;
		$materialBytes = null;
		$values = [];
		$lengths = [];
		$runCount = 0;
		if($packed){
			if(strlen($frame) < $offset + $count + 4){
				throw new NativeBackendException("bad chunk size");
			}
			$materialBytes = substr($frame, $offset, $count);
			$offset += $count;
		}else{
			$runCount = self::u32($frame, $offset);
			if($runCount <= 0 || strlen($frame) < $offset + 3 * $runCount + 4){
				throw new NativeBackendException("bad chunk size");
			}
			$values = unpack("C$runCount", $frame, $offset);
			$offset += $runCount;
			$lengths = unpack("v$runCount", $frame, $offset);
			$offset += 2 * $runCount;
		}
		$biomeCount = self::u32($frame, $offset);
		if($biomeCount !== 16 * ($this->height >> 2) || strlen($frame) < $offset + 2 * $biomeCount + 5){
			throw new NativeBackendException("bad biome count");
		}
		//with FLAG_PACKED_BIOMES the cells are only unpacked if PHP needs them (TerrainChunk::unpackBiomes())
		$lazy = ($this->flags & self::FLAG_PACKED_BIOMES) !== 0;
		$biomeBytes = $lazy ? substr($frame, $offset, 2 * $biomeCount) : null;
		$biomes = $lazy ? [] : unpack("v$biomeCount", $frame, $offset);
		$offset += 2 * $biomeCount;
		$stage = ord($frame[$offset++]);
		$maskRuns = self::u32($frame, $offset);
		if($stage > TerrainChunk::STAGE_CARVED || ($stage !== TerrainChunk::STAGE_TERRAIN && count($this->palette) === 0) || strlen($frame) < $offset + 6 * $maskRuns || (!$packed && strlen($frame) !== $offset + 6 * $maskRuns)){
			throw new NativeBackendException("bad chunk stage or carving mask");
		}
		$mask = [];
		$maskBytes = null;
		if($lazy){
			//nothing reads the mask while generating: kept packed, see TerrainChunk::getCarvingMask()
			$maskBytes = substr($frame, $offset, 6 * $maskRuns);
			$offset += 6 * $maskRuns;
		}else{
			for($i = 0; $i < $maskRuns; ++$i){
				$start = self::u32($frame, $offset);
				$length = self::u16($frame, $offset);
				if($length <= 0 || $start + $length > $count){
					throw new NativeBackendException("bad carving mask");
				}
				$mask += array_fill($start, $length, true);
			}
		}
		if($values === false || $lengths === false || $biomes === false){
			throw new NativeBackendException("bad chunk data");
		}
		$result = new TerrainChunk($chunkX, $chunkZ, $this->minY, $this->height);
		if($packed){
			$paletteSize = $stage !== TerrainChunk::STAGE_TERRAIN ? count($this->palette) : 4;
			$result->materialBytes = $materialBytes;
			$result->sections = $this->readPackedSections($frame, $offset, $paletteSize);
			if(($this->flags & self::FLAG_PACKED_BIOMES) !== 0){
				$result->biomeSections = $this->readBiomeSections($frame, $offset);
				$hasHeights = ord($frame[$offset++] ?? "\xff");
				if($hasHeights === 1){
					if(strlen($frame) < $offset + 1024){
						throw new NativeBackendException("truncated terrain heights");
					}
					$result->terrainHeights = substr($frame, $offset, 1024);
					$offset += 1024;
				}elseif($hasHeights !== 0){
					throw new NativeBackendException("bad terrain heights marker");
				}
			}
			if($offset !== strlen($frame)){
				throw new NativeBackendException("trailing chunk data");
			}
		}else{
			//runs of identical materials: array_fill() + one array_merge() is several times faster than unpacking a
			//byte per block
			$parts = [];
			$total = 0;
			for($i = 1; $i <= $runCount; ++$i){
				$material = $values[$i] ?? null;
				$length = $lengths[$i] ?? null;
				if(!is_int($material) || !is_int($length) || $length <= 0 || ($stage !== TerrainChunk::STAGE_TERRAIN && $material >= count($this->palette))){
					throw new NativeBackendException("bad material runs");
				}
				$parts[] = array_fill(0, $length, $material);
				$total += $length;
			}
			if($total !== $count){
				throw new NativeBackendException("bad material runs");
			}
			/** @phpstan-var list<int> $materials */
			$materials = array_merge(...$parts);
			$result->materials = $materials;
		}
		/** @phpstan-var array<int, int> $biomes */
		$result->biomes = array_values($biomes);
		$result->biomeBytes = $biomeBytes;
		$result->carvingMaskRuns = $maskBytes;
		$result->stage = $stage;
		if($stage !== TerrainChunk::STAGE_TERRAIN){
			$result->palette = $this->palette;
		}
		/** @phpstan-var array<int, true> $mask */
		$result->carvingMask = $mask;
		return $result;
	}

	/**
	 * CPU time used so far by the whole vanillagen process, in nanoseconds (benchmarks).
	 *
	 * @throws NativeBackendException
	 */
	public function processCpuTime() : int{
		$this->send(chr(0x05));
		$frame = $this->receive();
		$value = strlen($frame) === 9 && ord($frame[0]) === 0x85 ? unpack("P", $frame, 1) : false;
		if(!is_array($value) || !is_int($value[1])){
			throw new NativeBackendException("unexpected STATS reply");
		}
		return $value[1];
	}

	private static function str(string $s) : string{
		return pack("v", strlen($s)) . $s;
	}

	/**
	 * @throws NativeBackendException
	 */
	public function send(string $payload) : void{
		$data = pack("V", strlen($payload)) . $payload;
		$socket = $this->socket ?? throw new NativeBackendException("connection closed");
		$length = strlen($data);
		$written = 0;
		while($written < $length){
			$n = @fwrite($socket, $written === 0 ? $data : substr($data, $written));
			if($n === false || $n === 0){
				$this->close();
				throw new NativeBackendException("connection lost (write)");
			}
			$written += $n;
		}
	}

	/**
	 * @throws NativeBackendException
	 */
	private function read(int $length) : string{
		$socket = $this->socket ?? throw new NativeBackendException("connection closed");
		$data = "";
		while(($remaining = $length - strlen($data)) > 0){
			$chunk = @fread($socket, $remaining);
			if($chunk === false || $chunk === ""){
				$timedOut = stream_get_meta_data($socket)["timed_out"];
				$this->close();
				throw new NativeBackendException($timedOut ? "timed out" : "connection lost");
			}
			$data .= $chunk;
		}
		return $data;
	}

	/**
	 * @throws NativeBackendException
	 */
	public function receive() : string{
		$header = $this->read(4);
		$headerOffset = 0;
		$length = self::u32($header, $headerOffset);
		if($length <= 0 || $length > self::MAX_FRAME){
			$this->close();
			throw new NativeBackendException("invalid frame");
		}
		$frame = $this->read($length);
		if(ord($frame[0]) === self::MSG_ERROR){
			$offset = 1;
			$message = self::readStr($frame, $offset);
			$this->close();
			throw new NativeBackendException("vanillagen: " . $message);
		}
		return $frame;
	}

	/**
	 * @throws NativeBackendException
	 */
	private static function unpackInt(string $format, int $size, string $data, int &$offset) : int{
		if(strlen($data) < $offset + $size){
			throw new NativeBackendException("truncated message");
		}
		$v = unpack($format, $data, $offset);
		if(!is_array($v) || !isset($v[1]) || !is_int($v[1])){
			throw new NativeBackendException("truncated message");
		}
		$offset += $size;
		return $v[1];
	}

	/**
	 * Reads the packed subchunks of a CHUNK reply (FLAG_PACKED_SECTIONS), see TerrainChunk::$sections.
	 *
	 * @return array<int, array{int, string, list<int>}|null>
	 * @throws NativeBackendException
	 */
	private function readPackedSections(string $frame, int &$offset, int $paletteSize) : array{
		if(strlen($frame) < $offset + 1){
			throw new NativeBackendException("missing packed sections");
		}
		$sectionCount = ord($frame[$offset++]);
		if($sectionCount !== $this->height >> 4){
			throw new NativeBackendException("bad packed section count $sectionCount");
		}
		$sections = [];
		for($s = 0; $s < $sectionCount; ++$s){
			if(strlen($frame) < $offset + 1){
				throw new NativeBackendException("truncated packed section");
			}
			$paletteCount = ord($frame[$offset++]);
			if($paletteCount === 0){
				$sections[$s] = null;
				continue;
			}
			if(strlen($frame) < $offset + $paletteCount + 5){
				throw new NativeBackendException("truncated packed section");
			}
			$palette = unpack("C$paletteCount", $frame, $offset);
			$offset += $paletteCount;
			if($palette === false){
				throw new NativeBackendException("bad packed section palette");
			}
			$palette = array_values($palette);
			foreach($palette as $material){
				if($material >= $paletteSize){
					throw new NativeBackendException("bad material $material in a packed section");
				}
			}
			$bits = ord($frame[$offset++]);
			$wordBytes = self::u32($frame, $offset);
			$blocksPerWord = $bits > 0 ? intdiv(32, $bits) : 0;
			if($blocksPerWord === 0 || $wordBytes !== 4 * intdiv(4096 + $blocksPerWord - 1, $blocksPerWord) || (1 << $bits) < $paletteCount || strlen($frame) < $offset + $wordBytes){
				throw new NativeBackendException("bad packed section layout");
			}
			$sections[$s] = [$bits, substr($frame, $offset, $wordBytes), $palette];
			$offset += $wordBytes;
		}
		return $sections;
	}

	/**
	 * Reads the packed biome arrays of a CHUNK reply (FLAG_PACKED_BIOMES), see TerrainChunk::$biomeSections.
	 *
	 * @return array<int, array{int, string, list<int>}>
	 * @throws NativeBackendException
	 */
	private function readBiomeSections(string $frame, int &$offset) : array{
		$sections = [];
		for($s = 0, $count = $this->height >> 4; $s < $count; ++$s){
			if(strlen($frame) < $offset + 3){
				throw new NativeBackendException("truncated biome section");
			}
			$bits = ord($frame[$offset++]);
			$paletteCount = self::u16($frame, $offset);
			$blocksPerWord = $bits > 0 ? intdiv(32, $bits) : 0;
			$wordBytes = $bits > 0 ? 4 * intdiv(4096 + $blocksPerWord - 1, $blocksPerWord) : 0;
			if($paletteCount === 0 || ($bits === 0 ? $paletteCount !== 1 : ($bits > 16 || (1 << $bits) < $paletteCount)) || strlen($frame) < $offset + 4 * $paletteCount + $wordBytes){
				throw new NativeBackendException("bad biome section layout");
			}
			$palette = unpack("V$paletteCount", $frame, $offset);
			if($palette === false){
				throw new NativeBackendException("bad biome section palette");
			}
			$offset += 4 * $paletteCount;
			/** @phpstan-var list<int> $palette */
			$palette = array_values($palette);
			$sections[$s] = [$bits, $wordBytes > 0 ? substr($frame, $offset, $wordBytes) : "", $palette];
			$offset += $wordBytes;
		}
		return $sections;
	}

	/**
	 * Gives the server the height class of every material of the palette (0 air, 1 solid, 2 blocks motion in
	 * heightmaps, as FeatureDecorator::computeTerrainHeights() sees the blocks): later chunks come with their terrain
	 * heights (TerrainChunk::$terrainHeights). Needs FLAG_PACKED_BIOMES.
	 *
	 * @param int[] $classes by material id
	 * @phpstan-param list<int> $classes
	 *
	 * @throws NativeBackendException
	 */
	public function sendHeightClasses(array $classes) : void{
		if(($this->flags & self::FLAG_PACKED_BIOMES) === 0 || count($classes) !== count($this->palette)){
			throw new \InvalidArgumentException("Height classes need FLAG_PACKED_BIOMES and one class per material");
		}
		$this->send(chr(self::MSG_CLASSES) . pack("v", count($classes)) . (count($classes) > 0 ? pack("C*", ...$classes) : ""));
	}

	public function hasFlag(int $flag) : bool{
		return ($this->flags & $flag) === $flag;
	}

	private static function u16(string $data, int &$offset) : int{
		return self::unpackInt("v", 2, $data, $offset);
	}

	private static function u32(string $data, int &$offset) : int{
		return self::unpackInt("V", 4, $data, $offset);
	}

	private static function i32(string $data, int &$offset) : int{
		$v = self::unpackInt("V", 4, $data, $offset);
		return $v >= 0x80000000 ? $v - 0x100000000 : $v;
	}

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
