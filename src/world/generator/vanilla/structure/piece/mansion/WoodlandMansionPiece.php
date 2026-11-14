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

namespace pocketmine\world\generator\vanilla\structure\piece\mansion;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\math\Int64;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\OrientedPiece;
use pocketmine\world\generator\vanilla\structure\piece\TemplatePiece;
use pocketmine\world\generator\vanilla\structure\processor\Processors;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\EntityConversion;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use function str_starts_with;

/**
 * vanilla's WoodlandMansionPieces.WoodlandMansionPiece: one template of minecraft:woodland_mansion/, with the chests and
 * the illagers/allays of its data markers.
 */
final class WoodlandMansionPiece extends TemplatePiece{
	private const LOOT_TABLE = "minecraft:chests/woodland_mansion";
	private const CHEST_FACING = [
		"ChestWest" => Direction::WEST,
		"ChestEast" => Direction::EAST,
		"ChestSouth" => Direction::SOUTH,
		"ChestNorth" => Direction::NORTH,
	];

	public function __construct(
		StructureTemplates $templates,
		public readonly string $templateName,
		int $x,
		int $y,
		int $z,
		private int $rotation,
		private int $mirror = Rotations::MIRROR_NONE
	){
		parent::__construct($templates, "minecraft:woodland_mansion/" . $templateName, $x, $y, $z);
	}

	/**
	 * WoodlandMansionPiece.makeSettings(mirror, rotation)
	 */
	protected function makeSettings(?JavaBlocks $blocks) : PlaceSettings{
		$settings = (new PlaceSettings())->setRotation($this->rotation)->setMirror($this->mirror);
		$settings->ignoreEntities = true;
		if($blocks !== null){
			$settings->addProcessor(Processors::ignoreStructureBlocks($blocks));
		}
		return $settings;
	}

	/**
	 * WoodlandMansionPiece.handleDataMarker()
	 */
	protected function handleDataMarker(StructurePlaceContext $c, string $metadata, int $x, int $y, int $z, BoundingBox $box) : void{
		if(str_starts_with($metadata, "Chest")){
			$state = $c->blocks->defaultOf("minecraft:chest");
			$facing = self::CHEST_FACING[$metadata] ?? null;
			if($facing !== null){
				//the facing follows the rotation only (vanilla ignores the mirror here)
				$state = $c->blocks->with($state, "facing", Direction::NAMES[Rotations::rotateDirection($facing, $this->rotation)]);
			}
			OrientedPiece::createChestAt($c, $box, $c->random, $x, $y, $z, self::LOOT_TABLE, $state);
			return;
		}
		$mobs = [];
		switch($metadata){
			case "Mage":
				$mobs[] = "minecraft:evoker";
				break;
			case "Warrior":
				$mobs[] = "minecraft:vindicator";
				break;
			case "Group of Allays":
				//level.getRandom() = WorldGenRegion.getRandom() (the worldgen_region_random of the chunk), not the
				//decoration random; without it, a random of the marker position
				$random = $c->level->regionRandom ?? new LegacyRandom(Int64::positionSeed($x, $y, $z));
				$numberOfAllays = $random->nextBoundedInt(3) + 1;
				for($i = 0; $i < $numberOfAllays; ++$i){
					$mobs[] = "minecraft:allay";
				}
				break;
			default:
				return;
		}
		foreach($mobs as $mob){
			//setPersistenceRequired(), snapTo(pos bottom centre, 0, 0), addFreshEntityWithPassengers(); PocketMine mobs
			//are persistent by default (finalizeSpawn's equipment is left to the entity implementation)
			$nbt = EntityConversion::pendingEntity(CompoundTag::create()->setString("id", $mob), $x + 0.5, $y, $z + 0.5, 0.0, 0.0);
			if($nbt !== null){
				$c->level->addEntity($x, $z, $nbt);
			}
			$c->level->setState($x, $y, $z, $c->blocks->air);
		}
	}
}
