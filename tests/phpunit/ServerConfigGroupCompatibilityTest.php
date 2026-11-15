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
            . "auto-updater:\n  enabled: true\n  host: updates.vanillamine.com\n";
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
            // Legacy updater keys remain untouched in an existing YAML, but no updater is booted.
            self::assertTrue($config->getPropertyBool("auto-updater.enabled", false));
            self::assertSame("updates.vanillamine.com", $config->getPropertyString("auto-updater.host", ""));
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

    public function testQuotedBooleanValuesCannotAccidentallyEnableExternalReportingOrNetworking() : void{
        $dir = sys_get_temp_dir() . '/ryzemp-quoted-bool-' . uniqid('', true);
        self::assertTrue(mkdir($dir));
        $yml = $dir . '/pocketmine.yml';
        $props = $dir . '/server.properties';
        file_put_contents($yml,
            "auto-report:\n  enabled: \"false\"\n"
            . "auto-updater:\n  enabled: \"off\"\n"
            . "network:\n  proxy:\n    enabled: \"no\"\n"
            . "fishing:\n  enabled: \"0\"\n  pvp-enabled: \"true\"\n"
            . "mobs:\n  enabled: \"yes\"\n");
        file_put_contents($props, "server-port=19132\n");
        try{
            $group = new ServerConfigGroup(
                new Config($yml, Config::YAML, []),
                new Config($props, Config::PROPERTIES, [])
            );
            self::assertFalse($group->getPropertyBool(YmlServerProperties::AUTO_REPORT_ENABLED, true));
            // The legacy update option is intentionally not part of the RyzeMP API.
            self::assertFalse(defined(YmlServerProperties::class . "::AUTO_UPDATER_ENABLED"));
            self::assertFalse($group->getPropertyBool(YmlServerProperties::NETWORK_PROXY_ENABLED, true));
            self::assertFalse($group->getPropertyBool("fishing.enabled", true));
            self::assertTrue($group->getPropertyBool("fishing.pvp-enabled", false));
            self::assertTrue($group->getPropertyBool(YmlServerProperties::MOBS_ENABLED, false));
        }finally{
            unlink($yml);
            unlink($props);
            rmdir($dir);
        }
    }

    public function testLegacyAutoUpdaterAndNotificationsAreNotPartOfRyzeMP() : void{
        self::assertFalse(method_exists(Server::class, "getUpdater"));
        self::assertFalse(class_exists("pocketmine\\updater\\UpdateChecker"));
        self::assertFalse(class_exists("pocketmine\\updater\\UpdateCheckTask"));
        self::assertFalse(class_exists("pocketmine\\updater\\UpdateInfo"));
        self::assertFalse(class_exists("pocketmine\\event\\server\\UpdateNotifyEvent"));

        // The server no longer prints inherited documentation/plugin links on startup.
        $serverCode = file_get_contents(__DIR__ . '/../../src/Server.php');
        self::assertIsString($serverCode);
        self::assertStringNotContainsString('docs.vanillamine.com', $serverCode);
        self::assertStringNotContainsString('plugins.vanillamine.com', $serverCode);
        self::assertStringNotContainsString('pocketmine_server_url_freePlugins', $serverCode);
    }

    public function testNewInstallTemplateAvoidsInheritedExternalServices() : void{
        $parsed = yaml_parse_file(__DIR__ . '/../../resources/pocketmine.yml');
        self::assertIsArray($parsed);
        self::assertFalse($parsed['auto-report']['enabled']);
        self::assertFalse($parsed['auto-report']['send-code']);
        self::assertFalse($parsed['auto-report']['send-settings']);
        self::assertArrayNotHasKey('auto-updater', $parsed);
        self::assertTrue($parsed['fishing']['enabled']);
        self::assertSame(32, $parsed['fishing']['max-distance']);
        self::assertSame("", $parsed['network']['nethernet']['https']['certificate']);
        self::assertTrue($parsed['chunk-generation']['vanilla-native']['enabled']);
        self::assertSame(1, $parsed['network']['compression-level']);
        self::assertSame(384, $parsed['memory']['async-worker-hard-limit']);
    }
}
