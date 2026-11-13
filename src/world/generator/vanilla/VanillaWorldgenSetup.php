<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use Closure;
use pocketmine\utils\Config;
use pocketmine\world\dimension\DimensionSettings;
use pocketmine\world\generator\InvalidGeneratorOptionsException;

/**
 * One-shot, opt-in Pterodactyl bootstrap. Never changes a pre-existing
 * level.dat / LevelDB / player data, and never distributes Mojang data.
 *
 * The native Go process is optional, and stays outside the PHAR.
 */
final class VanillaWorldgenSetup{
    public const CACHE_NAME = "vanillamine-cache";
    public const GENERATOR = "ryze_vanilla_overworld";

    private function __construct(){}

    /** @param Closure(string): void $logger */
    public static function prepare(string $serverRoot, Closure $logger) : void{
        $root = realpath($serverRoot);
        if($root === false || !is_dir($root)){
            throw new \RuntimeException("Server directory does not exist");
        }
        $cache = $root . "/" . self::CACHE_NAME;
        if(is_link($cache)){
            throw new \RuntimeException("Refusing a symbolic link in the VanillaMine cache");
        }
        if(!VanillaDataFetcher::isReady($cache)){
            if(file_exists($cache) && (!is_dir($cache) ||
                count(scandir($cache) ?: []) > 2)){
                throw new \RuntimeException("Incomplete VanillaMine cache at $cache. Back it up and remove the incomplete cache using Pterodactyl Files before retrying.");
            }
            if(($problem = VanillaDataFetcher::checkPlatform()) !== null){
                throw new \RuntimeException("Cannot download verified Minecraft Java 26.3 data: $problem");
            }
            $logger("Preparing official Java 26.3 worldgen data on this server. The first run downloads Mojang and verified Java dependencies.");
            VanillaDataFetcher::fetch($cache, $logger);
        }else{
            $logger("Using existing Java 26.3 cache without re-downloading.");
        }
        if(!VanillaDataFetcher::isReady($cache)){
            throw new \RuntimeException("Java 26.3 cache failed readiness checks.");
        }
        $hash = VanillaDataIdentity::fingerprint($cache);
        $logger("Verified VanillaMine data SHA-256: $hash");
        self::configureWorlds($root, $cache, $hash, $logger);
    }

    /**
     * Pure configuration stage; the hash has already been verified by prepare().
     * Public for regression tests; never changes the actual world folders.
     *
     * @param Closure(string): void $logger
     */
    public static function configureWorlds(string $root, string $cache, string $hash, Closure $logger) : void{
        if(!preg_match('/^[a-f0-9]{64}$/D', $hash)){
            throw new \InvalidArgumentException("Worldgen SHA-256 is not valid");
        }
        $root = realpath($root) ?: throw new \RuntimeException("Server directory not found");
        $propertiesPath = $root . "/server.properties";
        if(!is_file($propertiesPath) || is_link($propertiesPath)){
            throw new \RuntimeException("server.properties must already exist as an ordinary file");
        }
        $properties = new Config($propertiesPath, Config::PROPERTIES);
        $originalName = trim((string) $properties->get("level-name", "world"));
        if(!self::validName($originalName)){
            throw new \RuntimeException("Invalid level-name; world directories will not be changed");
        }
        $preset = "core-v6;data=" . $cache . ";sha256=" . $hash;
        $worldPath = $root . "/worlds/" . $originalName;
        $worldExists = file_exists($worldPath) || is_link($worldPath);
        $currentType = strtolower((string) $properties->get("level-type", "default"));
        $currentPreset = (string) $properties->get("generator-settings", "");

        if($worldExists && $currentType === self::GENERATOR){
            if($currentPreset !== $preset){
                throw new \RuntimeException("Existing Vanilla world $originalName uses a different pinned cache. Refusing to change the world generator.");
            }
            $logger("Preserving existing Vanilla world $originalName with its original cache identity.");
            // Re-validate without overwriting the original pinned world.
            VanillaDensityChunkWriter::validatePreset($currentPreset);
            return;
        }

        $newName = $originalName;
        if($worldExists){
            $newName = self::nextUnusedWorld($root, "world_vanilla_test");
            $logger("Existing world $originalName remains untouched. The Vanilla test world will be $newName.");
        }
        $nextDimensions = [];
        $dimensionsPath = $root . "/dimensions.yml";
        if(is_link($dimensionsPath)){
            throw new \RuntimeException("Refusing symbolic link for dimensions.yml");
        }
        $dimensions = is_file($dimensionsPath) ? new Config($dimensionsPath, Config::YAML) : null;
        $dimensionValues = $dimensions?->getAll() ?? [];
        foreach(["nether", "end"] as $dimension){
            $section = $dimensionValues[$dimension] ?? [];
            if(!is_array($section)){
                throw new \RuntimeException("Invalid dimensions.yml $dimension section");
            }
            $previous = $section["world"] ?? ($dimension === "nether" ? "world_nether" : "world_the_end");
            if(!is_string($previous) || !self::validName($previous)){
                throw new \RuntimeException("Invalid dimension world name for $dimension");
            }
            if(file_exists($root . "/worlds/" . $previous) || is_link($root . "/worlds/" . $previous) ||
                $previous === $newName){
                $previous = self::nextUnusedWorld($root, $newName . "_" . $dimension);
                $logger("Existing $dimension dimension files are preserved. A fresh world will use $previous.");
            }
            $section["world"] = $previous;
            $section["source-dimension"] = $dimension;
            $section["worldgen-preset"] = $preset;
            $nextDimensions[$dimension] = $section;
        }

        // Validate the complete dimensions config before writing either file.
        DimensionSettings::fromArray(array_replace($dimensionValues, $nextDimensions));
        VanillaDensityChunkWriter::validatePreset($preset);
        foreach($nextDimensions as $dimension => $section){
            VanillaDensityChunkWriter::validatePreset($preset, $dimension);
        }

        if($dimensions === null){
            $dimensions = new Config($dimensionsPath, Config::YAML, []);
        }
        foreach($nextDimensions as $dimension => $section){
            $dimensions->set($dimension, $section);
        }
        $dimensions->save();
        $properties->set("level-name", $newName);
        $properties->set("level-type", self::GENERATOR);
        $properties->set("generator-settings", $preset);
        if(trim((string) $properties->get("level-seed", "")) === ""){
            $properties->set("level-seed", "12345");
        }
        $properties->save();
        $logger("Configured new core-v6 Overworld $newName and Vanilla Nether/End. No existing world folder was overwritten.");
    }

    private static function nextUnusedWorld(string $root, string $base) : string{
        for($suffix = 0; $suffix < 1000; ++$suffix){
            $name = $suffix === 0 ? $base : $base . "_" . ($suffix + 1);
            if(!file_exists($root . "/worlds/" . $name) && !is_link($root . "/worlds/" . $name)){
                return $name;
            }
        }
        throw new \RuntimeException("Could not find a free name for world $base");
    }

    private static function validName(string $name) : bool{
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name) === 1;
    }
}
