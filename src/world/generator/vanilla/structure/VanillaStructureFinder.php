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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\math\Vector3;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\structure\AsyncStructureLocator;
use pocketmine\world\locate\LocateWorkerPool;
use pocketmine\world\World;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function ceil;
use function count;
use function hrtime;
use function intdiv;
use function is_array;
use function max;
use function min;
use function sqrt;

/**
 * The /locate structure locator of vanilla worlds (registered for the generators "vanilla", "vanilla_nether" and
 * "vanilla_end"): a thin adapter over {@link VanillaStructureLocator}. It accepts the Bedrock /locate names
 * ("village", "temple", "ruins", "monument"...), their older spellings without underscores ("endcity") and the Java
 * structure ids ("village_plains", "jungle_pyramid", "ocean_ruin_cold"...), plus a few common English names
 * ("woodland_mansion", "ocean_monument", "nether_fortress", "bastion").
 *
 * Searches can take seconds (each candidate start needs the terrain height of its area), so /locate runs them on the
 * /locate worker through {@link findNearestAsync()}.
 */
final class VanillaStructureFinder implements AsyncStructureLocator{
	/** How many already generated starts useNewChunksOnly skips before giving up. */
	private const MAX_GENERATED_STARTS_SKIPPED = 16;

	public const DIMENSION_OVERWORLD = 0;
	public const DIMENSION_NETHER = 1;
	public const DIMENSION_END = 2;

	/**
	 * Bedrock /locate names => Java structure ids or patterns (without namespace) searched for them.
	 * @phpstan-var array<int, array<string, list<string>>>
	 */
	private const BEDROCK_NAMES = [
		self::DIMENSION_OVERWORLD => [
			"ancient_city" => ["ancient_city"],
			"buried_treasure" => ["buried_treasure"],
			"mansion" => ["mansion"],
			"mineshaft" => ["mineshaft*"],
			"monument" => ["monument"],
			"pillager_outpost" => ["pillager_outpost"],
			"ruined_portal" => ["ruined_portal*"],
			"ruins" => ["ocean_ruin_*"],
			"shipwreck" => ["shipwreck*"],
			"stronghold" => ["stronghold"],
			//Bedrock's "temple" is any scattered feature: desert pyramid, jungle temple, witch hut, igloo
			"temple" => ["desert_pyramid", "jungle_pyramid", "swamp_hut", "igloo"],
			"desert_pyramid" => ["desert_pyramid"],
			"desert_temple" => ["desert_pyramid"],
			"jungle_temple" => ["jungle_pyramid"],
			"swamp_hut" => ["swamp_hut"],
			"witch_hut" => ["swamp_hut"],
			"igloo" => ["igloo"],
			"trail_ruins" => ["trail_ruins"],
			"trial_chambers" => ["trial_chambers"],
			"village" => ["village_*"],
			"ocean_ruin" => ["ocean_ruin_*"],
			"abandoned_camp" => ["abandoned_camp_*"],
			"ancientcity" => ["ancient_city"],
			"buriedtreasure" => ["buried_treasure"],
			"pillageroutpost" => ["pillager_outpost"],
			"ruinedportal" => ["ruined_portal*"],
			"woodland_mansion" => ["mansion"],
			"ocean_monument" => ["monument"],
			"ocean_ruins" => ["ocean_ruin_*"],
			"outpost" => ["pillager_outpost"],
			"trial_chamber" => ["trial_chambers"],
		],
		self::DIMENSION_NETHER => [
			"bastion_remnant" => ["bastion_remnant"],
			"bastionremnant" => ["bastion_remnant"],
			"fortress" => ["fortress"],
			"ruined_portal" => ["ruined_portal_nether"],
			"ruinedportal" => ["ruined_portal_nether"],
			"nether_fortress" => ["fortress"],
			"bastion" => ["bastion_remnant"],
		],
		self::DIMENSION_END => [
			"end_city" => ["end_city"],
			"endcity" => ["end_city"],
		],
	];

	/**
	 * Java Edition 26.x structure ids of each dimension (also accepted as /locate names).
	 * @phpstan-var array<int, list<string>>
	 */
	private const JAVA_IDS = [
		self::DIMENSION_OVERWORLD => [
			"abandoned_camp_bamboo_jungle", "abandoned_camp_birch_forest", "abandoned_camp_cherry_grove",
			"abandoned_camp_dappled_forest", "abandoned_camp_flower_forest", "abandoned_camp_forest",
			"abandoned_camp_meadow", "abandoned_camp_old_growth_birch_forest", "abandoned_camp_old_growth_pine_taiga",
			"abandoned_camp_old_growth_spruce_taiga", "abandoned_camp_pale_garden", "abandoned_camp_savanna",
			"abandoned_camp_snowy_taiga", "abandoned_camp_sparse_jungle", "abandoned_camp_swamp", "abandoned_camp_taiga",
			"abandoned_camp_windswept_forest", "abandoned_camp_wooded_badlands", "ancient_city", "buried_treasure",
			"desert_pyramid", "igloo", "jungle_pyramid", "mansion", "mineshaft", "mineshaft_mesa", "monument",
			"ocean_ruin_cold", "ocean_ruin_warm", "pillager_outpost", "ruined_portal", "ruined_portal_desert",
			"ruined_portal_jungle", "ruined_portal_mountain", "ruined_portal_ocean", "ruined_portal_swamp", "shipwreck",
			"shipwreck_beached", "stronghold", "swamp_hut", "trail_ruins", "trial_chambers", "village_desert",
			"village_plains", "village_savanna", "village_snowy", "village_taiga"
		],
		self::DIMENSION_NETHER => ["bastion_remnant", "fortress", "nether_fossil", "ruined_portal_nether"],
		self::DIMENSION_END => ["end_city"],
	];

	public function __construct(
		private int $dimension
	){
		if(!isset(self::BEDROCK_NAMES[$dimension])){
			throw new \InvalidArgumentException("Unknown dimension $dimension");
		}
	}

	public function getStructureIds() : array{
		return array_values(array_unique(array_merge(array_keys(self::BEDROCK_NAMES[$this->dimension]), self::JAVA_IDS[$this->dimension])));
	}

	/**
	 * The Java structure ids or patterns searched for a /locate name.
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getPatterns(string $structureId) : array{
		return self::BEDROCK_NAMES[$this->dimension][$structureId] ?? [$structureId];
	}

	public function findNearest(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly) : ?Vector3{
		$manager = VanillaStructureLocator::getStructureManager($world);
		if($manager === null){
			return null;
		}
		$accept = $newChunksOnly ? fn(int $chunkX, int $chunkZ) : bool => !$world->isChunkGenerated($chunkX, $chunkZ) : null;
		$best = self::searchPatterns($manager, $this->getPatterns($structureId), $from->getFloorX(), $from->getFloorZ(), $radiusChunks, $accept);
		return $best !== null ? new Vector3($best[3], $best[4], $best[5]) : null;
	}

	/**
	 * The nearest start matching any of the patterns: [structure id, start chunk x, start chunk z, x, y, z] or null.
	 * Once a pattern found a start, the next patterns are only searched up to that distance.
	 *
	 * @param string[] $patterns
	 * @phpstan-param list<string> $patterns
	 * @phpstan-param (\Closure(int, int) : bool)|null $accept
	 * @phpstan-return array{string, int, int, int, int, int}|null
	 * @throws \pocketmine\world\locate\LocateTimeoutException
	 */
	public static function searchPatterns(StructureManager $manager, array $patterns, int $x, int $z, int $radiusChunks, ?\Closure $accept = null, ?int $deadlineNs = null) : ?array{
		$best = null;
		$bestDistance = 0;
		foreach($patterns as $pattern){
			$maxChunks = $best === null ? $radiusChunks : min($radiusChunks, (int) ceil(sqrt($bestDistance) / 16) + 1);
			$found = $manager->locate($pattern, $x, $z, maxChunks: $maxChunks, accept: $accept, deadlineNs: $deadlineNs);
			if($found === null){
				continue;
			}
			$distance = ($found[3] - $x) ** 2 + ($found[5] - $z) ** 2;
			if($best === null || $distance < $bestDistance){
				$best = $found;
				$bestDistance = $distance;
			}
		}
		return $best;
	}

	public function findNearestAsync(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly, int $timeoutSeconds, \Closure $onCompletion) : void{
		$data = $world->getProvider()->getWorldData();
		$class = GeneratorManager::getInstance()->getGenerator($data->getGenerator())?->getGeneratorClass();
		if($class === null){
			$onCompletion(null, false, false);
			return;
		}
		$pool = LocateWorkerPool::getInstance($world->getServer());
		$patterns = $this->getPatterns($structureId);
		$x = $from->getFloorX();
		$z = $from->getFloorZ();
		$started = hrtime(true);
		/** @phpstan-var list<string> $excluded */
		$excluded = [];
		$submit = null;
		$submit = function() use (&$submit, &$excluded, $pool, $class, $data, $patterns, $x, $z, $radiusChunks, $newChunksOnly, $timeoutSeconds, $started, $world, $onCompletion) : void{
			$remaining = 0;
			if($timeoutSeconds > 0){
				$remaining = $timeoutSeconds - intdiv(hrtime(true) - $started, 1_000_000_000);
				if($remaining <= 0){
					$onCompletion(null, false, true);
					return;
				}
			}
			$pool->submitTask(new LocateVanillaStructureTask($class, $data->getSeed(), $data->getGeneratorOptions(), $patterns, $x, $z, $radiusChunks, $excluded, max(0, $remaining),
				function(array|string|null $result, bool $failed) use (&$submit, &$excluded, $newChunksOnly, $world, $onCompletion) : void{
					if($failed || !is_array($result)){
						$onCompletion(null, $failed, $result === LocateVanillaStructureTask::RESULT_TIMEOUT);
						return;
					}
					[$foundX, $foundY, $foundZ, $chunkX, $chunkZ] = $result;
					if($newChunksOnly && $world->isLoaded() && $world->isChunkGenerated($chunkX, $chunkZ)){
						//the worker cannot see which chunks are generated: skip this start and search again
						if(count($excluded) >= self::MAX_GENERATED_STARTS_SKIPPED){
							$onCompletion(null, false, false);
							return;
						}
						$excluded[] = "$chunkX:$chunkZ";
						($submit)();
						return;
					}
					$onCompletion(new Vector3($foundX, $foundY, $foundZ), false, false);
				}
			));
		};
		$submit();
	}
}
