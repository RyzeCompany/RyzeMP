<?php

declare(strict_types=1);

namespace pocketmine;

use PHPUnit\Framework\TestCase;

final class VersionIdentityTest extends TestCase{
    public function testSoftwareVersionIsIndependentFromPluginApi() : void{
        self::assertSame("RyzeMP", VersionInfo::NAME);
        self::assertSame("1.0.0-beta.6", VersionInfo::BASE_VERSION);
        self::assertSame("5.44.2", VersionInfo::PLUGIN_API_VERSION);
        self::assertSame("5.44.2-p1", VersionInfo::COMPOSER_API_VERSION);
        self::assertSame("beta", VersionInfo::BUILD_CHANNEL);
        self::assertFalse(VersionInfo::IS_DEVELOPMENT_BUILD);
        $server = (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();
        self::assertSame(VersionInfo::PLUGIN_API_VERSION, $server->getApiVersion());
    }
}
