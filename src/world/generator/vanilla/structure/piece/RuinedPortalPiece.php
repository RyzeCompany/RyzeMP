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

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\feature\block\JavaBlocks;
use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\processor\Processors;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;
use pocketmine\world\generator\vanilla\structure\template\PlaceSettings;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use pocketmine\world\generator\vanilla\structure\type\RuinedPortalStructure;
use function abs;
use function intdiv;
use function max;
use function min;

/**
 * vanilla's RuinedPortalPiece: the portal template with its processors, then netherrack spread on the ground around
 * it, netherrack drips under it, and vines / leaves. Like vanilla, the whole portal is placed by the chunk holding the
 * centre of its box.
 */
final class RuinedPortalPiece extends TemplatePiece{
	private const SPREAD = [1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.9, 0.9, 0.8, 0.7, 0.6, 0.4, 0.2];

	public function __construct(
		StructureTemplates $templates,
		string $location,
		int $x,
		int $y,
		int $z,
		private int $rotation,
		private int $mirror,
		private int $pivotX,
		private int $pivotZ,
		private string $placement,
		private bool $airPocket,
		private float $mossiness,
		private bool $overgrown,
		private bool $vines,
		private bool $cold,
		private bool $blackstone
	){
		parent::__construct($templates, $location, $x, $y, $z);
	}

	protected function makeSettings(?JavaBlocks $blocks) : PlaceSettings{
		$settings = (new PlaceSettings())->setRotation($this->rotation)->setMirror($this->mirror)->setPivot($this->pivotX, $this->pivotZ);
		if($blocks === null){
			return $settings;
		}
		$settings->addProcessor($this->airPocket ? Processors::ignoreStructureBlocks($blocks) : Processors::ignoreStructureBlocksAndAir($blocks));
		$rules = [["minecraft:gold_block", 0.3, "minecraft:air"]];
		if($this->placement === RuinedPortalStructure::PLACEMENT_ON_OCEAN_FLOOR){
			$rules[] = ["minecraft:lava", 1.0, "minecraft:magma_block"];
		}elseif($this->cold){
			$rules[] = ["minecraft:lava", 1.0, "minecraft:netherrack"];
		}else{
			$rules[] = ["minecraft:lava", 0.2, "minecraft:magma_block"];
		}
		if(!$this->cold){
			$rules[] = ["minecraft:netherrack", 0.07, "minecraft:magma_block"];
		}
		$settings->addProcessor(Processors::simpleRules($blocks, $rules));
		$settings->addProcessor(Processors::blockAge($blocks, $this->mossiness));
		$settings->addProcessor(Processors::protectedBlocks($blocks->tag("minecraft:features_cannot_replace"), $blocks));
		$settings->addProcessor(Processors::lavaSubmerged($blocks));
		if($this->blackstone){
			$settings->addProcessor(Processors::blackstoneReplace($blocks));
		}
		return $settings;
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $chunkBox, int $chunkX, int $chunkZ, array $pivot) : void{
		[$cx, $cy, $cz] = $this->box->getCenter();
		if(!$chunkBox->isInside($cx, $cy, $cz)){
			return;
		}
		$box = $chunkBox->copy()->encapsulate($this->box);
		$this->placeTemplate($c, $box, $pivot);
		$random = $c->random;
		$this->spreadNetherrack($c, $random);
		$this->addNetherrackDripColumnsBelowPortal($c, $random);
		if($this->vines || $this->overgrown){
			$b = $this->box;
			for($x = $b->minX; $x <= $b->maxX; ++$x){
				for($y = $b->minY; $y <= $b->maxY; ++$y){
					for($z = $b->minZ; $z <= $b->maxZ; ++$z){
						if($this->vines){
							$this->maybeAddVines($c, $random, $x, $y, $z);
						}
						if($this->overgrown){
							$this->maybeAddLeavesAbove($c, $random, $x, $y, $z);
						}
					}
				}
			}
		}
	}

	private function surfaceY(StructurePlaceContext $c, int $x, int $z) : int{
		$heightmap = $this->placement === RuinedPortalStructure::PLACEMENT_ON_OCEAN_FLOOR ? TerrainSampler::OCEAN_FLOOR_WG : TerrainSampler::WORLD_SURFACE_WG;
		return $c->env->terrain->getFirstFreeHeight($x, $z, $heightmap) - 1;
	}

	private function canBeReplacedByNetherrack(StructurePlaceContext $c, int $x, int $y, int $z) : bool{
		$blocks = $c->blocks;
		$state = $c->level->getState($x, $y, $z);
		$name = $blocks->blockName($state);
		return !$blocks->isAir($state) && $name !== "minecraft:obsidian" && !$blocks->isTagged($state, "minecraft:features_cannot_replace") &&
			($this->placement === RuinedPortalStructure::PLACEMENT_IN_NETHER || $blocks->blockOf[$state] !== $blocks->lavaBlock);
	}

	private function placeNetherrackOrMagma(StructurePlaceContext $c, FeatureRandom $random, int $x, int $y, int $z) : void{
		$c->level->setState($x, $y, $z, $c->blocks->defaultOf(!$this->cold && $random->nextFloat() < 0.07 ? "minecraft:magma_block" : "minecraft:netherrack"));
	}

	private function addNetherrackDripColumn(StructurePlaceContext $c, FeatureRandom $random, int $x, int $y, int $z) : void{
		$this->placeNetherrackOrMagma($c, $random, $x, $y, $z);
		for($i = 8; $i > 0 && $random->nextFloat() < 0.5; --$i){
			--$y;
			$this->placeNetherrackOrMagma($c, $random, $x, $y, $z);
		}
	}

	private function spreadNetherrack(StructurePlaceContext $c, FeatureRandom $random) : void{
		$surface = $this->placement === RuinedPortalStructure::PLACEMENT_ON_LAND_SURFACE || $this->placement === RuinedPortalStructure::PLACEMENT_ON_OCEAN_FLOOR;
		[$centerX, , $centerZ] = $this->box->getCenter();
		$count = 20;
		$size = intdiv($this->box->getXSpan() + $this->box->getZSpan(), 2);
		$offset = $random->nextBoundedInt(max(1, 8 - intdiv($size, 2)));
		for($x = $centerX - $count; $x <= $centerX + $count; ++$x){
			for($z = $centerZ - $count; $z <= $centerZ + $count; ++$z){
				$distance = max(0, abs($x - $centerX) + abs($z - $centerZ) + $offset);
				if($distance >= $count || $random->nextDouble() >= self::SPREAD[$distance]){
					continue;
				}
				$y = $this->surfaceY($c, $x, $z);
				$y = $surface ? $y : min($this->box->minY, $y);
				if(abs($y - $this->box->minY) <= 3 && $this->canBeReplacedByNetherrack($c, $x, $y, $z)){
					$this->placeNetherrackOrMagma($c, $random, $x, $y, $z);
					if($this->overgrown){
						$this->maybeAddLeavesAbove($c, $random, $x, $y, $z);
					}
					$this->addNetherrackDripColumn($c, $random, $x, $y - 1, $z);
				}
			}
		}
	}

	private function addNetherrackDripColumnsBelowPortal(StructurePlaceContext $c, FeatureRandom $random) : void{
		$netherrack = $c->blocks->defaultOf("minecraft:netherrack");
		for($x = $this->box->minX + 1; $x < $this->box->maxX; ++$x){
			for($z = $this->box->minZ + 1; $z < $this->box->maxZ; ++$z){
				if($c->level->getState($x, $this->box->minY, $z) === $netherrack){
					$this->addNetherrackDripColumn($c, $random, $x, $this->box->minY - 1, $z);
				}
			}
		}
	}

	private function maybeAddVines(StructurePlaceContext $c, FeatureRandom $random, int $x, int $y, int $z) : void{
		$blocks = $c->blocks;
		$state = $c->level->getState($x, $y, $z);
		if($blocks->isAir($state) || $blocks->blockName($state) === "minecraft:vine"){
			return;
		}
		$direction = OrientedPiece::randomHorizontalDirection($random);
		$nx = $x + Direction::STEP_X[$direction];
		$nz = $z + Direction::STEP_Z[$direction];
		if($blocks->isAir($c->level->getState($nx, $y, $nz)) && $blocks->isFaceSturdy($state, $direction)){
			$c->level->setState($nx, $y, $nz, $blocks->withBool($blocks->defaultOf("minecraft:vine"), Direction::NAMES[Direction::OPPOSITE[$direction]], true));
		}
	}

	private function maybeAddLeavesAbove(StructurePlaceContext $c, FeatureRandom $random, int $x, int $y, int $z) : void{
		$blocks = $c->blocks;
		if($random->nextFloat() < 0.5 && $blocks->blockName($c->level->getState($x, $y, $z)) === "minecraft:netherrack" && $blocks->isAir($c->level->getState($x, $y + 1, $z))){
			$c->level->setState($x, $y + 1, $z, $blocks->withBool($blocks->defaultOf("minecraft:jungle_leaves"), "persistent", true));
		}
	}
}
