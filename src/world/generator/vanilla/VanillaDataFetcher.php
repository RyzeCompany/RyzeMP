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

use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\biome\ClimateBiomeTree;
use function array_map;
use function array_search;
use function array_slice;
use function array_values;
use function basename;
use function copy;
use function count;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function defined;
use function dirname;
use function explode;
use function fclose;
use function file_exists;
use function filesize;
use function fopen;
use function getenv;
use function getmypid;
use function glob;
use function hash;
use function hash_file;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function min;
use function mkdir;
use function number_format;
use function php_uname;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function round;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function time;
use function unlink;
use function usleep;
use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FILE;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOPROGRESS;
use const CURLOPT_PROGRESSFUNCTION;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_OPTIONS;
use const CURLOPT_TIMEOUT;
use const CURLSSLOPT_NATIVE_CA;
use const GLOB_ONLYDIR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_INT_MAX;
use const PHP_OS_FAMILY;

/**
 * Builds the local cache of vanilla worldgen data from Mojang's own Java Edition server, on the server owner's
 * machine. The data is © Mojang Studios (used under the Minecraft EULA) and is never shipped with PocketMine-MP.
 *
 * Pipeline (runs once per {@link MINECRAFT_VERSION} / {@link DATA_FORMAT}):
 *  1. Mojang version manifest -> version JSON (sha1-checked) -> server.jar (sha1- and size-checked).
 *  2. A Java runtime of the major version the server needs: $POCKETMINE_VANILLA_JAVA if set, otherwise a portable
 *     Eclipse Temurin JRE for this OS/architecture from api.adoptium.net (SHA-256-checked).
 *  3. Mojang's data generator: java -DbundlerMainClass=net.minecraft.data.Main -jar server.jar --server --reports
 *  4. Only {@link EXTRACTED_PATHS} are copied into the cache, plus a compact Java block state list, the structure
 *     templates used by features (from the server jar) and GeyserMC's block mappings; {@link STAMP_FILE} is written last.
 * Everything else (JRE, server.jar, generator output) lives in a temporary directory inside the cache directory and
 * is deleted afterwards: the job only runs again when the target data version changes (a PocketMine-MP update), so
 * keeping ~200 MB of runtime around for months is not worth saving a ~45 MB download.
 */
final class VanillaDataFetcher{
	/**
	 * Java Edition release whose worldgen matches Bedrock 1.26.5x content (first release with dappled_forest; terrain
	 * noise and density functions identical to the data the generator was verified against).
	 */
	public const MINECRAFT_VERSION = "26.3";
	/**
	 * Bump when EXTRACTED_PATHS or the cache layout change, to force a refetch.
	 * 2: features (feature, placed_feature, block_state_provider, processor_list), carvers, block and fluid tags,
	 *    the Java block state list, the structure templates used by features and GeyserMC's block mappings.
	 * 3: loot tables and what they reference (predicates, enchantments, enchantment providers, item/enchantment/entity
	 *    type, instrument tags), a compact item component list and GeyserMC's item mappings.
	 * 4: the Nether biome parameters (and their search tree).
	 * 5: structures: worldgen/structure, structure_set, template_pool, biome tags (has_structure/...) and every
	 *    structure template (.nbt) of the server jar.
	 */
	public const DATA_FORMAT = 5;

	public const STAMP_FILE = "version.json";
	public const LOCK_FILE = ".fetch.lock";
	/** Biome search trees built from the biome parameters reports (vanilla's R-tree layout), by dimension. */
	public const BIOME_TREE_FILE = "multi_noise_biome_tree.json";
	/** Compact Java block state list (ids and properties), built from the blocks report. */
	public const JAVA_BLOCKS_FILE = "java_blocks.json";
	/** Compact Java item list (max stack size, max damage, enchantability), built from the item component reports. */
	public const JAVA_ITEMS_FILE = "java_items.json";
	/** Directory of the item component reports in the data generator output. */
	private const ITEM_COMPONENTS_REPORT = "reports/minecraft/components/item";
	/** Directory of the structure templates (.nbt) used by features, copied from the server jar. */
	public const TEMPLATE_DIR = "data/minecraft/structure";
	/** Optional path to a java executable (major version >= the one the server.jar needs) to skip the JRE download. */
	public const JAVA_ENV = "POCKETMINE_VANILLA_JAVA";

	public const EXTRACTED_PATHS = [
		"data/minecraft/worldgen/noise",
		"data/minecraft/worldgen/density_function",
		"data/minecraft/worldgen/noise_settings",
		"data/minecraft/worldgen/material_rule",
		"data/minecraft/worldgen/material_condition",
		"data/minecraft/worldgen/biome",
		"data/minecraft/worldgen/multi_noise_biome_source_parameter_list",
		"data/minecraft/worldgen/feature",
		"data/minecraft/worldgen/placed_feature",
		"data/minecraft/worldgen/block_state_provider",
		"data/minecraft/worldgen/processor_list",
		//configured carvers (called "configured_carver" before 26.1)
		"data/minecraft/worldgen/carver",
		//structures (format 5)
		"data/minecraft/worldgen/structure",
		"data/minecraft/worldgen/structure_set",
		"data/minecraft/worldgen/template_pool",
		"data/minecraft/tags/worldgen/biome",
		"data/minecraft/tags/block",
		"data/minecraft/tags/fluid",
		//loot tables ("loot_tables" before 1.21) and what they reference
		"data/minecraft/loot_table",
		"data/minecraft/predicate",
		"data/minecraft/enchantment",
		"data/minecraft/enchantment_provider",
		"data/minecraft/tags/item",
		"data/minecraft/tags/enchantment",
		"data/minecraft/tags/entity_type",
		"data/minecraft/tags/instrument",
		"reports/biome_parameters/minecraft/overworld.json",
		"reports/biome_parameters/minecraft/nether.json",
	];
	/** Dimensions with a multi-noise biome source: their parameters report is turned into a search tree. */
	public const MULTI_NOISE_DIMENSIONS = ["overworld", "nether"];

	/**
	 * Java -> Bedrock block mappings by GeyserMC (MIT, https://github.com/GeyserMC/mappings), pinned to the commit made
	 * for this {@link MINECRAFT_VERSION}: blocks.nbt (Bedrock state of every Java block state, in Java state id order)
	 * and collisions.nbt (Java collision boxes, used for the "solid" and "blocks motion" checks of features).
	 * Update the commit and the hashes together with MINECRAFT_VERSION.
	 */
	public const GEYSER_MAPPINGS_URL = "https://raw.githubusercontent.com/GeyserMC/mappings/81700ab543c1f4e9f9041f58dac4154ecb0d0c8b/";
	public const GEYSER_FILES = [
		"blocks.nbt" => "e0a442e63761b9d67dbcf17fceafb449bb6e1a58c98d8c60c6ac1a3128e3a5ef",
		"collisions.nbt" => "92c201bc7d9917c3d877a3b4d5112086955d978aa5b70a0aecdce13de72d4dc8",
		//Bedrock item id and meta of every Java item (loot tables)
		"items.json" => "89ec17a95b005e5dac170d7834a18da79952c914e8f29201f6fe9a1cdb992179",
	];
	public const GEYSER_DIR = "geyser";

	private const VERSION_MANIFEST_URL = "https://piston-meta.mojang.com/mc/game/version_manifest_v2.json";
	private const ADOPTIUM_URL = "https://api.adoptium.net/v3/assets/latest/%d/hotspot?architecture=%s&image_type=jre&os=%s&vendor=eclipse";
	private const TMP_PREFIX = ".tmp-fetch-";
	private const DATAGEN_TIMEOUT = 900;

	private function __construct(){
		//NOOP
	}

	/**
	 * @return mixed[]|null
	 */
	public static function readStamp(string $root) : ?array{
		$path = $root . "/" . self::STAMP_FILE;
		if(!is_file($path)){
			return null;
		}
		try{
			$stamp = json_decode(Filesystem::fileGetContents($path), true, 8, JSON_THROW_ON_ERROR);
		}catch(\JsonException|\RuntimeException){
			return null;
		}
		return is_array($stamp) ? $stamp : null;
	}

	public static function isReady(string $root) : bool{
		$stamp = self::readStamp($root);
		return $stamp !== null && ($stamp["minecraft"] ?? null) === self::MINECRAFT_VERSION && ($stamp["format"] ?? null) === self::DATA_FORMAT &&
			is_file($root . "/" . self::BIOME_TREE_FILE) && is_file($root . "/" . self::JAVA_BLOCKS_FILE);
	}

	/**
	 * Temurin platform names for this machine, or null if no portable runtime is published for it.
	 * @phpstan-return array{os: string, arch: string, archive: string}|null
	 */
	public static function detectPlatform() : ?array{
		$os = match(PHP_OS_FAMILY){
			"Windows" => "windows",
			"Darwin" => "mac",
			"Linux" => file_exists("/etc/alpine-release") ? "alpine-linux" : "linux",
			default => null
		};
		$arch = match(strtolower(php_uname("m"))){
			"x86_64", "amd64", "x64" => "x64",
			"aarch64", "arm64" => "aarch64",
			default => null
		};
		if($os === null || $arch === null){
			return null;
		}
		return ["os" => $os, "arch" => $arch, "archive" => $os === "windows" ? "zip" : "tar.gz"];
	}

	/**
	 * Returns why the data cannot be fetched automatically on this machine, or null if it can.
	 */
	public static function checkPlatform() : ?string{
		$java = getenv(self::JAVA_ENV);
		if(is_string($java) && $java !== ""){
			return is_file($java) ? null : self::JAVA_ENV . " points to $java, which does not exist";
		}
		if(self::detectPlatform() === null){
			return "no portable Java runtime is available for " . PHP_OS_FAMILY . "/" . php_uname("m") . "; install Java 25+ and set " . self::JAVA_ENV . " to its java executable";
		}
		return null;
	}

	/**
	 * Runs the whole pipeline synchronously (minutes). Thread-safe as long as each root is handled by one job.
	 *
	 * @phpstan-param \Closure(string) : void $logger
	 * @phpstan-param (\Closure() : void)|null $heartbeat called regularly while working
	 */
	public static function fetch(string $root, \Closure $logger, ?\Closure $heartbeat = null) : void{
		$heartbeat ??= function() : void{};
		$start = microtime(true);
		if(!is_dir($root) && !@mkdir($root, 0777, true) && !is_dir($root)){
			throw new \RuntimeException("Cannot create $root");
		}
		$staleDirs = glob($root . "/" . self::TMP_PREFIX . "*", GLOB_ONLYDIR);
		foreach($staleDirs !== false ? $staleDirs : [] as $stale){
			Filesystem::recursiveUnlink($stale);
		}
		$tmp = $root . "/" . self::TMP_PREFIX . getmypid() . "-" . time();
		if(!@mkdir($tmp, 0777, true)){
			throw new \RuntimeException("Cannot create $tmp");
		}
		try{
			$logger("Preparing Minecraft Java Edition " . self::MINECRAFT_VERSION . " worldgen data in $root (once per data version; the data is (c) Mojang, used under the Minecraft EULA)");

			[$serverUrl, $serverSha1, $serverSize, $javaMajor] = self::resolveServerJar();
			$java = self::prepareJava($tmp, $javaMajor, $logger, $heartbeat);

			$logger("Downloading Minecraft " . self::MINECRAFT_VERSION . " server.jar (" . self::megabytes($serverSize) . ")");
			$jar = $tmp . "/server.jar";
			self::download($serverUrl, $jar, $heartbeat);
			self::verify($jar, "sha1", $serverSha1);
			if(filesize($jar) !== $serverSize){
				throw new \RuntimeException("server.jar size mismatch");
			}

			$logger("Running Mojang's data generator (about 30-60 s)");
			$genStart = microtime(true);
			self::runDataGenerator($java, $tmp, $heartbeat);
			$logger("Data generator finished in " . round(microtime(true) - $genStart) . " s");

			[$files, $bytes] = self::install($tmp . "/generated", $root);

			$javaBlocks = json_encode(self::compactBlocksReport($tmp . "/generated/reports/blocks.json"), JSON_THROW_ON_ERROR);
			Filesystem::safeFilePutContents($root . "/" . self::JAVA_BLOCKS_FILE, $javaBlocks);
			$files++;
			$bytes += strlen($javaBlocks);

			$javaItems = json_encode(self::compactItemReports($tmp . "/generated/" . self::ITEM_COMPONENTS_REPORT), JSON_THROW_ON_ERROR);
			Filesystem::safeFilePutContents($root . "/" . self::JAVA_ITEMS_FILE, $javaItems);
			$files++;
			$bytes += strlen($javaItems);

			[$templateFiles, $templateBytes] = self::extractTemplates($tmp, $root);
			$files += $templateFiles;
			$bytes += $templateBytes;

			$logger("Downloading GeyserMC's Java -> Bedrock block mappings (MIT)");
			@mkdir($root . "/" . self::GEYSER_DIR, 0777, true);
			foreach(self::GEYSER_FILES as $name => $sha256){
				$target = $root . "/" . self::GEYSER_DIR . "/" . $name;
				self::download(self::GEYSER_MAPPINGS_URL . $name, $target, $heartbeat);
				self::verify($target, "sha256", $sha256);
				$files++;
				$bytes += (int) filesize($target);
			}

			$worldgenData = new WorldgenData($root);
			$trees = [];
			foreach(self::MULTI_NOISE_DIMENSIONS as $dimension){
				$trees[$dimension] = ClimateBiomeTree::buildTree(ClimateBiomeTree::readParameterPoints($worldgenData->biomeParameters($dimension)));
			}
			$tree = json_encode($trees, JSON_THROW_ON_ERROR);
			Filesystem::safeFilePutContents($root . "/" . self::BIOME_TREE_FILE, $tree);
			$files++;
			$bytes += strlen($tree);
			Filesystem::safeFilePutContents($root . "/" . self::STAMP_FILE, json_encode([
				"minecraft" => self::MINECRAFT_VERSION,
				"format" => self::DATA_FORMAT,
				"server_jar_sha1" => $serverSha1,
				"java_major" => $javaMajor,
				"files" => $files,
				"bytes" => $bytes,
				"created" => time(),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
			$logger("Vanilla worldgen data ready: $files files (" . self::megabytes($bytes) . ") in " . round(microtime(true) - $start) . " s; removing temporary files");
		}finally{
			//the runtime and the generator output are thousands of small files: this can take a while on Windows,
			//but the data is already usable (isReady() is true once the stamp is written)
			Filesystem::recursiveUnlink($tmp);
		}
	}

	/**
	 * URL, SHA-1, size and Java major version of the {@link MINECRAFT_VERSION} server.jar, from Mojang's version
	 * manifest (the version metadata is SHA-1-checked against the manifest).
	 * @internal
	 * @phpstan-return array{string, string, int, int}
	 */
	public static function resolveServerJar() : array{
		$manifest = self::getJson(self::VERSION_MANIFEST_URL);
		$entry = null;
		foreach(WorldgenData::array($manifest, "versions") as $version){
			if(is_array($version) && ($version["id"] ?? null) === self::MINECRAFT_VERSION){
				$entry = $version;
				break;
			}
		}
		if($entry === null){
			throw new \RuntimeException("Minecraft " . self::MINECRAFT_VERSION . " is not in Mojang's version manifest");
		}
		$body = self::get(WorldgenData::string($entry, "url"));
		if(hash("sha1", $body) !== WorldgenData::string($entry, "sha1")){
			throw new \RuntimeException("Version metadata checksum mismatch");
		}
		$version = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
		if(!is_array($version)){
			throw new \RuntimeException("Invalid version metadata");
		}
		$server = WorldgenData::array(WorldgenData::array($version, "downloads"), "server");
		$javaVersion = $version["javaVersion"] ?? [];
		$major = is_array($javaVersion) ? WorldgenData::int($javaVersion, "majorVersion", 21) : 21;
		return [WorldgenData::string($server, "url"), WorldgenData::string($server, "sha1"), WorldgenData::int($server, "size"), $major];
	}

	/**
	 * @phpstan-param \Closure(string) : void $logger
	 * @phpstan-param \Closure() : void $heartbeat
	 */
	private static function prepareJava(string $tmp, int $major, \Closure $logger, \Closure $heartbeat) : string{
		$java = getenv(self::JAVA_ENV);
		if(is_string($java) && $java !== ""){
			$logger("Using Java from " . self::JAVA_ENV . ": $java");
			return $java;
		}
		$platform = self::detectPlatform() ?? throw new \RuntimeException(self::checkPlatform() ?? "Unsupported platform");
		$assets = self::getJson(sprintf(self::ADOPTIUM_URL, $major, $platform["arch"], $platform["os"]));
		$package = null;
		foreach($assets as $asset){
			if(is_array($asset) && is_array($asset["binary"] ?? null) && is_array($asset["binary"]["package"] ?? null)){
				$package = $asset["binary"]["package"];
				break;
			}
		}
		if($package === null){
			throw new \RuntimeException("No Temurin $major JRE published for {$platform["os"]}/{$platform["arch"]}");
		}
		$name = WorldgenData::string($package, "name");
		$archive = $tmp . "/" . basename($name);
		$logger("Downloading Java runtime $name (" . self::megabytes(WorldgenData::int($package, "size", 0)) . ", Eclipse Temurin, deleted afterwards)");
		self::download(WorldgenData::string($package, "link"), $archive, $heartbeat);
		self::verify($archive, "sha256", WorldgenData::string($package, "checksum"));

		$dir = $tmp . "/jre";
		@mkdir($dir);
		if(str_ends_with($name, ".zip")){
			(new \PharData($archive))->extractTo($dir, null, true);
		}else{
			self::runProcess(["tar", "-xzf", $archive, "-C", $dir], $tmp, $tmp . "/tar.log", 300, $heartbeat);
		}
		@unlink($archive);
		$binary = PHP_OS_FAMILY === "Windows" ? "java.exe" : "java";
		foreach(["/*/bin/", "/*/Contents/Home/bin/", "/bin/"] as $pattern){
			$candidates = glob($dir . $pattern . $binary);
			if($candidates !== false && count($candidates) > 0){
				return $candidates[0];
			}
		}
		throw new \RuntimeException("java executable not found in the downloaded runtime");
	}

	/**
	 * @phpstan-param \Closure() : void $heartbeat
	 */
	private static function runDataGenerator(string $java, string $tmp, \Closure $heartbeat) : void{
		self::runProcess(
			[$java, "-DbundlerMainClass=net.minecraft.data.Main", "-jar", "server.jar", "--server", "--reports", "--output", "generated"],
			$tmp,
			$tmp . "/datagen.log",
			self::DATAGEN_TIMEOUT,
			$heartbeat
		);
		foreach([...self::EXTRACTED_PATHS, "reports/blocks.json", self::ITEM_COMPONENTS_REPORT] as $path){
			if(!file_exists($tmp . "/generated/" . $path)){
				throw new \RuntimeException("The data generator did not produce $path");
			}
		}
	}

	/**
	 * @param string[] $command
	 * @phpstan-param list<string> $command
	 * @phpstan-param \Closure() : void $heartbeat
	 */
	private static function runProcess(array $command, string $cwd, string $log, int $timeout, \Closure $heartbeat) : void{
		$process = proc_open($command, [0 => ["file", PHP_OS_FAMILY === "Windows" ? "NUL" : "/dev/null", "r"], 1 => ["file", $log, "w"], 2 => ["file", $log, "a"]], $pipes, $cwd);
		if($process === false){
			throw new \RuntimeException("Failed to start " . $command[0]);
		}
		$deadline = time() + $timeout;
		$lastBeat = 0;
		while(true){
			$status = proc_get_status($process);
			if(!$status["running"]){
				break;
			}
			if(time() > $deadline){
				proc_terminate($process);
				proc_close($process);
				throw new \RuntimeException(basename($command[0]) . " timed out after $timeout s");
			}
			if(time() !== $lastBeat){
				$lastBeat = time();
				$heartbeat();
			}
			usleep(200_000);
		}
		proc_close($process);
		if($status["exitcode"] !== 0){
			$output = is_file($log) ? Filesystem::fileGetContents($log) : "";
			$lines = explode("\n", str_replace("\r", "", $output), limit: PHP_INT_MAX);
			throw new \RuntimeException(basename($command[0]) . " exited with code {$status["exitcode"]}: " . implode("\n", array_slice($lines, -10)));
		}
	}

	/**
	 * Replaces the cached data with the wanted subset of the generator output.
	 * @phpstan-return array{int, int}
	 */
	private static function install(string $generated, string $root) : array{
		@unlink($root . "/" . self::STAMP_FILE);
		@unlink($root . "/" . self::BIOME_TREE_FILE);
		@unlink($root . "/" . self::JAVA_BLOCKS_FILE);
		@unlink($root . "/" . self::JAVA_ITEMS_FILE);
		foreach(["data", "reports", self::GEYSER_DIR] as $top){
			if(is_dir($root . "/" . $top)){
				Filesystem::recursiveUnlink($root . "/" . $top);
			}
		}
		$files = 0;
		$bytes = 0;
		foreach(self::EXTRACTED_PATHS as $path){
			$source = $generated . "/" . $path;
			$target = $root . "/" . $path;
			if(!is_dir(dirname($target))){
				@mkdir(dirname($target), 0777, true);
			}
			if(is_dir($source)){
				Filesystem::recursiveCopy($source, $target);
				$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS));
				foreach($iterator as $file){
					if($file instanceof \SplFileInfo && $file->isFile()){
						$files++;
						$bytes += $file->getSize();
					}
				}
			}else{
				if(!@copy($source, $target)){
					throw new \RuntimeException("Failed to copy $path");
				}
				$files++;
				$bytes += (int) filesize($target);
			}
		}
		return [$files, $bytes];
	}

	/**
	 * Converts the blocks report (7 MB) to the compact list the feature engine needs:
	 * {"blocks": [[name, firstStateId, defaultStateId, [[property, [values...]], ...]], ...]}
	 * State ids of a block are consecutive, in the order of the cartesian product of the property values with the last
	 * property varying fastest (checked here). Blocks that do not follow it get an explicit "states" list instead:
	 * [name, firstStateId, defaultStateId, properties, [[stateId, [valueIndex...]], ...]].
	 *
	 * @return mixed[]
	 */
	public static function compactBlocksReport(string $path) : array{
		$report = json_decode(Filesystem::fileGetContents($path), true, 16, JSON_THROW_ON_ERROR);
		if(!is_array($report)){
			throw new \RuntimeException("Invalid blocks report");
		}
		$blocks = [];
		foreach(Utils::promoteKeys($report) as $name => $block){
			if(!is_array($block)){
				throw new \RuntimeException("Invalid blocks report entry $name");
			}
			$properties = [];
			foreach(Utils::promoteKeys(is_array($block["properties"] ?? null) ? $block["properties"] : []) as $property => $values){
				if(!is_array($values)){
					throw new \RuntimeException("Invalid property $property of $name");
				}
				$properties[] = [(string) $property, array_values(array_map(fn(mixed $v) : string => is_string($v) ? $v : throw new \RuntimeException("Invalid value"), $values))];
			}
			$states = WorldgenData::array($block, "states");
			$first = PHP_INT_MAX;
			$default = -1;
			$explicit = [];
			foreach($states as $state){
				if(!is_array($state)){
					throw new \RuntimeException("Invalid state of $name");
				}
				$id = WorldgenData::int($state, "id");
				$first = min($first, $id);
				if(($state["default"] ?? false) === true){
					$default = $id;
				}
				$indexes = [];
				$stateProperties = is_array($state["properties"] ?? null) ? $state["properties"] : [];
				foreach($properties as [$property, $values]){
					$index = array_search($stateProperties[$property] ?? null, $values, true);
					if($index === false){
						throw new \RuntimeException("Unknown value of $property in a state of $name");
					}
					$indexes[] = $index;
				}
				$explicit[] = [$id, $indexes];
			}
			$sequential = true;
			foreach($explicit as [$id, $indexes]){
				$offset = 0;
				foreach($properties as $i => [, $values]){
					$offset = $offset * count($values) + $indexes[$i];
				}
				if($id !== $first + $offset){
					$sequential = false;
					break;
				}
			}
			$entry = [(string) $name, $first, $default === -1 ? $first : $default, $properties];
			if(!$sequential){
				$entry[] = $explicit;
			}
			$blocks[] = $entry;
		}
		return ["blocks" => $blocks];
	}

	/**
	 * Converts the item component reports (one file per item, ~6 MB) to the compact list the loot tables need:
	 * {"items": {id: [maxStackSize, maxDamage, enchantability]}} (0 when the item has no such component).
	 *
	 * @return mixed[]
	 */
	public static function compactItemReports(string $directory) : array{
		$files = glob($directory . "/*.json");
		if($files === false || count($files) === 0){
			throw new \RuntimeException("No item component reports in $directory");
		}
		$items = [];
		foreach($files as $file){
			$report = json_decode(Filesystem::fileGetContents($file), true, 64, JSON_THROW_ON_ERROR);
			$components = is_array($report) && is_array($report["components"] ?? null) ? $report["components"] : throw new \RuntimeException("Invalid item component report $file");
			$enchantable = $components["minecraft:enchantable"] ?? null;
			$items["minecraft:" . basename($file, ".json")] = [
				is_int($components["minecraft:max_stack_size"] ?? null) ? $components["minecraft:max_stack_size"] : 64,
				is_int($components["minecraft:max_damage"] ?? null) ? $components["minecraft:max_damage"] : 0,
				is_array($enchantable) && is_int($enchantable["value"] ?? null) ? $enchantable["value"] : 0
			];
		}
		return ["items" => $items];
	}

	/**
	 * Copies the structure templates from the server jar: every template (structures, format 5), checking that the
	 * ones referenced by the features (template and fossil features) are there.
	 * @phpstan-return array{int, int}
	 */
	private static function extractTemplates(string $tmp, string $root) : array{
		$ids = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . "/data/minecraft/worldgen/feature", \FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file){
			if($file instanceof \SplFileInfo && $file->isFile()){
				$json = json_decode(Filesystem::fileGetContents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
				self::collectTemplateIds($json, $ids);
			}
		}
		$jars = glob($tmp . "/versions/*/server-*.jar");
		if($jars === false || count($jars) === 0){
			throw new \RuntimeException("The unpacked server jar was not found (needed for the structure templates)");
		}
		$jar = new \PharData($jars[0]);
		$prefix = "/" . self::TEMPLATE_DIR . "/";
		if(isset($jar[self::TEMPLATE_DIR])){
			$all = new \RecursiveIteratorIterator(new \PharData($jars[0] . "/" . self::TEMPLATE_DIR), \RecursiveIteratorIterator::LEAVES_ONLY);
			foreach($all as $entry){
				if($entry instanceof \SplFileInfo && $entry->isFile() && str_ends_with($entry->getFilename(), ".nbt")){
					$path = str_replace("\\", "/", $entry->getPathname());
					$pos = strrpos($path, $prefix);
					if($pos !== false){
						$ids["minecraft:" . substr($path, $pos + strlen($prefix), -4)] = true;
					}
				}
			}
		}
		if(count($ids) === 0){
			return [0, 0];
		}
		$files = 0;
		$bytes = 0;
		foreach(Utils::stringifyKeys($ids) as $id => $_){
			$relative = self::TEMPLATE_DIR . "/" . WorldgenData::stripNamespace($id) . ".nbt";
			if(!isset($jar[$relative])){
				throw new \RuntimeException("Structure template $id not found in the server jar");
			}
			$contents = $jar[$relative]->getContent();
			$target = $root . "/" . $relative;
			if(!is_dir(dirname($target))){
				@mkdir(dirname($target), 0777, true);
			}
			Filesystem::safeFilePutContents($target, $contents);
			$files++;
			$bytes += strlen($contents);
		}
		return [$files, $bytes];
	}

	/**
	 * @param true[] $ids
	 * @phpstan-param array<string, true> $ids
	 */
	private static function collectTemplateIds(mixed $json, array &$ids) : void{
		if(!is_array($json)){
			return;
		}
		$type = $json["type"] ?? null;
		if($type === "minecraft:template" && is_array($json["templates"] ?? null)){
			foreach($json["templates"] as $entry){
				$id = is_array($entry) && is_array($entry["data"] ?? null) ? ($entry["data"]["id"] ?? null) : null;
				if(is_string($id)){
					$ids[$id] = true;
				}
			}
		}elseif($type === "minecraft:fossil"){
			foreach(["fossil_structures", "overlay_structures"] as $key){
				foreach(is_array($json[$key] ?? null) ? $json[$key] : [] as $id){
					if(is_string($id)){
						$ids[$id] = true;
					}
				}
			}
		}
		foreach($json as $value){
			self::collectTemplateIds($value, $ids);
		}
	}

	private static function get(string $url) : string{
		return self::request($url, null, function() : void{});
	}

	/**
	 * @return mixed[]
	 */
	private static function getJson(string $url) : array{
		$json = json_decode(self::get($url), true, 512, JSON_THROW_ON_ERROR);
		if(!is_array($json)){
			throw new \RuntimeException("Unexpected response from $url");
		}
		return $json;
	}

	/**
	 * Streams a (large) file to disk. $heartbeat may throw to cancel the download.
	 * @internal
	 * @phpstan-param \Closure() : void $heartbeat
	 */
	public static function download(string $url, string $target, \Closure $heartbeat) : void{
		$file = fopen($target, "wb");
		if($file === false){
			throw new \RuntimeException("Cannot write $target");
		}
		try{
			self::request($url, $file, $heartbeat);
		}catch(\RuntimeException $e){
			fclose($file);
			@unlink($target);
			throw $e;
		}
		fclose($file);
	}

	/**
	 * HTTPS request with certificate verification (the checksums that protect the downloads come from these
	 * responses). On Windows the system certificate store is used, since PHP builds often ship without a CA bundle.
	 *
	 * @param resource|null $file null to return the body
	 * @phpstan-param \Closure() : void $heartbeat
	 */
	private static function request(string $url, $file, \Closure $heartbeat) : string{
		$ch = curl_init($url);
		$lastBeat = 0;
		$cancelled = null;
		$options = [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 1800,
			CURLOPT_HTTPHEADER => ["User-Agent: PocketMine-MP"],
			CURLOPT_NOPROGRESS => false,
			CURLOPT_PROGRESSFUNCTION => function() use (&$lastBeat, &$cancelled, $heartbeat) : int{
				if(time() !== $lastBeat){
					$lastBeat = time();
					try{
						$heartbeat();
					}catch(\Throwable $e){
						//an exception thrown by a curl callback does not stop the transfer: abort it, rethrow afterwards
						$cancelled = $e;
						return 1;
					}
				}
				return 0;
			},
		];
		if($file !== null){
			$options[CURLOPT_FILE] = $file;
		}else{
			$options[CURLOPT_RETURNTRANSFER] = true;
		}
		if(PHP_OS_FAMILY === "Windows" && defined("CURLSSLOPT_NATIVE_CA")){
			$options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
		}
		curl_setopt_array($ch, $options);
		$ok = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		if($cancelled !== null){
			throw $cancelled;
		}
		if($ok === false || $code !== 200){
			throw new \RuntimeException("Failed to download $url: " . ($error !== "" ? $error . " (if this is a certificate error, configure curl.cainfo in php.ini)" : "HTTP $code"));
		}
		return is_string($ok) ? $ok : "";
	}

	/**
	 * @internal
	 */
	public static function verify(string $file, string $algo, string $expected) : void{
		$actual = hash_file($algo, $file);
		if($actual === false || strtolower($actual) !== strtolower($expected)){
			throw new \RuntimeException("Checksum mismatch for " . basename($file) . " ($algo: expected $expected, got " . ($actual === false ? "nothing" : $actual) . ")");
		}
	}

	/**
	 * @internal
	 */
	public static function megabytes(int $bytes) : string{
		return number_format($bytes / 1048576, 1) . " MB";
	}
}
