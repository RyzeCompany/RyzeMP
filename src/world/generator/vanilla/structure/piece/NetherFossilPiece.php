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

namespace pocketmine\world\generator\vanilla\structure\piece;

use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\processor\Processors;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;

/**
 * vanilla's NetherFossilPieces.NetherFossilPiece: a bone block fossil template, with sometimes a dried ghast next to it.
 */
final class NetherFossilPiece extends TemplatePiece{

	public function __construct(StructureTemplates $templates, string $location, int $x, int $y, int $z, private int $rotation){
		parent::__construct($templates, $location, $x, $y, $z);
	}

	protected function makeSettings(?JavaBlocks $blocks) : PlaceSettings{
		$settings = (new PlaceSettings())->setRotation($this->rotation);
		if($blocks !== null){
			$settings->addProcessor(Processors::ignoreStructureBlocksAndAir($blocks));
		}
		return $settings;
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		//vanilla encapsulates the fossil's box into the chunk box, placing the whole fossil (and the dried ghast) from
		//the first chunk that reaches it; placing each chunk's part gives the same blocks, since the template places no
		//air and the dried ghast only looks at the block it replaces
		$this->placeTemplate($c, $chunkBox, $pivot);
		$this->placeDriedGhast($c, $chunkBox);
	}

	/**
	 * NetherFossilPiece.placeDriedGhast()
	 */
	private function placeDriedGhast(StructurePlaceContext $c, BoundingBox $chunkBox) : void{
		$fossilBox = $this->box;
		[$cx, $cy, $cz] = $fossilBox->getCenter();
		$random = (new LegacyRandom($c->seed))->forkPositional()->at($cx, $cy, $cz);
		if($random->nextFloat() >= 0.5){
			return;
		}
		$x = $fossilBox->minX + $random->nextBoundedInt($fossilBox->getXSpan());
		$y = $fossilBox->minY;
		$z = $fossilBox->minZ + $random->nextBoundedInt($fossilBox->getZSpan());
		//vanilla checks the block first; both checks come before the last random call, so the order does not matter
		if(!$chunkBox->isInside($x, $y, $z) || !$c->blocks->isAir($c->level->getState($x, $y, $z))){
			return;
		}
		$rotation = $random->nextBoundedInt(4);
		$c->level->setState($x, $y, $z, Rotations::rotateState($c->blocks, $c->blocks->defaultOf("minecraft:dried_ghast"), $rotation));
	}
}
