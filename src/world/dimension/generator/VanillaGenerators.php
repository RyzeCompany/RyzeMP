<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator;

use pocketmine\world\dimension\generator\end\EndGenerator;
use pocketmine\world\dimension\generator\nether\NetherGenerator;
use pocketmine\world\generator\GeneratorManager;

/**
 * Registers the nether and end generators. Must run in onLoad, before the
 * worlds are loaded.
 */
final class VanillaGenerators{

	public const NETHER = "vanilla_nether";
	public const END = "vanilla_end";

	public static function register() : void{
		$manager = GeneratorManager::getInstance();
		$manager->addGenerator(NetherGenerator::class, self::NETHER, fn() => null, true);
		$manager->addGenerator(EndGenerator::class, self::END, fn() => null, true);
		$manager->addAlias(self::END, "end");
		$manager->addAlias(self::END, "the_end");
	}
}
