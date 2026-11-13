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

use pocketmine\block\tile\ItemFrame as ItemFrameTile;
use pocketmine\block\tile\Tile;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\processor\Processors;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\template\TemplatePlacer;
use function str_starts_with;

/**
 * vanilla's EndCityPieces.EndCityPiece: one template of minecraft:end_city/, with its loot chests, shulker sentries and
 * the elytra item frame of the ship.
 */
final class EndCityPiece extends TemplatePiece{
	private const LOOT_TABLE = "minecraft:chests/end_city_treasure";

	public function __construct(
		StructureTemplates $templates,
		public readonly string $templateName,
		int $x,
		int $y,
		int $z,
		public readonly int $rotation,
		private bool $overwrite
	){
		parent::__construct($templates, "minecraft:end_city/" . $templateName, $x, $y, $z);
	}

	/**
	 * EndCityPiece.makeSettings(overwrite, rotation): structure blocks are never placed; air only when overwriting.
	 */
	protected function makeSettings(?JavaBlocks $blocks) : PlaceSettings{
		$settings = (new PlaceSettings())->setRotation($this->rotation);
		if($blocks !== null){
			$settings->addProcessor($this->overwrite ? Processors::ignoreStructureBlocks($blocks) : Processors::ignoreStructureBlocksAndAir($blocks));
		}
		return $settings;
	}

	/**
	 * EndCityPiece.handleDataMarker()
	 */
	protected function handleDataMarker(StructurePlaceContext $c, string $metadata, int $x, int $y, int $z, BoundingBox $box) : void{
		if(str_starts_with($metadata, "Chest")){
			if($box->isInside($x, $y - 1, $z)){
				TemplatePlacer::lootChest($c, $x, $y - 1, $z, self::LOOT_TABLE);
			}
			return;
		}
		if(str_starts_with($metadata, "Elytra")){
			//an item frame entity in Java (facing rotation.rotate(SOUTH)), a block with a tile in Bedrock
			$facing = Rotations::rotateDirection(Direction::SOUTH, $this->rotation);
			$frame = VanillaBlocks::ITEM_FRAME()->setFacing($facing);
			if($c->level->setPmState($x, $y, $z, $frame->getStateId())){
				$tile = CompoundTag::create()
					->setString(Tile::TAG_ID, "ItemFrame")
					->setFloat(ItemFrameTile::TAG_ITEM_ROTATION, 0.0)
					->setFloat(ItemFrameTile::TAG_ITEM_DROP_CHANCE, 1.0);
				$elytra = StringToItemParser::getInstance()->parse("elytra");
				if($elytra !== null){
					$tile->setTag(ItemFrameTile::TAG_ITEM, $elytra->nbtSerialize());
				}
				$c->level->setBlockEntity($x, $y, $z, $tile);
			}
		}
	}
}
