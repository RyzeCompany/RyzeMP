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

namespace pocketmine\world\generator\vanilla\structure\piece\mineshaft;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\OrientedPiece;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use function intdiv;
use function max;
use function min;

/**
 * Vanilla's MineshaftPieces.MineShaftPiece: the common parts of the mineshaft pieces (wood type, liquid and biome
 * checks, planks bridging over air).
 */
abstract class MineShaftPiece extends OrientedPiece{
	/** BiomeTags.MINESHAFT_BLOCKING */
	private const BLOCKING_BIOMES = ["minecraft:deep_dark" => true];

	/** The blocks extending FallingBlock (ColoredFallingBlock, ConcretePowderBlock, AnvilBlock, DragonEggBlock). */
	private const FALLING_BLOCKS = [
		"minecraft:sand" => true, "minecraft:red_sand" => true, "minecraft:gravel" => true,
		"minecraft:anvil" => true, "minecraft:chipped_anvil" => true, "minecraft:damaged_anvil" => true,
		"minecraft:dragon_egg" => true,
		"minecraft:white_concrete_powder" => true, "minecraft:orange_concrete_powder" => true,
		"minecraft:magenta_concrete_powder" => true, "minecraft:light_blue_concrete_powder" => true,
		"minecraft:yellow_concrete_powder" => true, "minecraft:lime_concrete_powder" => true,
		"minecraft:pink_concrete_powder" => true, "minecraft:gray_concrete_powder" => true,
		"minecraft:light_gray_concrete_powder" => true, "minecraft:cyan_concrete_powder" => true,
		"minecraft:purple_concrete_powder" => true, "minecraft:blue_concrete_powder" => true,
		"minecraft:brown_concrete_powder" => true, "minecraft:green_concrete_powder" => true,
		"minecraft:red_concrete_powder" => true, "minecraft:black_concrete_powder" => true,
	];

	public function __construct(
		BoundingBox $box,
		int $genDepth,
		public readonly bool $mesa
	){
		parent::__construct($box, $genDepth);
	}

	/**
	 * StructurePiece.addChildren()
	 */
	abstract public function addChildren(MineShaftRoom $start, StructurePiecesBuilder $builder, LegacyRandom $random) : void;

	/** MineshaftStructure.Type.getWoodState() */
	protected function woodName() : string{
		return $this->mesa ? "minecraft:dark_oak_log" : "minecraft:oak_log";
	}

	/** MineshaftStructure.Type.getPlanksState() */
	protected function planksName() : string{
		return $this->mesa ? "minecraft:dark_oak_planks" : "minecraft:oak_planks";
	}

	/** MineshaftStructure.Type.getFenceState() */
	protected function fenceName() : string{
		return $this->mesa ? "minecraft:dark_oak_fence" : "minecraft:oak_fence";
	}

	/**
	 * MineShaftPiece.canBeReplaced(): never replaces the wood, planks, fences and chains of the mineshaft.
	 */
	protected function canBeReplaced(StructurePlaceContext $c, int $x, int $y, int $z, BoundingBox $box) : bool{
		$blocks = $c->blocks;
		$name = $blocks->blockName($this->getBlock($c, $x, $y, $z, $box));
		return $name !== $this->planksName() && $name !== $this->woodName() && $name !== $this->fenceName() && $name !== "minecraft:iron_chain";
	}

	/**
	 * MineShaftPiece.isSupportingBox(): whether there is no air above the given row.
	 */
	protected function isSupportingBox(StructurePlaceContext $c, BoundingBox $box, int $x0, int $x1, int $y1, int $z0) : bool{
		for($x = $x0; $x <= $x1; ++$x){
			if($c->blocks->isAir($this->getBlock($c, $x, $y1 + 1, $z0, $box))){
				return false;
			}
		}
		return true;
	}

	/**
	 * MineShaftPiece.isInInvalidLocation(): in a blocking biome, or liquid on the faces of the piece box (grown by one,
	 * clipped to the chunk box).
	 */
	protected function isInInvalidLocation(StructurePlaceContext $c, BoundingBox $box) : bool{
		$x0 = max($this->box->minX - 1, $box->minX);
		$y0 = max($this->box->minY - 1, $box->minY);
		$z0 = max($this->box->minZ - 1, $box->minZ);
		$x1 = min($this->box->maxX + 1, $box->maxX);
		$y1 = min($this->box->maxY + 1, $box->maxY);
		$z1 = min($this->box->maxZ + 1, $box->maxZ);
		$level = $c->level;
		$biome = $c->feature->registry->biomeNames[$level->getBiome(intdiv($x0 + $x1, 2), intdiv($y0 + $y1, 2), intdiv($z0 + $z1, 2))] ?? "";
		if(isset(self::BLOCKING_BIOMES[$biome])){
			return true;
		}
		$blocks = $c->blocks;
		$water = $blocks->waterBlock;
		$lava = $blocks->lavaBlock;
		$liquid = function(int $x, int $y, int $z) use ($level, $blocks, $water, $lava) : bool{
			$block = $blocks->blockOf[$level->getState($x, $y, $z)];
			return $block === $water || $block === $lava;
		};
		for($x = $x0; $x <= $x1; ++$x){
			for($z = $z0; $z <= $z1; ++$z){
				if($liquid($x, $y0, $z) || $liquid($x, $y1, $z)){
					return true;
				}
			}
		}
		for($x = $x0; $x <= $x1; ++$x){
			for($y = $y0; $y <= $y1; ++$y){
				if($liquid($x, $y, $z0) || $liquid($x, $y, $z1)){
					return true;
				}
			}
		}
		for($z = $z0; $z <= $z1; ++$z){
			for($y = $y0; $y <= $y1; ++$y){
				if($liquid($x0, $y, $z) || $liquid($x1, $y, $z)){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * MineShaftPiece.setPlanksBlock(): planks under the floor where it is not sturdy (bridging caves), only below the
	 * ocean floor heightmap.
	 */
	protected function setPlanksBlock(StructurePlaceContext $c, BoundingBox $box, int $planks, int $x, int $y, int $z) : void{
		if(!$this->isInterior($c, $x, $y, $z, $box)){
			return;
		}
		[$wx, $wy, $wz] = $this->getWorldPos($x, $y, $z);
		if(!$c->blocks->isFaceSturdy($c->level->getState($wx, $wy, $wz), Direction::UP)){
			$c->level->setState($wx, $wy, $wz, $planks);
		}
	}

	/**
	 * MineShaftCorridor.canHangChainBelow(): Block.canSupportCenter(DOWN) (approximated: sturdy bottom face, or a
	 * fence or wall post) and not a FallingBlock.
	 */
	protected static function canHangChainBelow(StructurePlaceContext $c, int $state) : bool{
		$blocks = $c->blocks;
		$supports = $blocks->isFaceSturdy($state, Direction::DOWN) || $blocks->isTagged($state, "minecraft:fences") || $blocks->isTagged($state, "minecraft:walls");
		return $supports && !isset(self::FALLING_BLOCKS[$blocks->blockName($state)]);
	}
}
