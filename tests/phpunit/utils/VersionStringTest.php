<?php

declare(strict_types=1);

namespace pocketmine\utils;

use PHPUnit\Framework\TestCase;
use pocketmine\VersionInfo;

final class VersionStringTest extends TestCase{
    public function testPlainThreeComponentVersionIsValid() : void{
        $version = new VersionString("5.44.2");
        self::assertSame("5.44.2", $version->getFullVersion());
        self::assertSame("", $version->getSuffix());
    }

    public function testRyzeMPBetaVersion() : void{
        $version = new VersionString("1.0.0-beta.1", true);
        self::assertSame("1.0.0-beta.1+dev", $version->getFullVersion());
        self::assertSame("beta.1", $version->getSuffix());
        self::assertSame("1.0.0-beta.4", VersionInfo::BASE_VERSION);
        self::assertFalse(VersionInfo::IS_DEVELOPMENT_BUILD);
        self::assertSame("1.0.0-beta.4", VersionInfo::VERSION()->getFullVersion());
    }

    public function testSoftwareVersionDoesNotChangePluginApi() : void{
        self::assertSame("RyzeMP", VersionInfo::NAME);
        self::assertSame("5.44.2", VersionInfo::PLUGIN_API_VERSION);
        self::assertNotSame(VersionInfo::BASE_VERSION, VersionInfo::PLUGIN_API_VERSION);
    }

    public function testMalformedVersionsAreRejected() : void{
        $this->expectException(\InvalidArgumentException::class);
        new VersionString("not-a-version");
    }
}
