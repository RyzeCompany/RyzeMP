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

namespace pocketmine\world\generator\vanilla\structure\jigsaw;

use pocketmine\world\generator\vanilla\feature\block\Direction;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\PoolElementPiece;
use pocketmine\world\generator\vanilla\structure\StructureEnvironment;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use pocketmine\world\generator\vanilla\structure\TerrainSampler;
use function count;
use function intdiv;
use function krsort;
use function max;
use function min;
use const PHP_INT_MIN;

/**
 * Vanilla's JigsawPlacement.Placer: grows a jigsaw structure from its start piece, breadth-first by jigsaw placement
 * priority, attaching pieces of the target pools (and their fallbacks) to free jigsaw blocks while they fit in the
 * remaining space.
 */
final class JigsawAssembler{
	/**
	 * Pieces waiting for their children, by placement priority (vanilla's SequencedPriorityIterator).
	 * @phpstan-var array<int, list<array{PoolElementPiece, FreeSpace, int}>>
	 */
	private array $queue = [];

	/**
	 * @param PoolElementPiece[] $pieces
	 * @phpstan-param list<PoolElementPiece> $pieces
	 * @phpstan-param array<string, string> $aliases pool aliases
	 */
	public function __construct(
		private StructureEnvironment $env,
		private int $maxDepth,
		private bool $useExpansionHack,
		private LegacyRandom $random,
		public array $pieces,
		private array $aliases,
		private bool $applyWaterlogging
	){}

	private function templates() : StructureTemplates{
		return $this->env->templates;
	}

	private function poolKey(string $pool) : string{
		return $this->aliases[$pool] ?? $pool;
	}

	public function run(PoolElementPiece $start, FreeSpace $free) : void{
		$this->tryPlacingChildren($start, $free, 0);
		while(($next = $this->next()) !== null){
			[$piece, $space, $depth] = $next;
			$this->tryPlacingChildren($piece, $space, $depth);
		}
	}

	/**
	 * @phpstan-return array{PoolElementPiece, FreeSpace, int}|null
	 */
	private function next() : ?array{
		foreach($this->queue as $priority => $list){
			if(count($list) > 0){
				$state = $list[0];
				unset($list[0]);
				$list = [...$list];
				if(count($list) === 0){
					unset($this->queue[$priority]);
				}else{
					$this->queue[$priority] = $list;
				}
				return $state;
			}
			unset($this->queue[$priority]);
		}
		return null;
	}

	private function enqueue(PoolElementPiece $piece, FreeSpace $space, int $depth, int $priority) : void{
		$new = !isset($this->queue[$priority]);
		$this->queue[$priority][] = [$piece, $space, $depth];
		if($new){
			krsort($this->queue);
		}
	}

	private function firstFreeHeight(int $x, int $z) : int{
		return $this->env->terrain->getFirstFreeHeight($x, $z, TerrainSampler::WORLD_SURFACE_WG);
	}

	private function tryPlacingChildren(PoolElementPiece $piece, FreeSpace $free, int $depth) : void{
		$registry = $this->env->registry;
		$templates = $this->templates();
		$element = $piece->element;
		$rotation = $piece->rotation;
		$isRigid = $element->projection === PoolElement::PROJECTION_RIGID;
		$innerFree = null;
		$box = $piece->box;
		$minY = $box->minY;

		foreach($element->getShuffledJigsaws($templates, $piece->x, $piece->y, $piece->z, $rotation, $this->random) as $jigsaw){
			$front = $jigsaw->front;
			$targetX = $jigsaw->x + Direction::STEP_X[$front];
			$targetY = $jigsaw->y + Direction::STEP_Y[$front];
			$targetZ = $jigsaw->z + Direction::STEP_Z[$front];
			$localY = $jigsaw->y - $minY;
			$heightAtTarget = PHP_INT_MIN;
			$poolId = $this->poolKey($jigsaw->pool);
			$pool = $registry->getPool($poolId);
			if($pool === null){
				$registry->warn("pool:$poolId", "Empty or non-existent pool: $poolId");
				continue;
			}
			if($pool->size() === 0 && $poolId !== "minecraft:empty"){
				$registry->warn("pool:$poolId", "Empty or non-existent pool: $poolId");
				continue;
			}
			$fallback = $registry->getPool($pool->fallback);
			if($fallback === null || ($fallback->size() === 0 && $pool->fallback !== "minecraft:empty")){
				$registry->warn("pool:" . $pool->fallback, "Empty or non-existent fallback pool: " . $pool->fallback);
				continue;
			}
			$targetInside = $box->isInside($targetX, $targetY, $targetZ);
			if($targetInside){
				$innerFree ??= new FreeSpace($box->copy());
				$space = $innerFree;
			}else{
				$space = $free;
			}

			$candidates = [];
			if($depth !== $this->maxDepth){
				$candidates = $pool->getShuffledTemplates($this->random);
			}
			foreach($fallback->getShuffledTemplates($this->random) as $candidate){
				$candidates[] = $candidate;
			}
			$placementPriority = $jigsaw->placementPriority;

			foreach($candidates as $candidate){
				if($candidate->isEmpty()){
					break;
				}
				foreach(StructureRandom::shuffled([0, 1, 2, 3], $this->random) as $candidateRotation){
					$candidateJigsaws = $candidate->getShuffledJigsaws($templates, 0, 0, 0, $candidateRotation, $this->random);
					$candidateBox0 = $candidate->getBoundingBox($templates, 0, 0, 0, $candidateRotation);
					$expansion = 0;
					if($this->useExpansionHack && $candidateBox0->getYSpan() <= 16){
						foreach($candidateJigsaws as $cj){
							$cf = $cj->front;
							if(!$candidateBox0->isInside($cj->x + Direction::STEP_X[$cf], $cj->y + Direction::STEP_Y[$cf], $cj->z + Direction::STEP_Z[$cf])){
								continue;
							}
							$cjPool = $registry->getPool($this->poolKey($cj->pool));
							$cjFallback = $cjPool !== null ? $registry->getPool($cjPool->fallback) : null;
							$expansion = max($expansion, $cjPool !== null ? $cjPool->getMaxSize($templates) : 0, $cjFallback !== null ? $cjFallback->getMaxSize($templates) : 0);
						}
					}

					foreach($candidateJigsaws as $cj){
						if(!$jigsaw->canAttach($cj)){
							continue;
						}
						$deltaX = $targetX - $cj->x;
						$deltaY = $targetY - $cj->y;
						$deltaZ = $targetZ - $cj->z;
						$candidateBox = $candidate->getBoundingBox($templates, $deltaX, $deltaY, $deltaZ, $candidateRotation);
						$candidateMinY = $candidateBox->minY;
						$candidateRigid = $candidate->projection === PoolElement::PROJECTION_RIGID;
						$cjLocalY = $cj->y;
						$yDelta = $localY - $cjLocalY + Direction::STEP_Y[$front];
						if($isRigid && $candidateRigid){
							$newMinY = $minY + $yDelta;
						}else{
							if($heightAtTarget === PHP_INT_MIN){
								$heightAtTarget = $this->firstFreeHeight($jigsaw->x, $jigsaw->z);
							}
							$newMinY = $heightAtTarget - $cjLocalY;
						}
						$dy = $newMinY - $candidateMinY;
						$placedBox = $candidateBox->moved(0, $dy, 0);
						$placedX = $deltaX;
						$placedY = $deltaY + $dy;
						$placedZ = $deltaZ;
						if($expansion > 0){
							$top = max($expansion + 1, $placedBox->maxY - $placedBox->minY);
							$placedBox->encapsulatePoint($placedBox->minX, $placedBox->minY + $top, $placedBox->minZ);
						}
						if(!$space->fits($placedBox)){
							continue;
						}
						$space->subtract($placedBox);
						$pieceGroundDelta = $piece->getGroundLevelDelta();
						$candidateGroundDelta = $candidateRigid ? $pieceGroundDelta - $yDelta : $candidate->getGroundLevelDelta();
						$newPiece = new PoolElementPiece($candidate, $placedX, $placedY, $placedZ, $candidateGroundDelta, $candidateRotation, $placedBox, $candidate->getLiquidOverride() ?? $this->applyWaterlogging);
						if($isRigid){
							$junctionY = $minY + $localY;
						}elseif($candidateRigid){
							$junctionY = $newMinY + $cjLocalY;
						}else{
							if($heightAtTarget === PHP_INT_MIN){
								$heightAtTarget = $this->firstFreeHeight($jigsaw->x, $jigsaw->z);
							}
							$junctionY = $heightAtTarget + intdiv($yDelta, 2);
						}
						$piece->junctions[] = [$targetX, $junctionY - $localY + $pieceGroundDelta, $targetZ, $yDelta, $candidate->projection];
						$newPiece->junctions[] = [$jigsaw->x, $junctionY - $cjLocalY + $candidateGroundDelta, $jigsaw->z, -$yDelta, $element->projection];
						$this->pieces[] = $newPiece;
						if($depth + 1 <= $this->maxDepth){
							$this->enqueue($newPiece, $space, $depth + 1, $placementPriority);
						}
						continue 4;
					}
				}
			}
		}
	}

	/**
	 * JigsawPlacement.getRandomNamedJigsaw(): position of a random jigsaw block with the given name.
	 * @phpstan-return array{int, int, int}|null
	 */
	public static function randomNamedJigsaw(StructureTemplates $templates, PoolElement $element, string $name, int $x, int $y, int $z, int $rotation, LegacyRandom $random) : ?array{
		foreach($element->getShuffledJigsaws($templates, $x, $y, $z, $rotation, $random) as $jigsaw){
			if($jigsaw->name === $name){
				return [$jigsaw->x, $jigsaw->y, $jigsaw->z];
			}
		}
		return null;
	}

	/**
	 * The free space of a jigsaw structure: the box of max_distance_from_center around the start (clamped to the
	 * world height minus the dimension padding) minus the start piece.
	 */
	public static function initialSpace(StructureEnvironment $env, int $centerX, int $centerY, int $centerZ, int $maxDistance, int $padBottom, int $padTop, BoundingBox $startBox) : FreeSpace{
		$outer = new BoundingBox(
			$centerX - $maxDistance,
			max($centerY - $maxDistance, $env->minY + $padBottom),
			$centerZ - $maxDistance,
			$centerX + $maxDistance,
			min($centerY + $maxDistance, $env->getMaxY() - $padTop),
			$centerZ + $maxDistance
		);
		$space = new FreeSpace($outer);
		$space->subtract($startBox);
		return $space;
	}
}
