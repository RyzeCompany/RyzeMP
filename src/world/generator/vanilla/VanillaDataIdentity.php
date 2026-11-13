<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use pocketmine\world\generator\InvalidGeneratorOptionsException;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function hash_file;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_dir;
use function is_file;
use function is_link;
use function preg_match;
use function realpath;
use function sort;
use function str_contains;
use function strlen;
use function substr;
use const SORT_STRING;

/**
 * Byte-for-byte identity of the external VanillaMine data cache.
 *
 * This is not a hash of Mojang's executable, and does not download assets.
 * It includes only source inputs used by worldgen/features/structures (not
 * transient compiled kernels, lock files or arbitrary sibling directories).
 * A cache MUST remain unchanged for the lifetime of a world.
 */
final class VanillaDataIdentity{
	public const ALGORITHM = "ryze-vanillamine-core-v2";
	public const PRESET_PREFIX = "core-v2;data=";
	public const FEATURES_PRESET_PREFIX = "core-v3;data=";
	public const COMPILED_PRESET_PREFIX = "core-v4;data=";
	public const STRUCTURES_PRESET_PREFIX = "core-v5;data=";
	public const CONTENT_PRESET_PREFIX = "core-v6;data=";

	private const ASSETS = [
		"data",
		"reports",
		"version.json",
		"multi_noise_biome_tree.json",
		"java_blocks.json",
		"java_items.json",
		"geyser"
	];

	private function __construct(){}

	/**
	 * Canonical, sorted, content-derived SHA-256, independent of the cache's
	 * absolute location, creation order, timestamps and directory metadata.
	 *
	 * @throws InvalidGeneratorOptionsException if an input is unreadable, is a
	 * symlink, or the minimum required worldgen dataset is missing.
	 */
	public static function fingerprint(string $directory) : string{
		$root = realpath($directory);
		if($root === false || !is_dir($root . "/data/minecraft/worldgen") || !is_file($root . "/version.json")){
			throw new InvalidGeneratorOptionsException("Missing VanillaMine worldgen cache root");
		}
		$files = [];
		foreach(self::ASSETS as $path){
			$source = $root . "/" . $path;
			if(is_link($source)){
				throw new InvalidGeneratorOptionsException("Symlinks are not permitted in pinned worldgen cache: $path");
			}
			if(is_file($source)){
				$files[$path] = $source;
			}elseif(is_dir($source)){
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
					\RecursiveIteratorIterator::SELF_FIRST
				);
				foreach($iterator as $item){
					/** @var \SplFileInfo $item */
					$relative = substr($item->getPathname(), strlen($root) + 1);
					if($item->isLink()){
						throw new InvalidGeneratorOptionsException("Symlinks are not permitted in pinned worldgen cache: $relative");
					}
					if($item->isFile()){
						$files[$relative] = $item->getPathname();
					}
				}
			}
		}
		if(!isset($files["version.json"]) || !isset($files["data/minecraft/worldgen/noise_settings/overworld.json"])){
			throw new InvalidGeneratorOptionsException("VanillaMine cache lacks required noise settings");
		}
		$paths = array_keys($files);
		sort($paths, SORT_STRING);
		$hash = hash_init("sha256");
		hash_update($hash, self::ALGORITHM . "\n");
		foreach($paths as $relative){
			$sha = hash_file("sha256", $files[$relative]);
			if($sha === false){
				throw new InvalidGeneratorOptionsException("Unable to hash VanillaMine cache asset: $relative");
			}
			hash_update($hash, strlen($relative) . ":" . $relative . ":" . $sha . "\n");
		}
		return hash_final($hash);
	}

	/**
	 * Parses an immutable generator preset. Store this entire preset in
	 * level.dat (WorldCreationOptions already does this) rather than a
	 * mutable manifest alongside the cache.
	 *
	 * @phpstan-return array{directory: string, hash: string}
	 */
	public static function parse(string $preset) : array{
		$prefix = self::pinnedPrefix($preset);
		if($prefix === null){
			throw new InvalidGeneratorOptionsException("Expected core-v2 through core-v6 with an absolute data path and SHA-256");
		}
		$rest = substr($preset, strlen($prefix));
		$separator = strrpos($rest, ";sha256=");
		if($separator === false){
			throw new InvalidGeneratorOptionsException("Pinned VanillaMine preset requires sha256");
		}
		$directory = substr($rest, 0, $separator);
		$hash = substr($rest, $separator + 8);
		if($directory === "" || str_contains($directory, ";") || str_contains($directory, "\0") ||
			!Path::isAbsolute($directory) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1){
			throw new InvalidGeneratorOptionsException("Invalid core-v2 data directory or SHA-256 fingerprint");
		}
		return ["directory" => $directory, "hash" => $hash];
	}

	public static function assertMatches(string $directory, string $expectedHash) : void{
		if(!hash_equals($expectedHash, self::fingerprint($directory))){
			throw new InvalidGeneratorOptionsException(
				"VanillaMine data cache changed for pinned core-v2 world; refusing to generate new chunks"
			);
		}
	}

	public static function hasPlacedFeatures(string $preset) : bool{
		return str_starts_with($preset, self::FEATURES_PRESET_PREFIX) || self::hasCompiledTerrain($preset);
	}

	public static function hasCompiledTerrain(string $preset) : bool{
		return str_starts_with($preset, self::COMPILED_PRESET_PREFIX) || self::hasStructures($preset);
	}

	public static function hasStructures(string $preset) : bool{
		return str_starts_with($preset, self::STRUCTURES_PRESET_PREFIX) || self::hasStructureContent($preset);
	}

	public static function hasStructureContent(string $preset) : bool{
		return str_starts_with($preset, self::CONTENT_PRESET_PREFIX);
	}

	/** Historical core-v1 has no pinned hash. */
	public static function expectedHash(string $preset) : ?string{
		return self::pinnedPrefix($preset) !== null ? self::parse($preset)["hash"] : null;
	}

	private static function pinnedPrefix(string $preset) : ?string{
		foreach([self::PRESET_PREFIX, self::FEATURES_PRESET_PREFIX, self::COMPILED_PRESET_PREFIX, self::STRUCTURES_PRESET_PREFIX, self::CONTENT_PRESET_PREFIX] as $prefix){
			if(str_starts_with($preset, $prefix)){
				return $prefix;
			}
		}
		return null;
	}
}
