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
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\processor\Processors;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\template\TemplatePlacer;

/**
 * vanilla's ShipwreckPieces.ShipwreckPiece.
 */
final class ShipwreckPiece extends TemplatePiece{
	private const MARKERS_TO_LOOT = [
		"map_chest" => "minecraft:chests/shipwreck_map",
		"treasure_chest" => "minecraft:chests/shipwreck_treasure",
		"supply_chest" => "minecraft:chests/shipwreck_supply",
	];

	public function __construct(StructureTemplates $templates, string $location, int $x, int $y, int $z, private int $rotation, public readonly bool $beached){
		parent::__construct($templates, $location, $x, $y, $z);
	}

	protected function makeSettings(?JavaBlocks $blocks) : PlaceSettings{
		$settings = (new PlaceSettings())->setRotation($this->rotation)->setPivot(4, 15);
		if($blocks !== null){
			$settings->addProcessor(Processors::ignoreStructureBlocksAndAir($blocks));
		}
		return $settings;
	}

	protected function handleDataMarker(StructurePlaceContext $c, string $metadata, int $x, int $y, int $z, BoundingBox $box) : void{
		$loot = self::MARKERS_TO_LOOT[$metadata] ?? null;
		if($loot !== null){
			TemplatePlacer::lootChest($c, $x, $y - 1, $z, $loot);
		}
	}
}
