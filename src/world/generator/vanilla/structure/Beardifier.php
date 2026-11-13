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

namespace pocketmine\world\generator\vanilla\structure;

use pocketmine\utils\Utils;
use pocketmine\world\generator\vanilla\structure\piece\PoolElementPiece;
use function count;
use function intdiv;
use function max;
use function min;
use function pack;
use function pow;
use function sqrt;
use function unpack;

/**
 * vanilla's Beardifier: the density added to the terrain's final density around the pieces of structures with a
 * terrain adaptation (beard_thin, beard_box, bury, encapsulate) and around jigsaw junctions, so that the terrain fills
 * the space under the pieces and clears the space inside them, with smooth slopes. Applied by the terrain fillers
 * (PHP and the native core, which must compute exactly the same values: everything is float32 arithmetic like Java
 * 26.3, rounded with {@link f()}).
 *
 * The rigids and junctions are in vanilla's order for one start (pieces in order); starts are in
 * {@link StructureManager::getStartsAffecting()} order (Java iterates a hash map, so the order of starts, which only
 * changes the last bit of sums, cannot be reproduced anyway).
 */
final class Beardifier{
	public const NONE = 0;
	public const BURY = 1;
	public const BEARD_THIN = 2;
	public const BEARD_BOX = 3;
	public const ENCAPSULATE = 4;

	/** Rigid record size: minX, minY, minZ, maxX, maxY, maxZ, adaptation, ground level delta. */
	public const RIGID_SIZE = 8;
	/** Junction record size: source x, source ground y, source z. */
	public const JUNCTION_SIZE = 3;

	private const KERNEL_RADIUS = 12;
	private const KERNEL_SIZE = 24;

	/** @phpstan-var list<float>|null */
	private static ?array $kernel = null;
	/** @phpstan-var array<int, float> (int) (distanceSqr * 4) => (float) fastInvSqrt(distanceSqr / 2) */
	private static array $invSqrt = [];

	/**
	 * @param int[] $rigids    flat list of RIGID_SIZE ints per rigid piece
	 * @param int[] $junctions flat list of JUNCTION_SIZE ints per junction
	 * @param int[] $affected  minX, minY, minZ, maxX, maxY, maxZ of the area where the density can change
	 * @phpstan-param list<int> $rigids
	 * @phpstan-param list<int> $junctions
	 * @phpstan-param array{int, int, int, int, int, int} $affected
	 */
	public function __construct(
		public readonly array $rigids,
		public readonly array $junctions,
		public readonly array $affected
	){}

	public static function adaptation(string $name) : int{
		return match($name){
			StructureDefinition::ADAPTATION_BURY => self::BURY,
			StructureDefinition::ADAPTATION_BEARD_THIN => self::BEARD_THIN,
			StructureDefinition::ADAPTATION_BEARD_BOX => self::BEARD_BOX,
			StructureDefinition::ADAPTATION_ENCAPSULATE => self::ENCAPSULATE,
			default => self::NONE
		};
	}

	/**
	 * Beardifier.forStructuresInChunk(): the rigid pieces (all pieces of code-built structures, rigid jigsaw pieces)
	 * within 12 blocks of the chunk and the junctions of jigsaw pieces near it, or null if there are none.
	 *
	 * @param StructureStart[] $starts the starts referenced by the chunk
	 * @phpstan-param list<StructureStart> $starts
	 */
	public static function forStructuresInChunk(array $starts, int $chunkX, int $chunkZ) : ?self{
		$minX = $chunkX << 4;
		$minZ = $chunkZ << 4;
		$rigids = [];
		$junctions = [];
		$box = null;
		foreach($starts as $start){
			$adaptation = self::adaptation($start->structure->terrainAdaptation);
			if($adaptation === self::NONE){
				continue;
			}
			foreach($start->pieces as $piece){
				$b = $piece->box;
				//StructurePiece.isCloseToChunk(chunkPos, 12)
				if($b->maxX < $minX - 12 || $b->minX > $minX + 15 + 12 || $b->maxZ < $minZ - 12 || $b->minZ > $minZ + 15 + 12){
					continue;
				}
				if($piece instanceof PoolElementPiece){
					if($piece->isRigid()){
						$rigids[] = $b->minX;
						$rigids[] = $b->minY;
						$rigids[] = $b->minZ;
						$rigids[] = $b->maxX;
						$rigids[] = $b->maxY;
						$rigids[] = $b->maxZ;
						$rigids[] = $adaptation;
						$rigids[] = $piece->getGroundLevelDelta();
						$box = $box === null ? $b->copy() : $box->encapsulate($b);
					}
					foreach($piece->junctions as [$jx, $jy, $jz]){
						if($jx > $minX - 12 && $jz > $minZ - 12 && $jx < $minX + 15 + 12 && $jz < $minZ + 15 + 12){
							$junctions[] = $jx;
							$junctions[] = $jy;
							$junctions[] = $jz;
							$point = new BoundingBox($jx, $jy, $jz, $jx, $jy, $jz);
							$box = $box === null ? $point : $box->encapsulate($point);
						}
					}
				}else{
					$rigids[] = $b->minX;
					$rigids[] = $b->minY;
					$rigids[] = $b->minZ;
					$rigids[] = $b->maxX;
					$rigids[] = $b->maxY;
					$rigids[] = $b->maxZ;
					$rigids[] = $adaptation;
					$rigids[] = 0;
					$box = $box === null ? $b->copy() : $box->encapsulate($b);
				}
			}
		}
		if($box === null){
			return null;
		}
		$a = $box->inflatedBy(24);
		return new self($rigids, $junctions, [$a->minX, $a->minY, $a->minZ, $a->maxX, $a->maxY, $a->maxZ]);
	}

	/** Rounds to the nearest float32 (Veltkamp splitting, same as the native core's fround()). */
	public static function f(float $x) : float{
		$c = $x * 536870913.0;
		return $c - ($c - $x);
	}

	/**
	 * BEARD_KERNEL: (float) Math.pow(e, -(dx² + (dy + 0.5)² + dz²) / 16), index (z * 24 + x) * 24 + y.
	 * @phpstan-return list<float>
	 */
	public static function kernel() : array{
		if(self::$kernel === null){
			$kernel = [];
			for($zi = 0; $zi < self::KERNEL_SIZE; ++$zi){
				for($xi = 0; $xi < self::KERNEL_SIZE; ++$xi){
					for($yi = 0; $yi < self::KERNEL_SIZE; ++$yi){
						$dx = (float) ($xi - self::KERNEL_RADIUS);
						$dy = ($yi - self::KERNEL_RADIUS) + 0.5;
						$dz = (float) ($zi - self::KERNEL_RADIUS);
						$distanceSqr = $dx * $dx + $dy * $dy + $dz * $dz;
						$kernel[] = self::f(pow(2.718281828459045, -$distanceSqr / 16.0));
					}
				}
			}
			self::$kernel = $kernel;
		}
		return self::$kernel;
	}

	/**
	 * Mth.fastInvSqrt(double)
	 */
	public static function fastInvSqrt(float $x) : float{
		$xhalf = 0.5 * $x;
		$i = Utils::assumeNotFalse(unpack("q", pack("e", $x)))[1];
		$i = 6910469410427058090 - ($i >> 1);
		$y = Utils::assumeNotFalse(unpack("e", pack("q", $i)))[1];
		return $y * (1.5 - $xhalf * $y * $y);
	}

	/**
	 * Beardifier.getBeardContribution(dx, dy, dz, yToGround), not yet multiplied by the piece's weight.
	 * @phpstan-param list<float> $kernel
	 */
	private static function beard(array $kernel, int $dx, int $dy, int $dz, int $yToGround) : float{
		$xi = $dx + self::KERNEL_RADIUS;
		$yi = $dy + self::KERNEL_RADIUS;
		$zi = $dz + self::KERNEL_RADIUS;
		if($xi < 0 || $xi >= self::KERNEL_SIZE || $yi < 0 || $yi >= self::KERNEL_SIZE || $zi < 0 || $zi >= self::KERNEL_SIZE){
			return 0.0;
		}
		$dyWithOffset = $yToGround + 0.5;
		//Mth.lengthSquared(float, float, float); the squares and sums of these small values are exact in float32
		$distanceSqr = self::f(self::f(self::f($dx * $dx) + self::f($dyWithOffset * $dyWithOffset)) + self::f($dz * $dz));
		$key = (int) ($distanceSqr * 4);
		$inv = self::$invSqrt[$key] ??= self::f(self::fastInvSqrt(self::f($distanceSqr / 2.0)));
		$value = self::f(self::f(-$dyWithOffset * $inv) / 2.0);
		return self::f($value * $kernel[($zi * self::KERNEL_SIZE + $xi) * self::KERNEL_SIZE + $yi]);
	}

	/**
	 * Beardifier.getBuryContribution(dx, dy, dz) with float arguments.
	 */
	private static function bury(float $dx, float $dy, float $dz) : float{
		$distanceSq = self::f(self::f(self::f($dx * $dx) + self::f($dy * $dy)) + self::f($dz * $dz));
		if($distanceSq >= 36.0){
			return 0.0;
		}
		return self::f(1.0 - self::f(self::f(sqrt($distanceSq)) / 6.0));
	}

	/**
	 * The density added at each y of a column (Beardifier.sampleValue(), float32 values), for y from $fromY to $toY
	 * (inclusive), or [] if the column is outside the affected area. The values start at $fromY clamped to the
	 * affected area: returns [first y, list of values].
	 *
	 * @phpstan-return array{int, list<float>}|array{}
	 */
	public function column(int $x, int $z, int $fromY, int $toY) : array{
		[$aMinX, $aMinY, $aMinZ, $aMaxX, $aMaxY, $aMaxZ] = $this->affected;
		if($x < $aMinX || $x > $aMaxX || $z < $aMinZ || $z > $aMaxZ){
			return [];
		}
		$y0 = max($fromY, $aMinY);
		$y1 = min($toY, $aMaxY);
		if($y0 > $y1){
			return [];
		}
		$kernel = self::$kernel ?? self::kernel();
		//rigids and junctions that can contribute to this column (a contribution of exactly 0 changes nothing)
		$active = [];
		$rigids = $this->rigids;
		for($i = 0, $n = count($rigids); $i < $n; $i += self::RIGID_SIZE){
			$dx = max(0, $rigids[$i] - $x, $x - $rigids[$i + 3]);
			$dz = max(0, $rigids[$i + 2] - $z, $z - $rigids[$i + 5]);
			$adaptation = $rigids[$i + 6];
			$limit = $adaptation === self::BURY ? 6 : 12;
			if($dx < $limit && $dz < $limit && $adaptation !== self::NONE){
				$active[] = [$dx, $dz, $adaptation, $rigids[$i + 1], $rigids[$i + 4], $rigids[$i + 1] + $rigids[$i + 7]];
			}
		}
		$activeJunctions = [];
		$junctions = $this->junctions;
		for($i = 0, $n = count($junctions); $i < $n; $i += self::JUNCTION_SIZE){
			$dx = $x - $junctions[$i];
			$dz = $z - $junctions[$i + 2];
			if($dx >= -self::KERNEL_RADIUS && $dx < self::KERNEL_RADIUS && $dz >= -self::KERNEL_RADIUS && $dz < self::KERNEL_RADIUS){
				$activeJunctions[] = [$dx, $dz, $junctions[$i + 1]];
			}
		}
		$values = [];
		for($y = $y0; $y <= $y1; ++$y){
			$noise = 0.0;
			foreach($active as [$dx, $dz, $adaptation, $minY, $maxY, $groundY]){
				$dyToGround = $y - $groundY;
				switch($adaptation){
					case self::BURY:
						$term = self::bury($dx, $dyToGround / 2.0, $dz);
						break;
					case self::BEARD_THIN:
						$term = self::f(self::beard($kernel, $dx, $dyToGround, $dz, $dyToGround) * 0.800000011920929);
						break;
					case self::BEARD_BOX:
						$term = self::f(self::beard($kernel, $dx, max(0, $groundY - $y, $y - $maxY), $dz, $dyToGround) * 0.800000011920929);
						break;
					default: //ENCAPSULATE
						$term = self::f(self::bury($dx / 2.0, max(0, $minY - $y, $y - $maxY) / 2.0, $dz / 2.0) * 0.800000011920929);
						break;
				}
				if($term !== 0.0){
					$noise = self::f($noise + $term);
				}
			}
			foreach($activeJunctions as [$dx, $dz, $groundY]){
				$dy = $y - $groundY;
				$term = self::f(self::beard($kernel, $dx, $dy, $dz, $dy) * 0.4000000059604645);
				if($term !== 0.0){
					$noise = self::f($noise + $term);
				}
			}
			$values[] = $noise;
		}
		return [$y0, $values];
	}

	/**
	 * Wire form for the native core: u16 rigid count, rigids (8 x i32), u16 junction count, junctions (3 x i32),
	 * affected area (6 x i32), all little-endian.
	 */
	public function encode() : string{
		return pack("v", intdiv(count($this->rigids), self::RIGID_SIZE)) . pack("V*", ...$this->rigids) .
			pack("v", intdiv(count($this->junctions), self::JUNCTION_SIZE)) . pack("V*", ...$this->junctions) .
			pack("V*", ...$this->affected);
	}
}
