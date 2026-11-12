<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator;

use pocketmine\world\dimension\generator\end\EndGenerator;
use pocketmine\world\dimension\generator\nether\NetherGenerator;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\vanilla\RyzeOverworldGenerator;

/**
 * Registers the existing dimension generators and opt-in experimental Overworld.
 * Called before worlds are loaded; does not change existing generator selection.
 */
final class VanillaGenerators{

	public const NETHER = "vanilla_nether";
	public const END = "vanilla_end";
	public const OVERWORLD_EXPERIMENTAL = "ryze_vanilla_overworld";

	public static function register() : void{
		$manager = GeneratorManager::getInstance();
		$manager->addGenerator(NetherGenerator::class, self::NETHER, fn() => null, true);
		$manager->addGenerator(EndGenerator::class, self::END, fn() => null, true);
		if($manager->getGenerator('end') === null){
			$manager->addAlias(self::END, 'end');
		}
		if($manager->getGenerator('the_end') === null){
			$manager->addAlias(self::END, 'the_end');
		}
		$manager->addGenerator(
			RyzeOverworldGenerator::class,
			self::OVERWORLD_EXPERIMENTAL,
			static fn(string $preset) : ?InvalidGeneratorOptionsException => $preset === '' ? null : new InvalidGeneratorOptionsException('The experimental Ryze Overworld generator does not support presets yet'),
			true
		);
	}
}
