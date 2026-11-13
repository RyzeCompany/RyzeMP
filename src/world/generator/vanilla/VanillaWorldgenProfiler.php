<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use function array_column;
use function count;
use function getenv;
use function microtime;
use function round;
use function sort;
use function sprintf;

/**
 * Optional worker-local microprofiling for native/PHP worldgen. Only aggregated
 * measurements are sent back from PopulationTask to the main server thread.
 * No player names, coordinates, seeds or chunk contents are logged.
 *
 * Profiling is disabled unless RYZEMP_WORLDGEN_PROFILE=true or 1.
 */
final class VanillaWorldgenProfiler{
    /** @var array<string, float> */
    private static array $stages = [];
    private static bool $active = false;

    /** @var list<array{queue: float, worker: float, roundtrip: float, verify: float, terrain: float, blocks: float, decoration: float}> */
    private static array $samples = [];

    private function __construct(){}

    public static function enabled() : bool{
        $flag = getenv("RYZEMP_WORLDGEN_PROFILE");
        return $flag === "true" || $flag === "1";
    }

    public static function begin() : void{
        self::$active = self::enabled();
        self::$stages = [];
    }

    public static function active() : bool{
        return self::$active;
    }

    /** Record elapsed milliseconds for a stage, including repeat work in neighbour chunks. */
    public static function add(string $stage, float $milliseconds) : void{
        if(self::$active){
            self::$stages[$stage] = (self::$stages[$stage] ?? 0.0) + $milliseconds;
        }
    }

    /** @return array<string, float> */
    public static function finish() : array{
        $result = self::$stages;
        self::$stages = [];
        self::$active = false;
        return $result;
    }

    /**
     * Runs only on the main server thread, at most once per 16 completed
     * Vanilla population tasks, to avoid flooding the console.
     *
     * @param array<string, float> $stages
     */
    public static function report(array $stages, float $queueMs, float $workerMs, float $roundtripMs) : void{
        if($stages === []){
            return;
        }
        self::$samples[] = [
            "queue" => $queueMs, "worker" => $workerMs, "roundtrip" => $roundtripMs,
            "verify" => $stages["verify"] ?? 0.0,
            "terrain" => $stages["terrain"] ?? 0.0,
            "blocks" => $stages["blocks"] ?? 0.0,
            "decoration" => $stages["decoration"] ?? 0.0
        ];
        if(count(self::$samples) < 16){
            return;
        }
        $samples = self::$samples;
        self::$samples = [];

        $average = static function(string $key) use ($samples) : float{
            $sum = 0.0;
            foreach($samples as $sample){
                $sum += $sample[$key];
            }
            return $sum / count($samples);
        };
        $tail = static function(string $key) use ($samples) : float{
            $values = array_column($samples, $key);
            sort($values, SORT_NUMERIC);
            return $values[(int) ceil(count($values) * 0.95) - 1];
        };
        \GlobalLogger::get()->info(sprintf(
            "[Worldgen perf] %d tasks: queue avg %.0f/p95 %.0f ms; worker avg %.0f/p95 %.0f ms; total avg %.0f/p95 %.0f ms; worker stages avg verify %.0f, terrain %.0f, block/biome %.0f, decoration %.0f ms",
            count($samples), $average("queue"), $tail("queue"),
            $average("worker"), $tail("worker"),
            $average("roundtrip"), $tail("roundtrip"),
            $average("verify"), $average("terrain"), $average("blocks"), $average("decoration")
        ));
    }
}
