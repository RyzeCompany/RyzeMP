<?php
declare(strict_types=1);

namespace pocketmine\world\mobs;

use PHPUnit\Framework\TestCase;
use pocketmine\ServerConfigGroup;
use pocketmine\utils\Config;
use pocketmine\world\mobs\spawn\MobSpawningSettings;
use Symfony\Component\Filesystem\Filesystem;

final class MobSpawningSettingsTest extends TestCase{
	/** @var list<string> */
	private array $paths = [];

	/** @param array<string, mixed> $mobs */
	private function settings(array $mobs, string $folder = "world", int $radius = 4) : MobSpawningSettings{
		$base = sys_get_temp_dir() . "/ryzemp-mob-cfg-" . bin2hex(random_bytes(10));
		$this->paths[] = $base;
		$config = new Config($base . ".yml", Config::YAML, ["mobs" => $mobs]);
		$props = new Config($base . "-props.yml", Config::YAML);
		return MobSpawningSettings::fromConfig(new ServerConfigGroup($config, $props), $folder, $radius);
	}

	protected function tearDown() : void{
		$files = new Filesystem();
		foreach($this->paths as $path){
			$files->remove([$path . ".yml", $path . "-props.yml"]);
		}
		$this->paths = [];
	}

	public function testVanillaDefaultPopulationAndDistances() : void{
		$s = $this->settings(["enabled" => true, "spawning" => ["mode" => "vanilla"]]);
		self::assertTrue($s->aiEnabled);
		self::assertTrue($s->enabled);
		self::assertSame("vanilla", $s->mode);
		self::assertSame(11, $s->chunkChance);
		self::assertSame(200, $s->globalCap);
		self::assertSame(32, $s->maxAttempts);
		self::assertSame(44, $s->maxDistance);
		self::assertSame(44, $s->despawnDistance);
		self::assertSame(4, $s->populationCap("animal", true));
		self::assertSame(0, $s->populationCap("animal", false));
		self::assertSame(8, $s->populationCap("monster", true));
		self::assertSame(16, $s->populationCap("monster", false));
		self::assertSame(36, $s->populationCap("water_animal", true));
		self::assertSame(0, $s->populationCap("water_animal", false));
		$largerRadius = $this->settings(["enabled" => true], "world", 6);
		self::assertSame(96, $largerRadius->maxDistance);
		self::assertSame(128, $largerRadius->despawnDistance);
	}

	public function testDisabledWorldsAndLegacyMode() : void{
		$mobs = [
			"enabled" => true,
			"disabled-worlds" => ["lobby", "arena"],
			"spawning" => ["mode" => "legacy", "hostile-cap" => 12, "passive-cap" => 7]
		];
		$s = $this->settings($mobs, "lobby");
		self::assertTrue($s->aiEnabled);
		self::assertFalse($s->enabled);
		self::assertTrue($s->isWorldDisabled("lobby"));
		self::assertFalse($s->isWorldDisabled("world"));
		self::assertSame("legacy", $s->mode);
		self::assertSame(12, $s->populationCap("monster", true));
		self::assertSame(7, $s->populationCap("animal", true));
		self::assertTrue($this->settings($mobs, "world")->enabled);
	}

	public function testVanillaSpawnLimitsAndExplicitDistances() : void{
		$s = $this->settings(["enabled" => true, "spawning" => [
			"spawn-limits" => ["animal" => 9, "monster" => 0, "water_animal" => 11],
			"global-cap" => 80,
			"max-distance" => 74,
			"instant-despawn-distance" => 100,
			"chunk-chance" => 55,
			"max-attempts-per-tick" => 9,
			"count-all-mobs-for-spawning" => false
		]]);
		self::assertSame(9, $s->populationCap("animal", true));
		self::assertSame(9, $s->populationCap("animal", false));
		self::assertSame(0, $s->populationCap("monster", false));
		self::assertSame(11, $s->populationCap("water_animal", true));
		self::assertSame(80, $s->globalCap);
		self::assertSame(74, $s->maxDistance);
		self::assertSame(100, $s->despawnDistance);
		self::assertSame(55, $s->chunkChance);
		self::assertSame(9, $s->maxAttempts);
		self::assertFalse($s->countAll);
	}

	public function testOldRyzeMPKeysAreStillReadWithoutOverridesOfNewKeys() : void{
		$s = $this->settings(["enabled" => true, "spawning" => [
			"interval-ticks" => 40, "max-attempts" => 6, "max-total" => 72,
			"max-animals" => 24, "max-monsters" => 40, "max-aquatic" => 12
		]]);
		self::assertSame("legacy", $s->mode);
		self::assertSame(40, $s->hostileInterval);
		self::assertSame(40, $s->passiveInterval);
		self::assertSame(6, $s->maxAttempts);
		self::assertSame(72, $s->globalCap);
		self::assertSame(24, $s->spawnLimits["animal"]);
		self::assertSame(40, $s->spawnLimits["monster"]);

		$new = $this->settings(["enabled" => true, "spawning" => [
			"spawn-limits" => ["animal" => 5], "max-animals" => 27
		]]);
		self::assertSame(5, $new->populationCap("animal", true));
	}

	public function testUnsupportedFeaturesAreReportedInsteadOfSilentlyUsed() : void{
		$s = $this->settings(["enabled" => true, "spawning" => [
			"vanilla-rules" => true, "phantoms" => true,
			"wandering-traders" => true, "patrols" => ["enabled" => true]
		], "raids" => ["enabled" => true]]);
		self::assertCount(5, $s->warnings);
	}

	public function testUnknownModeAndBadDisabledWorldsAreRejected() : void{
		$this->expectException(\InvalidArgumentException::class);
		$this->settings(["enabled" => true, "spawning" => ["mode" => "unimplemented"]]);
	}
}
