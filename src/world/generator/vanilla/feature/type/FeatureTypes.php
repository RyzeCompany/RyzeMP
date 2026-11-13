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

namespace pocketmine\world\generator\vanilla\feature\type;

use pocketmine\world\generator\vanilla\feature\Feature;
use pocketmine\world\generator\vanilla\feature\FeatureRegistry;

/**
 * Factory of the implemented configured feature types (Java 26.3 names, without namespace).
 */
final class FeatureTypes{

	private function __construct(){
		//NOOP
	}

	/**
	 * @param mixed[] $json
	 */
	public static function create(FeatureRegistry $registry, string $type, array $json) : ?Feature{
		return match($type){
			"no_op" => new NoOpFeature(),
			"ore" => new OreFeature($registry, $json),
			"scattered_ore" => new OreFeature($registry, $json, true),
			"simple_block" => new SimpleBlockFeature($registry, $json),
			"random_selector" => new SelectorFeature($registry, SelectorFeature::RANDOM, $json),
			"simple_random_selector" => new SelectorFeature($registry, SelectorFeature::SIMPLE_RANDOM, $json),
			"weighted_random_selector" => new SelectorFeature($registry, SelectorFeature::WEIGHTED, $json),
			"random_boolean_selector" => new SelectorFeature($registry, SelectorFeature::BOOLEAN, $json),
			"sequence" => new SelectorFeature($registry, SelectorFeature::SEQUENCE, $json),
			"tree" => new tree\TreeFeature($registry, $json),
			"sculk_patch" => new SculkPatchFeature($registry, $json),
			"root_system" => new RootSystemFeature($registry, $json),
			"iceberg" => new IcebergFeature($registry, $json),
			"template" => TemplateFeatures::template($registry, $json),
			"fossil" => TemplateFeatures::fossil($registry, $json),
			"huge_brown_mushroom" => new HugeMushroomFeature($registry, $json, false),
			"huge_red_mushroom" => new HugeMushroomFeature($registry, $json, true),
			"fallen_tree" => new FallenTreeFeature($registry, $json),
			"block_column" => new BlockColumnFeature($registry, $json),
			"vegetation_patch" => new VegetationPatchFeature($registry, $json, false),
			"waterlogged_vegetation_patch" => new VegetationPatchFeature($registry, $json, true),
			"multiface_growth" => new MultifaceGrowthFeature($registry, $json),
			"disk" => new DiskFeature($registry, $json),
			"spring_feature" => DiskFeature::spring($registry, $json),
			"lake" => new LakeFeature($registry, $json),
			"monster_room" => new MonsterRoomFeature($registry),
			"geode" => new GeodeFeature($registry, $json),
			"underwater_magma" => MiscFeatures::underwaterMagma($registry, $json),
			"freeze_top_layer" => MiscFeatures::freezeTopLayer($registry),
			"blue_ice" => MiscFeatures::blueIce($registry),
			"block_pile" => MiscFeatures::blockPile($registry, $json),
			"spike" => MiscFeatures::spike($registry, $json),
			"vines" => MiscFeatures::vines($registry),
			"block_blob" => MiscFeatures::blockBlob($registry, $json),
			"overlay" => MiscFeatures::overlay($registry, $json),
			"speleothem", "speleothem_cluster", "large_dripstone" => SpeleothemFeatures::create($registry, $json, $type),
			"bamboo" => PlantFeatures::bamboo($registry, $json),
			"coral_tree" => PlantFeatures::coralTree($registry, $json),
			"coral_claw" => PlantFeatures::coralClaw($registry, $json),
			//Nether
			"random_neighbor_spread" => NetherFeatures::randomNeighborSpread($registry, $json),
			"single_block_pillar" => NetherFeatures::singleBlockPillar($registry, $json),
			"projected_random_patchy_square" => NetherFeatures::projectedRandomPatchySquare($registry, $json),
			"stepped_column_cluster" => NetherFeatures::steppedColumnCluster($registry, $json),
			"delta_feature" => NetherFeatures::delta($registry, $json),
			"netherrack_replace_blobs" => NetherFeatures::replaceBlobs($registry, $json),
			"huge_fungus" => NetherFeatures::hugeFungus($registry, $json),
			//End
			"end_spike" => EndFeatures::spike($registry, $json),
			"end_platform" => EndFeatures::platform($registry),
			"end_gateway" => EndFeatures::gateway($registry, $json),
			"chorus_plant" => EndFeatures::chorusPlant($registry),
			"end_island" => EndFeatures::island($registry),
			default => null
		};
	}
}
