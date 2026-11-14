<?php
declare(strict_types=1);

namespace pocketmine\world\mobs\spawn;

use pocketmine\ServerConfigGroup;

/**
 * VanillaMine-shaped mob configuration with explicit capabilities for the
 * current RyzeMP mob subset. All fields that are used have server-side effects.
 * Unsupported mechanics issue warnings instead of silently claiming parity.
 */
final class MobSpawningSettings{
	public const MODE_VANILLA = "vanilla";
	public const MODE_LEGACY = "legacy";

	/**
	 * @param list<string> $disabledWorlds
	 * @param array<string, int> $spawnLimits
	 * @param list<string> $warnings
	 */
	private function __construct(
		public readonly bool $aiEnabled,
		public readonly bool $enabled,
		public readonly array $disabledWorlds,
		public readonly string $mode,
		public readonly int $chunkChance,
		public readonly int $maxAttempts,
		public readonly int $globalCap,
		public readonly bool $countAll,
		public readonly array $spawnLimits,
		public readonly int $maxDistance,
		public readonly int $despawnDistance,
		public readonly int $hostileInterval,
		public readonly int $passiveInterval,
		public readonly int $hostileCap,
		public readonly int $passiveCap,
		public readonly array $warnings
	){}

	public static function fromConfig(ServerConfigGroup $cfg, string $worldFolder, int $tickRadius) : self{
		$disabled = $cfg->getProperty("mobs.disabled-worlds", []);
		if(!is_array($disabled)){
			throw new \InvalidArgumentException("mobs.disabled-worlds must be a list of world folder names");
		}
		$names = [];
		foreach($disabled as $worldName){
			if(!is_string($worldName) || $worldName === ""){
				throw new \InvalidArgumentException("mobs.disabled-worlds must contain non-empty folder names");
			}
			$names[] = $worldName;
		}
		$upgradingLegacyConfig = $cfg->getProperty("mobs.spawning.mode") === null &&
			$cfg->getProperty("mobs.spawning.interval-ticks") !== null;
		$mode = strtolower($cfg->getPropertyString("mobs.spawning.mode",
			$upgradingLegacyConfig ? self::MODE_LEGACY : self::MODE_VANILLA));
		if($mode !== self::MODE_VANILLA && $mode !== self::MODE_LEGACY){
			throw new \InvalidArgumentException("mobs.spawning.mode must be vanilla or legacy, got $mode");
		}
		$aiEnabled = $cfg->getPropertyBool("mobs.enabled", false);
		$worldEnabled = $aiEnabled && !in_array($worldFolder, $names, true);
		$warnings = [];
		if($cfg->getPropertyBool("mobs.spawning.vanilla-rules", false)){
			$warnings[] = "vanilla-rules=true is not yet available: only RyzeMP's limited built-in biome rules are applied";
		}
		foreach([
			"mobs.spawning.phantoms" => "phantom spawning",
			"mobs.spawning.wandering-traders" => "wandering traders",
			"mobs.spawning.village-cats" => "village cats",
			"mobs.spawning.patrols.enabled" => "pillager patrols",
			"mobs.raids.enabled" => "raids"
		] as $key => $description){
			if($cfg->getPropertyBool($key, false)){
				$warnings[] = "$description cannot spawn until the corresponding mob system is implemented";
			}
		}
		if(!$cfg->getPropertyBool("mobs.ignore-plugin-npcs", true)){
			$warnings[] = "ignore-plugin-npcs=false is not yet available: current hostile AI only targets survival players";
		}
		if($cfg->getPropertyInt("mobs.movement-broadcast.full-rate-distance", 24) !== 24 ||
			$cfg->getPropertyInt("mobs.movement-broadcast.half-rate-distance", 48) !== 48){
			$warnings[] = "mobs.movement-broadcast optimisations are not implemented: normal movement updates are unchanged";
		}
		$limits = [];
		foreach(["animal", "monster", "ambient", "water_animal", "pillager"] as $category){
			$limits[$category] = max(-1, $cfg->getPropertyInt("mobs.spawning.spawn-limits.$category", -1));
		}
		$autoSpawnDistance = $tickRadius <= 4 ? 44 : min(128, $tickRadius * 16);
		// Bedrock keeps naturally spawned mobs up to 128 blocks away once the
		// simulation radius exceeds four chunks, even if the spawn range is smaller.
		$autoDespawnDistance = $tickRadius <= 4 ? 44 : 128;
		$requestedDistance = $cfg->getPropertyInt("mobs.spawning.max-distance", 0);
		$maxDistance = $requestedDistance <= 0 ? $autoSpawnDistance : max(25, $requestedDistance);
		$requestedDespawn = $cfg->getPropertyInt("mobs.spawning.instant-despawn-distance", 0);
		$despawnDistance = $requestedDespawn <= 0 ? $autoDespawnDistance : $requestedDespawn;

		// Old PR #61 configs used these fields. Honor them during upgrade,
		// without adding them to the new VanillaMine-shaped template.
		$oldCap = $cfg->getPropertyInt("mobs.spawning.max-total", 200);
		$oldAttempts = $cfg->getPropertyInt("mobs.spawning.max-attempts", 32);
		$oldInterval = $cfg->getPropertyInt("mobs.spawning.interval-ticks", 20);
		$oldMonsterCap = $cfg->getPropertyInt("mobs.spawning.max-monsters", 20);
		$oldAnimalCap = $cfg->getPropertyInt("mobs.spawning.max-animals", 10);
		if($cfg->getProperty("mobs.spawning.spawn-limits.animal") === null &&
			$cfg->getProperty("mobs.spawning.max-animals") !== null){
			$limits["animal"] = $oldAnimalCap;
		}
		if($cfg->getProperty("mobs.spawning.spawn-limits.monster") === null &&
			$cfg->getProperty("mobs.spawning.max-monsters") !== null){
			$limits["monster"] = $oldMonsterCap;
		}
		if($cfg->getProperty("mobs.spawning.spawn-limits.water_animal") === null &&
			$cfg->getProperty("mobs.spawning.max-aquatic") !== null){
			$limits["water_animal"] = $cfg->getPropertyInt("mobs.spawning.max-aquatic", 12);
		}
		return new self(
			$aiEnabled,
			$worldEnabled && $cfg->getPropertyBool("mobs.spawning.enabled", true),
			$names,
			$mode,
			max(0, min(2000, $cfg->getPropertyInt("mobs.spawning.chunk-chance", 11))),
			max(1, min(128, $cfg->getPropertyInt("mobs.spawning.max-attempts-per-tick", $oldAttempts))),
			max(0, $cfg->getPropertyInt("mobs.spawning.global-cap", $oldCap)),
			$cfg->getPropertyBool("mobs.spawning.count-all-mobs-for-spawning", true),
			$limits,
			$maxDistance,
			$despawnDistance,
			max(1, $cfg->getPropertyInt("mobs.spawning.hostile-interval", $oldInterval)),
			max(1, $cfg->getPropertyInt("mobs.spawning.passive-interval", $upgradingLegacyConfig ? $oldInterval : 400)),
			max(0, $cfg->getPropertyInt("mobs.spawning.hostile-cap", $oldMonsterCap)),
			max(0, $cfg->getPropertyInt("mobs.spawning.passive-cap", $oldAnimalCap)),
			$warnings
		);
	}

	public function isWorldDisabled(string $folderName) : bool{
		return in_array($folderName, $this->disabledWorlds, true);
	}

	/** Returns the per-9x9-chunk cap for a category in the current mode. */
	public function populationCap(string $category, bool $surface) : int{
		if($this->mode === self::MODE_LEGACY){
			return match($category){
				"animal" => $this->passiveCap,
				"monster" => $this->hostileCap,
				default => 0
			};
		}
		$override = $this->spawnLimits[$category] ?? -1;
		if($override >= 0){return $override;}
		return match($category){
			"animal" => $surface ? 4 : 0,
			"monster" => $surface ? 8 : 16,
			"water_animal" => $surface ? 36 : 0,
			"ambient" => $surface ? 0 : 2,
			"pillager" => 8,
			default => 0
		};
	}
}
