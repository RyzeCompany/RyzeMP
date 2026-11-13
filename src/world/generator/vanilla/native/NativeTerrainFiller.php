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
use pocketmine\world\generator\vanilla\TerrainFiller;
use function realpath;

/**
 * Terrain filler backed by the vanillagen helper process: same output as the PHP fillers, several times faster.
 * On any failure (helper missing, crashed, too old...) it logs once and permanently switches this generator to the
 * PHP filler built by the fallback factory.
 */
final class NativeTerrainFiller implements TerrainFiller{
	private ?TerrainFiller $fallback = null;
	private static bool $reportedNativeConnection = false;

	/**
	 * @phpstan-param \Closure() : TerrainFiller $fallbackFactory
	 */
	public function __construct(
		private ?NativeTerrainClient $client,
		private \Closure $fallbackFactory
	){}

	/**
	 * Connects to the helper published by the main thread ({@link VanillaNativeBackend}).
	 * Returns null (and logs why, unless no helper was published) if the native core cannot be used.
	 *
	 * @param string[] $biomeNames biome names of the PHP biome tree, in index order
	 * @phpstan-param list<string> $biomeNames
	 * @phpstan-param \Closure() : TerrainFiller $fallbackFactory
	 * @param int[] $bedrockBiomes Bedrock biome id per biome name (for FLAG_PACKED_BIOMES)
	 * @phpstan-param list<int> $bedrockBiomes
	 */
	public static function connect(string $dataDir, int $seed, array $biomeNames, int $minY, int $height, \Closure $fallbackFactory, int $flags = 0, string $settings = "overworld", array $bedrockBiomes = []) : ?self{
		$endpoint = VanillaNativeBackend::endpoint();
		if($endpoint === null){
			return null;
		}
		[$host, $port, $token] = $endpoint;
		$realDir = realpath($dataDir);
		try{
			$client = new NativeTerrainClient($host, $port, $token, $realDir !== false ? $realDir : $dataDir, $seed, $flags, settings: $settings, bedrockBiomes: $bedrockBiomes);
		}catch(NativeBackendException $e){
			\GlobalLogger::get()->warning("Vanilla generator: native terrain core unavailable (" . $e->getMessage() . "), using the PHP implementation");
			return null;
		}
		if($client->biomeNames !== $biomeNames || $client->minY !== $minY || $client->height !== $height){
			$client->close();
			\GlobalLogger::get()->warning("Vanilla generator: the native terrain core reads different worldgen data than PHP, using the PHP implementation");
			return null;
		}
		if(!self::$reportedNativeConnection){
			self::$reportedNativeConnection = true;
			\GlobalLogger::get()->info("[Worldgen Go] Native terrain session verified (protocol " . VanillaNativeBackend::PROTOCOL_VERSION .
				", dimension " . $settings . ", material/carver flags " . $flags . ")");
		}
		return new self($client, $fallbackFactory);
	}

	public function getClient() : ?NativeTerrainClient{
		return $this->client;
	}

	public function fill(int $chunkX, int $chunkZ, ?Beardifier $beardifier = null) : TerrainChunk{
		if($this->client !== null){
			try{
				return $this->client->fill($chunkX, $chunkZ, $beardifier);
			}catch(NativeBackendException $e){
				\GlobalLogger::get()->warning("Vanilla generator: native terrain core failed (" . $e->getMessage() . "), switching to the PHP implementation");
				$this->client->close();
				$this->client = null;
			}
		}
		return ($this->fallback ??= ($this->fallbackFactory)())->fill($chunkX, $chunkZ, $beardifier);
	}
}
