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

namespace pocketmine\world\generator\vanilla\structure\type;

use pocketmine\world\generator\vanilla\structure\piece\JungleTemplePiece;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiece;
use pocketmine\world\generator\vanilla\structure\StructureGenContext;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;

/**
 * minecraft:jungle_temple (vanilla's JungleTempleStructure).
 */
final class JungleTempleStructure extends SinglePieceStructure{

	public function __construct(){
		parent::__construct(12, 15);
	}

	protected function createPiece(StructureGenContext $context) : StructurePiece{
		$piece = new JungleTemplePiece($context->random, $context->getMinBlockX(), $context->getMinBlockZ());
		//ScatteredFeaturePiece.updateAverageGroundHeight(), in the start chunk
		$piece->box->move(0, $context->averageHeightInStartChunk($piece->box, TerrainSampler::WORLD_SURFACE_WG) - $piece->box->minY, 0);
		return $piece;
	}
}
