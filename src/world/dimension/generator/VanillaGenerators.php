<?php

declare(strict_types=1);

namespace pocketmine\world\dimension\generator;

use pocketmine\world\dimension\generator\end\EndGenerator;
use pocketmine\world\dimension\generator\nether\NetherGenerator;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\generator\InvalidGeneratorOptionsException;
use pocketmine\world\generator\vanilla\RyzeOverworldGenerator;
use pocketmine\world\generator\vanilla\RyzeVanillaNetherCoreGenerator;
use pocketmine\world\generator\vanilla\RyzeVanillaEndCoreGenerator;
use pocketmine\world\generator\vanilla\VanillaDensityChunkWriter;

/**
 * Registers the existing dimension generators and opt-in experimental Overworld.
 * Called before worlds are loaded; does not change existing generator selection.
 */
final class VanillaGenerators{

	public const NETHER = "vanilla_nether";
	public const END = "vanilla_end";
	public const OVERWORLD_EXPERIMENTAL = "ryze_vanilla_overworld";
	public const NETHER_CORE = "ryze_vanillamine_nether_core_v1";
	public const END_CORE = "ryze_vanillamine_end_core_v1";

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
		// These names are opt-in. DimensionManager still owns its existing worlds,
		// Nether/End generator names and portal transitions unchanged.
		foreach([self::NETHER_CORE => [RyzeVanillaNetherCoreGenerator::class, "nether"], self::END_CORE => [RyzeVanillaEndCoreGenerator::class, "end"]] as $name => [$class, $dimension]){
			$manager->addGenerator(
				$class,
				$name,
				static function(string $preset) use ($dimension) : ?InvalidGeneratorOptionsException{
					try{
						VanillaDensityChunkWriter::validatePreset($preset, $dimension);
						return null;
					}catch(InvalidGeneratorOptionsException $e){
						return $e;
					}
				},
				true
			);
		}
		$manager->addGenerator(
			RyzeOverworldGenerator::class,
			self::OVERWORLD_EXPERIMENTAL,
			static function(string $preset) : ?InvalidGeneratorOptionsException{
				if($preset === ''){
					return null; // Historical experimental algorithm preserved.
				}
				try{
					VanillaDensityChunkWriter::validatePreset($preset);
					return null;
				}catch(InvalidGeneratorOptionsException $e){
					return $e;
				}
			},
			true
		);
	}
}
