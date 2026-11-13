<?php

declare(strict_types=1);

namespace pocketmine\world\generator\vanilla;

use Closure;
use PHPUnit\Framework\TestCase;
use pocketmine\utils\Config;
use pocketmine\utils\Filesystem;

final class VanillaWorldgenSetupTest extends TestCase{
    private string $root;

    protected function setUp() : void{
        parent::setUp();
        $dir = sys_get_temp_dir() . "/ryze-vanilla-setup-" . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700));
        $this->root = $dir;
        self::assertTrue(mkdir($dir . "/worlds", 0700));
        file_put_contents($dir . "/server.properties",
            "server-ip=0.0.0.0\nserver-port=19132\nlevel-name=world\nlevel-type=DEFAULT\n");
    }

    protected function tearDown() : void{
        Filesystem::recursiveUnlink($this->root);
        parent::tearDown();
    }

    /** @return Closure(string): void */
    private function logger() : Closure{
        return static function(string $message) : void{};
    }

    /** @return array{path:string, hash:string} */
    private function realData() : array{
        $path = getenv("POCKETMINE_VANILLA_WORLDGEN_DATA");
        if($path === false || !is_dir($path)){
            self::markTestSkipped("Java 26.3 data are only available in the dedicated real-data CI");
        }
        return ["path" => $path, "hash" => VanillaDataIdentity::fingerprint($path)];
    }

    public function testRejectsFakeFingerprintBeforeEditingFiles() : void{
        $before = file_get_contents($this->root . "/server.properties");
        $this->expectException(\InvalidArgumentException::class);
        try{
            VanillaWorldgenSetup::configureWorlds(
                $this->root, $this->root . "/vanillamine-cache", "HASH_REAL", $this->logger());
        }finally{
            self::assertSame($before, file_get_contents($this->root . "/server.properties"));
            self::assertFileDoesNotExist($this->root . "/dimensions.yml");
        }
    }

    public function testFreshWorldIsPinnedAndRepeatedSetupIsIdempotent() : void{
        $data = $this->realData();
        VanillaWorldgenSetup::configureWorlds($this->root, $data["path"], $data["hash"], $this->logger());
        $props = new Config($this->root . "/server.properties", Config::PROPERTIES);
        self::assertSame("world", $props->get("level-name"));
        self::assertSame(VanillaWorldgenSetup::GENERATOR, $props->get("level-type"));
        self::assertSame("12345", $props->get("level-seed"));
        self::assertSame("19132", (string) $props->get("server-port"));
        $preset = "core-v6;data=" . $data["path"] . ";sha256=" . $data["hash"];
        self::assertSame($preset, $props->get("generator-settings"));
        $dims = new Config($this->root . "/dimensions.yml", Config::YAML);
        self::assertSame($preset, $dims->getAll()["nether"]["worldgen-preset"]);
        self::assertSame($preset, $dims->getAll()["end"]["worldgen-preset"]);
        $before = file_get_contents($this->root . "/server.properties");
        VanillaWorldgenSetup::configureWorlds($this->root, $data["path"], $data["hash"], $this->logger());
        self::assertSame($before, file_get_contents($this->root . "/server.properties"));
    }

    public function testExistingPocketMineWorldAndDimensionsAreNeverOverwritten() : void{
        $data = $this->realData();
        foreach(["world", "world_nether", "world_the_end"] as $name){
            self::assertTrue(mkdir($this->root . "/worlds/" . $name));
            file_put_contents($this->root . "/worlds/" . $name . "/level.dat", "SENTINEL-" . $name);
        }
        file_put_contents($this->root . "/dimensions.yml",
            "nether:\n  world: world_nether\n  enabled: true\nend:\n  world: world_the_end\n  enabled: true\n");
        VanillaWorldgenSetup::configureWorlds($this->root, $data["path"], $data["hash"], $this->logger());
        $props = new Config($this->root . "/server.properties", Config::PROPERTIES);
        self::assertSame("world_vanilla_test", $props->get("level-name"));
        $dims = new Config($this->root . "/dimensions.yml", Config::YAML);
        self::assertSame("world_vanilla_test_nether", $dims->getAll()["nether"]["world"]);
        self::assertSame("world_vanilla_test_end", $dims->getAll()["end"]["world"]);
        foreach(["world", "world_nether", "world_the_end"] as $name){
            self::assertSame("SENTINEL-" . $name,
                file_get_contents($this->root . "/worlds/" . $name . "/level.dat"));
        }
        self::assertSame("19132", (string) $props->get("server-port"));
    }

    public function testExistingPinnedVanillaWorldRejectsDifferentCacheIdentity() : void{
        $data = $this->realData();
        VanillaWorldgenSetup::configureWorlds($this->root, $data["path"], $data["hash"], $this->logger());
        self::assertTrue(mkdir($this->root . "/worlds/world"));
        file_put_contents($this->root . "/worlds/world/level.dat", "VANILLA-SENTINEL");
        $before = file_get_contents($this->root . "/server.properties");
        VanillaWorldgenSetup::configureWorlds($this->root, $data["path"], $data["hash"], $this->logger());
        $this->expectException(\RuntimeException::class);
        try{
            VanillaWorldgenSetup::configureWorlds($this->root, $data["path"], str_repeat("f", 64), $this->logger());
        }finally{
            self::assertSame($before, file_get_contents($this->root . "/server.properties"));
            self::assertSame("VANILLA-SENTINEL",
                file_get_contents($this->root . "/worlds/world/level.dat"));
        }
    }
}
