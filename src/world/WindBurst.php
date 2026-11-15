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

namespace pocketmine\world;

use pocketmine\block\Bell;
use pocketmine\block\Block;
use pocketmine\block\Button;
use pocketmine\block\CakeWithCandle;
use pocketmine\block\Candle;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\IronDoor;
use pocketmine\block\IronTrapdoor;
use pocketmine\block\Lever;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\Trapdoor;
use pocketmine\block\utils\LiquidLayer;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\entity\object\ExperienceOrb;
use pocketmine\entity\object\FallingBlock;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\projectile\WindCharge;
use pocketmine\entity\vehicle\AbstractMinecart;
use pocketmine\entity\vehicle\Boat;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\SubChunk;
use pocketmine\world\particle\Particle;
use pocketmine\world\sound\FireExtinguishSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\sound\WindChargeBurstSound;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use pocketmine\world\vibration\GameEvent;
use function count;
use function floor;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;

/**
 * A burst of wind (wind charge, Wind Burst enchantment, Wind Charged effect) that pushes entities away without
 * damaging them or breaking blocks.
 *
 * Uses the explosion knockback rule of Java's ServerExplosion: an entity within twice the radius (measured to its
 * feet) is pushed away from the center, towards its eyes, with strength
 * (1 - distance / (radius * 2)) * exposure * multiplier, where the exposure is the fraction of the entity's bounding
 * box that the center can see (walls shield). An entity standing at the center is launched straight up. Items, XP
 * orbs, falling blocks, primed TNT, vehicles and projectiles are pushed as well as living entities.
 *
 * Bursts that toggle blocks (Bedrock explode "toggles_blocks", Java ExplosionInteraction.TRIGGER_BLOCK) also
 * operate the blocks the burst reaches: wooden and copper doors, trapdoors and fence gates toggle unless powered,
 * buttons are pressed, levers flip, bells ring and lit candles go out. The blocks reached are found with the
 * explosion ray sampling (Java ServerExplosion.calculateExplodedPositions()), only when a block that reacts is near,
 * and only for the rays that pass through one of them.
 */
final class WindBurst{
	private const REACTION_NONE = 0;
	private const REACTION_DOOR = 1;
	private const REACTION_TRAPDOOR = 2;
	private const REACTION_FENCE_GATE = 3;
	private const REACTION_BUTTON = 4;
	private const REACTION_LEVER = 5;
	private const REACTION_BELL = 6;
	private const REACTION_CANDLE = 7;

	/** Java ServerExplosion: rays start on the faces of a 16x16x16 grid. */
	private const RAY_GRID = 16;
	private const RAY_STEP = 0.3;
	/** Power lost per step (Java: 0.225, i.e. 0.75 * step). */
	private const RAY_DECAY = 0.225;
	/** Most a ray's power can exceed the radius (Java: radius * (0.7 + random 0.6)). */
	private const RAY_MAX_POWER_FACTOR = 1.3;
	/** Java's resistance of a water block (PocketMine blast resistances are 5x Java's). */
	private const WATER_RESISTANCE = 100.0;
	/** A ray can only enter a block if it passes this close to its center (half its diagonal, plus a margin). */
	private const RAY_PASS_DISTANCE = 0.9;
	/** A collision box overlapping the area of the rays by less than this only touches it (as AxisAlignedBB::intersectsWith()). */
	private const TOUCH_EPSILON = 0.00001;

	/**
	 * @var int[] state ID => REACTION_* constant
	 * @phpstan-var array<int, int>
	 */
	private static array $reactions = [];

	/**
	 * @var float[][]|null unit directions of the explosion rays
	 * @phpstan-var list<array{float, float, float}>|null
	 */
	private static ?array $rayDirections = null;

	/**
	 * @param float         $radius              burst radius (1.2 for player wind charges, 3 for breeze wind charges, 3.5
	 *                                           for Wind Burst)
	 * @param float         $knockbackMultiplier strength (1.22 for player wind charges, 0.6 for breeze wind charges,
	 *                                           1.2/1.75/2.2 for Wind Burst I/II/III)
	 * @param bool          $negatesFallDamage   whether entities only take fall damage for the distance fallen below the
	 *                                           burst (player wind charges), or fall normally (breeze charges, Wind Burst)
	 * @param Sound|null    $sound               sound of the burst (default: the player wind charge burst)
	 * @param Particle|null $particle            particle of the burst, if any
	 * @param bool          $togglesBlocks       whether the burst operates doors, levers, bells... (wind charges)
	 *
	 * @return Entity[] the entities that were pushed
	 */
	public static function apply(World $world, Vector3 $center, float $radius, float $knockbackMultiplier, bool $negatesFallDamage, ?Entity $exclude = null, ?Sound $sound = null, ?Particle $particle = null, bool $togglesBlocks = false) : array{
		$reach = $radius * 2;
		$affected = [];
		$boxes = [];
		foreach($world->getNearbyEntities(new AxisAlignedBB(
			$center->x - $reach, $center->y - $reach, $center->z - $reach,
			$center->x + $reach, $center->y + $reach, $center->z + $reach
		), $exclude) as $entity){
			if(!self::canBePushed($entity)){
				continue;
			}
			$impulse = self::calculateImpulse($center, $entity->getPosition(), $entity->getEyeHeight(), $radius, $knockbackMultiplier);
			if($impulse === null){
				continue;
			}
			$exposure = self::computeExposure($world, $center, $entity, $boxes);
			if($exposure <= 0){
				continue;
			}
			if($exposure < 1){
				$impulse = $impulse->multiply($exposure);
			}
			if($entity->setMotion($entity instanceof Player ? $impulse : $entity->getMotion()->addVector($impulse)) && $entity instanceof Mob){
				$entity->carryPush();
			}
			if($negatesFallDamage && $entity instanceof Living){
				$entity->resetFallDistance();
				$entity->setFallDamageCutoffY($center->y);
			}
			$affected[] = $entity;
		}

		if($togglesBlocks){
			foreach(self::findTriggeredBlocks($world, $center, $radius) as $block){
				self::triggerBlock($world, $block);
			}
		}

		if($particle !== null){
			$world->addParticle($center, $particle);
		}
		$world->addSound($center, $sound ?? new WindChargeBurstSound());
		$world->addGameEvent($center, GameEvent::EXPLODE);

		return $affected;
	}

	/**
	 * Returns whether a burst moves the entity: living entities (except spectators and flying players), items, XP
	 * orbs, falling blocks, primed TNT, vehicles and projectiles other than wind charges.
	 */
	private static function canBePushed(Entity $entity) : bool{
		if($entity->isClosed() || $entity->isFlaggedForDespawn()){
			return false;
		}
		if($entity instanceof Living){
			return $entity->isAlive() && !($entity instanceof Player && ($entity->isSpectator() || $entity->isFlying()));
		}
		if($entity instanceof Projectile){
			//Java AbstractWindCharge.push() does nothing
			return !$entity instanceof WindCharge;
		}
		return $entity instanceof ItemEntity ||
			$entity instanceof ExperienceOrb ||
			$entity instanceof FallingBlock ||
			$entity instanceof PrimedTNT ||
			$entity instanceof Boat ||
			$entity instanceof AbstractMinecart;
	}

	/**
	 * Returns the velocity added to an entity whose feet are at $feet, or null if it is out of reach. Block exposure
	 * is not included.
	 */
	public static function calculateImpulse(Vector3 $center, Vector3 $feet, float $eyeHeight, float $radius, float $knockbackMultiplier) : ?Vector3{
		$distance = $feet->distance($center) / ($radius * 2);
		if($distance > 1){
			return null;
		}
		$direction = $feet->add(0, $eyeHeight, 0)->subtractVector($center);
		$length = $direction->length();
		$direction = $length < 0.0001 ? new Vector3(0, 1, 0) : $direction->divide($length);

		return $direction->multiply((1 - $distance) * $knockbackMultiplier);
	}

	/**
	 * Returns the fraction (0-1) of the points of the entity's bounding box from which the center can be seen
	 * without a block in the way (Java ServerExplosion.getSeenPercent()).
	 */
	public static function getExposure(World $world, Vector3 $center, Entity $entity) : float{
		$boxes = [];
		return self::computeExposure($world, $center, $entity, $boxes);
	}

	/**
	 * @param float[][][] $boxes block hash => collision boxes of the block (minX, minY, minZ, maxX, maxY, maxZ), shared
	 *                           by the entities of a burst
	 * @phpstan-param array<int, list<array{float, float, float, float, float, float}>> $boxes
	 */
	private static function computeExposure(World $world, Vector3 $center, Entity $entity, array &$boxes) : float{
		$bb = $entity->getBoundingBox();
		$xs = 1 / (($bb->maxX - $bb->minX) * 2 + 1);
		$ys = 1 / (($bb->maxY - $bb->minY) * 2 + 1);
		$zs = 1 / (($bb->maxZ - $bb->minZ) * 2 + 1);
		$xOffset = (1 - floor(1 / $xs) * $xs) / 2;
		$zOffset = (1 - floor(1 / $zs) * $zs) / 2;

		//every ray runs between the center and a point of the box shifted by the offsets: a block whose collision
		//boxes stay outside of that hull (a slab or carpet under the feet, the block the charge landed on) cannot
		//be in the way of any ray (the usual case)
		if(!self::hasBoxIn($world,
			min($center->x, $bb->minX), min($center->y, $bb->minY), min($center->z, $bb->minZ),
			max($center->x, $bb->maxX + $xOffset), max($center->y, $bb->maxY), max($center->z, $bb->maxZ + $zOffset),
			$boxes
		)){
			return 1.0;
		}

		$hits = 0;
		$checks = 0;
		for($x = 0.0; $x <= 1; $x += $xs){
			for($y = 0.0; $y <= 1; $y += $ys){
				for($z = 0.0; $z <= 1; $z += $zs){
					if(!self::isSegmentBlocked($world,
						$bb->minX + $x * ($bb->maxX - $bb->minX) + $xOffset,
						$bb->minY + $y * ($bb->maxY - $bb->minY),
						$bb->minZ + $z * ($bb->maxZ - $bb->minZ) + $zOffset,
						$center->x, $center->y, $center->z,
						$boxes
					)){
						++$hits;
					}
					++$checks;
				}
			}
		}

		return $checks > 0 ? $hits / $checks : 0.0;
	}

	/**
	 * @phpstan-param array<int, list<array{float, float, float, float, float, float}>> $boxes
	 * @phpstan-return list<array{float, float, float, float, float, float}>
	 */
	private static function getBoxes(World $world, int $x, int $y, int $z, array &$boxes) : array{
		$hash = World::blockHash($x, $y, $z);
		if(!isset($boxes[$hash])){
			$list = [];
			if($world->isInWorld($x, $y, $z)){
				foreach($world->getBlockAt($x, $y, $z, true, false)->getCollisionBoxes() as $box){
					$list[] = [$box->minX, $box->minY, $box->minZ, $box->maxX, $box->maxY, $box->maxZ];
				}
			}
			$boxes[$hash] = $list;
		}
		return $boxes[$hash];
	}

	/**
	 * Returns whether a collision box of a block overlaps the given area by more than a touch.
	 *
	 * @phpstan-param array<int, list<array{float, float, float, float, float, float}>> $boxes
	 */
	private static function hasBoxIn(World $world, float $minX, float $minY, float $minZ, float $maxX, float $maxY, float $maxZ, array &$boxes) : bool{
		$e = self::TOUCH_EPSILON;
		for($x = (int) floor($minX), $x1 = (int) floor($maxX); $x <= $x1; ++$x){
			for($y = (int) floor($minY), $y1 = (int) floor($maxY); $y <= $y1; ++$y){
				for($z = (int) floor($minZ), $z1 = (int) floor($maxZ); $z <= $z1; ++$z){
					foreach(self::getBoxes($world, $x, $y, $z, $boxes) as [$bMinX, $bMinY, $bMinZ, $bMaxX, $bMaxY, $bMaxZ]){
						if($bMaxX > $minX + $e && $bMinX < $maxX - $e && $bMaxY > $minY + $e && $bMinY < $maxY - $e && $bMaxZ > $minZ + $e && $bMinZ < $maxZ - $e){
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	/**
	 * Returns whether a collision box crosses the segment from a to b, walking the blocks along it (3D DDA). A
	 * segment that only touches a box, or starts on its surface and leaves it, is not blocked (Java AABB.clip()
	 * only counts the faces a ray enters through).
	 *
	 * @phpstan-param array<int, list<array{float, float, float, float, float, float}>> $boxes
	 */
	private static function isSegmentBlocked(World $world, float $ax, float $ay, float $az, float $bx, float $by, float $bz, array &$boxes) : bool{
		$dx = $bx - $ax;
		$dy = $by - $ay;
		$dz = $bz - $az;
		$x = (int) floor($ax);
		$y = (int) floor($ay);
		$z = (int) floor($az);
		$endX = (int) floor($bx);
		$endY = (int) floor($by);
		$endZ = (int) floor($bz);
		$stepX = $dx > 0 ? 1 : ($dx < 0 ? -1 : 0);
		$stepY = $dy > 0 ? 1 : ($dy < 0 ? -1 : 0);
		$stepZ = $dz > 0 ? 1 : ($dz < 0 ? -1 : 0);
		//segment fraction at which the next block boundary on each axis is crossed, and between two boundaries
		$tMaxX = $stepX > 0 ? ($x + 1 - $ax) / $dx : ($stepX < 0 ? ($x - $ax) / $dx : 2.0);
		$tMaxY = $stepY > 0 ? ($y + 1 - $ay) / $dy : ($stepY < 0 ? ($y - $ay) / $dy : 2.0);
		$tMaxZ = $stepZ > 0 ? ($z + 1 - $az) / $dz : ($stepZ < 0 ? ($z - $az) / $dz : 2.0);
		$tDeltaX = $stepX !== 0 ? $stepX / $dx : 2.0;
		$tDeltaY = $stepY !== 0 ? $stepY / $dy : 2.0;
		$tDeltaZ = $stepZ !== 0 ? $stepZ / $dz : 2.0;

		while(true){
			foreach(self::getBoxes($world, $x, $y, $z, $boxes) as $box){
				if(self::segmentCrossesBox($ax, $ay, $az, $dx, $dy, $dz, $box)){
					return true;
				}
			}
			if($x === $endX && $y === $endY && $z === $endZ){
				return false;
			}
			if($tMaxX <= $tMaxY && $tMaxX <= $tMaxZ){
				if($tMaxX > 1){
					return false;
				}
				$x += $stepX;
				$tMaxX += $tDeltaX;
			}elseif($tMaxY <= $tMaxZ){
				if($tMaxY > 1){
					return false;
				}
				$y += $stepY;
				$tMaxY += $tDeltaY;
			}else{
				if($tMaxZ > 1){
					return false;
				}
				$z += $stepZ;
				$tMaxZ += $tDeltaZ;
			}
		}
	}

	/**
	 * Returns whether the segment a + t * d (0 <= t <= 1) runs through the inside of the box for more than a point.
	 *
	 * @phpstan-param array{float, float, float, float, float, float} $box
	 */
	private static function segmentCrossesBox(float $ax, float $ay, float $az, float $dx, float $dy, float $dz, array $box) : bool{
		$tMin = 0.0;
		$tMax = 1.0;
		if(!self::clipAxis($ax, $dx, $box[0], $box[3], $tMin, $tMax)){
			return false;
		}
		if(!self::clipAxis($ay, $dy, $box[1], $box[4], $tMin, $tMax)){
			return false;
		}
		return self::clipAxis($az, $dz, $box[2], $box[5], $tMin, $tMax);
	}

	/**
	 * Narrows [tMin, tMax] to the part of the segment between the two planes of one axis of a box (slab test).
	 * Returns false if nothing more than a point is left.
	 */
	private static function clipAxis(float $a, float $d, float $min, float $max, float &$tMin, float &$tMax) : bool{
		if($d > -1e-12 && $d < 1e-12){
			return $a > $min && $a < $max; //parallel: inside the slab, or outside (or on a face of) the box
		}
		$t1 = ($min - $a) / $d;
		$t2 = ($max - $a) / $d;
		if($t1 > $t2){
			$swap = $t1;
			$t1 = $t2;
			$t2 = $swap;
		}
		if($t1 > $tMin){
			$tMin = $t1;
		}
		if($t2 < $tMax){
			$tMax = $t2;
		}
		return $tMax - $tMin > 1e-7;
	}

	private static function getReaction(int $stateId) : int{
		if(!isset(self::$reactions[$stateId])){
			$block = RuntimeBlockStateRegistry::getInstance()->fromStateId($stateId);
			self::$reactions[$stateId] = match(true){
				//only the bottom half reacts, toggling the whole door (Java DoorBlock.onExplosionHit())
				$block instanceof Door => $block instanceof IronDoor || $block->isTop() ? self::REACTION_NONE : self::REACTION_DOOR,
				$block instanceof Trapdoor => $block instanceof IronTrapdoor ? self::REACTION_NONE : self::REACTION_TRAPDOOR,
				$block instanceof FenceGate => self::REACTION_FENCE_GATE,
				$block instanceof Button => $block->isPressed() ? self::REACTION_NONE : self::REACTION_BUTTON,
				$block instanceof Lever => self::REACTION_LEVER,
				$block instanceof Bell => self::REACTION_BELL,
				($block instanceof Candle || $block instanceof CakeWithCandle) => $block->isLit() ? self::REACTION_CANDLE : self::REACTION_NONE,
				default => self::REACTION_NONE
			};
		}
		return self::$reactions[$stateId];
	}

	/**
	 * Returns the centers of the blocks within $reach of the center that react to wind bursts. Only the subchunks
	 * whose palette holds such a block are scanned.
	 *
	 * @return float[][]
	 * @phpstan-return list<array{float, float, float}>
	 */
	private static function findReactiveBlocksNear(World $world, Vector3 $center, float $reach) : array{
		$minX = (int) floor($center->x - $reach);
		$maxX = (int) floor($center->x + $reach);
		$minY = max(World::Y_MIN, (int) floor($center->y - $reach));
		$maxY = min(World::Y_MAX - 1, (int) floor($center->y + $reach));
		$minZ = (int) floor($center->z - $reach);
		$maxZ = (int) floor($center->z + $reach);
		$result = [];
		for($cx = $minX >> Chunk::COORD_BIT_SIZE, $maxCx = $maxX >> Chunk::COORD_BIT_SIZE; $cx <= $maxCx; ++$cx){
			for($cz = $minZ >> Chunk::COORD_BIT_SIZE, $maxCz = $maxZ >> Chunk::COORD_BIT_SIZE; $cz <= $maxCz; ++$cz){
				$chunk = $world->getChunk($cx, $cz);
				if($chunk === null){
					continue;
				}
				for($sy = $minY >> SubChunk::COORD_BIT_SIZE, $maxSy = $maxY >> SubChunk::COORD_BIT_SIZE; $sy <= $maxSy; ++$sy){
					$subChunk = $chunk->getSubChunk($sy);
					if($subChunk->isEmptyFast() || !self::paletteHasReactiveBlock($subChunk)){
						continue;
					}
					//the part of the reach cube inside this subchunk
					$x0 = max($minX, $cx << Chunk::COORD_BIT_SIZE);
					$x1 = min($maxX, ($cx << Chunk::COORD_BIT_SIZE) + SubChunk::COORD_MASK);
					$y0 = max($minY, $sy << SubChunk::COORD_BIT_SIZE);
					$y1 = min($maxY, ($sy << SubChunk::COORD_BIT_SIZE) + SubChunk::COORD_MASK);
					$z0 = max($minZ, $cz << Chunk::COORD_BIT_SIZE);
					$z1 = min($maxZ, ($cz << Chunk::COORD_BIT_SIZE) + SubChunk::COORD_MASK);
					for($x = $x0; $x <= $x1; ++$x){
						for($y = $y0; $y <= $y1; ++$y){
							for($z = $z0; $z <= $z1; ++$z){
								if(self::getReaction($subChunk->getBlockStateId($x & SubChunk::COORD_MASK, $y & SubChunk::COORD_MASK, $z & SubChunk::COORD_MASK)) !== self::REACTION_NONE){
									$result[] = [$x + 0.5, $y + 0.5, $z + 0.5];
								}
							}
						}
					}
				}
			}
		}
		return $result;
	}

	private static function paletteHasReactiveBlock(SubChunk $subChunk) : bool{
		foreach($subChunk->getBlockLayers() as $layer){
			foreach($layer->getPalette() as $stateId){
				if(self::getReaction($stateId) !== self::REACTION_NONE){
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @phpstan-return list<array{float, float, float}>
	 */
	private static function getRayDirections() : array{
		if(self::$rayDirections === null){
			$directions = [];
			$max = self::RAY_GRID - 1;
			for($i = 0; $i < self::RAY_GRID; ++$i){
				for($j = 0; $j < self::RAY_GRID; ++$j){
					for($k = 0; $k < self::RAY_GRID; ++$k){
						if($i !== 0 && $i !== $max && $j !== 0 && $j !== $max && $k !== 0 && $k !== $max){
							continue;
						}
						$x = $i / $max * 2 - 1;
						$y = $j / $max * 2 - 1;
						$z = $k / $max * 2 - 1;
						$length = sqrt($x * $x + $y * $y + $z * $z);
						$directions[] = [$x / $length, $y / $length, $z / $length];
					}
				}
			}
			self::$rayDirections = $directions;
		}
		return self::$rayDirections;
	}

	/**
	 * Returns the blocks reached by the burst that react to it (Java ServerExplosion.calculateExplodedPositions()
	 * filtered to the reacting blocks).
	 *
	 * @return Block[]
	 * @phpstan-return array<int, Block>
	 */
	public static function findTriggeredBlocks(World $world, Vector3 $center, float $radius) : array{
		$maxPower = $radius * self::RAY_MAX_POWER_FACTOR;
		$reach = $maxPower / self::RAY_DECAY * self::RAY_STEP + 1;
		$candidates = self::findReactiveBlocksNear($world, $center, $reach);
		if(count($candidates) === 0){
			return [];
		}

		$registry = RuntimeBlockStateRegistry::getInstance();
		$explorer = new SubChunkExplorer($world);
		$found = [];
		$randMax = mt_getrandmax();
		foreach(self::getRayDirections() as [$dx, $dy, $dz]){
			$power = $radius * (0.7 + mt_rand() / $randMax * 0.6);
			//only the rays passing through a reacting block matter: the others are not traced
			$passes = false;
			foreach($candidates as [$bx, $by, $bz]){
				$vx = $bx - $center->x;
				$vy = $by - $center->y;
				$vz = $bz - $center->z;
				$along = $vx * $dx + $vy * $dy + $vz * $dz;
				if($along > -self::RAY_PASS_DISTANCE && $along < $reach && $vx * $vx + $vy * $vy + $vz * $vz - $along * $along <= self::RAY_PASS_DISTANCE ** 2){
					$passes = true;
					break;
				}
			}
			if(!$passes){
				continue;
			}
			$px = $center->x;
			$py = $center->y;
			$pz = $center->z;
			while($power > 0){
				$x = (int) floor($px);
				$y = (int) floor($py);
				$z = (int) floor($pz);
				if($explorer->moveTo($x, $y, $z) === SubChunkExplorerStatus::INVALID || $explorer->currentSubChunk === null){
					break;
				}
				$subChunk = $explorer->currentSubChunk;
				$lx = $x & SubChunk::COORD_MASK;
				$ly = $y & SubChunk::COORD_MASK;
				$lz = $z & SubChunk::COORD_MASK;
				$stateId = $subChunk->getBlockStateId($lx, $ly, $lz);
				$water = LiquidLayer::canContainWater($stateId) && LiquidLayer::isWaterInSubChunk($subChunk, $lx, $ly, $lz);
				if($stateId !== Block::EMPTY_STATE_ID || $water){
					$resistance = ($registry->blastResistance[$stateId] ?? 0) / 5;
					if($water){
						$resistance = max($resistance, self::WATER_RESISTANCE);
					}
					$power -= ($resistance + 0.3) * self::RAY_STEP;
					if($power > 0 && self::getReaction($stateId) !== self::REACTION_NONE){
						$hash = World::blockHash($x, $y, $z);
						if(!isset($found[$hash])){
							$found[$hash] = $world->getBlockAt($x, $y, $z);
						}
					}
				}
				$px += $dx * self::RAY_STEP;
				$py += $dy * self::RAY_STEP;
				$pz += $dz * self::RAY_STEP;
				$power -= self::RAY_DECAY;
			}
		}
		return $found;
	}

	/**
	 * Operates a block reached by a burst (Java Block.onExplosionHit() with TRIGGER_BLOCK).
	 */
	private static function triggerBlock(World $world, Block $block) : void{
		$pos = $block->getPosition();
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		$reaction = self::getReaction($block->getStateId());
		switch($reaction){
			case self::REACTION_DOOR:
			case self::REACTION_TRAPDOOR:
			case self::REACTION_FENCE_GATE:
				if($world->getRedstone()?->getRememberedPowered($x, $y, $z) === true){
					return; //held open or closed by redstone
				}
				$block->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5));
				break;
			case self::REACTION_BUTTON:
			case self::REACTION_LEVER:
				$block->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0.5, 0.5, 0.5));
				break;
			case self::REACTION_BELL:
				if($block instanceof Bell){
					$block->ring($block->getFacing());
				}
				break;
			case self::REACTION_CANDLE:
				if(($block instanceof Candle || $block instanceof CakeWithCandle) && $block->isLit()){
					$world->addSound($pos, new FireExtinguishSound());
					$world->setBlock($pos, $block->setLit(false));
					$world->addGameEvent($pos, GameEvent::BLOCK_CHANGE);
				}
				break;
		}
	}
}
