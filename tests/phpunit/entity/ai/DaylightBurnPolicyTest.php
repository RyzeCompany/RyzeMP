<?php
declare(strict_types=1);

namespace pocketmine\entity\ai;

use PHPUnit\Framework\TestCase;

final class DaylightBurnPolicyTest extends TestCase{
	public function testOnlyExposedOverworldUndeadCanIgnite() : void{
		self::assertTrue(DaylightBurnPolicy::isExposed(true, 0, 15, false, false));
		self::assertFalse(DaylightBurnPolicy::isExposed(false, 0, 15, false, false));
		self::assertFalse(DaylightBurnPolicy::isExposed(true, 9, 15, false, false));
		self::assertFalse(DaylightBurnPolicy::isExposed(true, 0, 14, false, false));
		self::assertFalse(DaylightBurnPolicy::isExposed(true, 0, 15, true, false));
		self::assertFalse(DaylightBurnPolicy::isExposed(true, 0, 15, false, true));
	}
}
