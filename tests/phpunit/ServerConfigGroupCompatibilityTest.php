<?php

/*
 * RyzeMP configuration compatibility regression tests.
 *
 * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace pocketmine;

use pocketmine\utils\Config;
use PHPUnit\Framework\TestCase;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function yaml_parse_file;

final class ServerConfigGroupCompatibilityTest extends TestCase{

    public function testOldPocketmineYmlPreservesValuesAndUsesSafeFallbacks() : void{
        $dir = sys_get_temp_dir() . '/ryzemp-config-compat-' . uniqid('', true);
        self::assertTrue(mkdir($dir));
        $yml = $dir . '/pocketmine.yml';
        $properties = $dir . '/server.properties';
        // Representative configuration saved by RyzeMP before its VanillaMine-base update.
        $original = "# Keep my server settings intact\n"
            . "settings:\n  force-language: true\n"
            . "fishing:\n  enabled: false\n  pvp-enabled: false\n  max-distance: 17\n"
            . "network:\n  compression-level: 6\n"
            . "memory:\n  async-worker-hard-limit: 256\n"
            . "auto-report:\n  enabled: false\n"
            . "auto-updater:\n  enabled: false\n";
        file_put_contents($yml, $original);
        file_put_contents($properties, "server-port=19132\nlevel-type=flat\n");
        try{
            $config = new ServerConfigGroup(
                new Config($yml, Config::YAML, []),
                new Config($properties, Config::PROPERTIES, [])
            );
            self::assertTrue($config->getPropertyBool(YmlServerProperties::SETTINGS_FORCE_LANGUAGE, false));
            self::assertFalse($config->getPropertyBool("fishing.enabled", true));
            self::assertFalse($config->getPropertyBool("fishing.pvp-enabled", true));
            self::assertSame(17, $config->getPropertyInt("fishing.max-distance", 32));
            self::assertSame(6, $config->getPropertyInt(YmlServerProperties::NETWORK_COMPRESSION_LEVEL, 1));
            self::assertSame(256, $config->getPropertyInt(YmlServerProperties::MEMORY_ASYNC_WORKER_HARD_LIMIT, 384));
            // New options missing from an older file must use their runtime defaults.
            self::assertSame(32, $config->getPropertyInt(YmlServerProperties::NETWORK_CONNECTION_LIMITS_PENDING_LOGINS_PER_IP, 32));
            self::assertSame("", $config->getPropertyString(YmlServerProperties::NETWORK_NETHERNET_HTTPS_CERTIFICATE, ""));
            self::assertTrue($config->getPropertyBool(YmlServerProperties::CHUNK_GENERATION_VANILLA_NATIVE_ENABLED, true));
            self::assertFalse($config->getPropertyBool(YmlServerProperties::AUTO_REPORT_ENABLED, false));
            self::assertFalse($config->getPropertyBool(YmlServerProperties::AUTO_UPDATER_ENABLED, false));
            // An existing world type, port and YAML comments must not be overwritten.
            self::assertSame("flat", $config->getConfigString(ServerProperties::DEFAULT_WORLD_GENERATOR));
            self::assertSame(19132, $config->getConfigInt(ServerProperties::SERVER_PORT_IPV4, 0));
            $config->save();
            self::assertSame($original, file_get_contents($yml));
        }finally{
            unlink($yml);
            unlink($properties);
            rmdir($dir);
        }
    }

    public function testNewInstallTemplateAvoidsInheritedExternalServices() : void{
        $parsed = yaml_parse_file(__DIR__ . '/../../resources/pocketmine.yml');
        self::assertIsArray($parsed);
        self::assertFalse($parsed['auto-report']['enabled']);
        self::assertFalse($parsed['auto-report']['send-code']);
        self::assertFalse($parsed['auto-report']['send-settings']);
        self::assertFalse($parsed['auto-updater']['enabled']);
        self::assertSame("", $parsed['auto-updater']['host']);
        self::assertTrue($parsed['fishing']['enabled']);
        self::assertSame(32, $parsed['fishing']['max-distance']);
        self::assertSame("", $parsed['network']['nethernet']['https']['certificate']);
        self::assertTrue($parsed['chunk-generation']['vanilla-native']['enabled']);
        self::assertSame(1, $parsed['network']['compression-level']);
        self::assertSame(384, $parsed['memory']['async-worker-hard-limit']);
    }
}
