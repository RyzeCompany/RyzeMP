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

namespace pocketmine\world\generator;

use PHPUnit\Framework\TestCase;
use pocketmine\world\generator\hell\Nether;
use pocketmine\world\generator\normal\Normal;
use pocketmine\world\generator\vanilla\VanillaGenerator;
use pocketmine\world\generator\vanilla\VanillaNetherGenerator;

final class GeneratorManagerTest extends TestCase{

	public function testOverwriteReplacesOrdinaryGenerators() : void{
		$manager = new GeneratorManager();
		$manager->addGenerator(Nether::class, "normal", fn() => null, overwrite: true);
		self::assertSame(Nether::class, $manager->getGenerator("normal")?->getGeneratorClass());
	}

	public function testOverwriteCannotReplaceVanillaGenerators() : void{
		$manager = new GeneratorManager();
		foreach(["vanilla", "VANILLA_NETHER", "vanilla_end"] as $name){
			$before = $manager->getGenerator($name)?->getGeneratorClass();
			$manager->addGenerator(Normal::class, $name, fn() => null, overwrite: true);
			self::assertSame($before, $manager->getGenerator($name)?->getGeneratorClass(), $name);
		}
		self::assertSame(VanillaGenerator::class, $manager->getGenerator("vanilla")?->getGeneratorClass());
		self::assertSame(VanillaNetherGenerator::class, $manager->getGenerator("vanilla_nether")?->getGeneratorClass());
	}

	public function testDuplicateWithoutOverwriteStillThrows() : void{
		$manager = new GeneratorManager();
		$this->expectException(\InvalidArgumentException::class);
		$manager->addGenerator(Normal::class, "vanilla", fn() => null);
	}

	public function testOtherNewGeneratorsCanBeRegistered() : void{
		$manager = new GeneratorManager();
		$manager->addGenerator(Normal::class, "vanilla_normal", fn() => null, overwrite: true);
		self::assertSame(Normal::class, $manager->getGenerator("vanilla_normal")?->getGeneratorClass());
	}
}
