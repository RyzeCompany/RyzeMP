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

namespace pocketmine\entity\ai\hostile;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\math\Vector3;
use function cos;
use function deg2rad;
use function sqrt;

/**
 * Whether a living entity (usually a player) is looking at a point or an entity, from its view direction (vanilla
 * minecraft:looked_at, Java EnderMan::isLookingAtMe).
 */
final class LookDetection{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the cosine of the angle between the viewer's look direction and the direction to the point (1: dead
	 * centre), and the distance to it.
	 *
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	public static function lookCosine(Living $viewer, Vector3 $point) : array{
		$eye = $viewer->getEyePos();
		$dx = $point->x - $eye->x;
		$dy = $point->y - $eye->y;
		$dz = $point->z - $eye->z;
		$dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
		if($dist < 0.0001){
			return [1.0, 0.0];
		}
		$view = $viewer->getDirectionVector();
		return [($view->x * $dx + $view->y * $dy + $view->z * $dz) / $dist, $dist];
	}

	/** Item type ID of a carved pumpkin (fixed: block type IDs map to item type IDs one to one). */
	private static ?int $carvedPumpkinItemTypeId = null;

	public static function wearsCarvedPumpkin(Living $viewer) : bool{
		return $viewer->getArmorInventory()->getHelmet()->getTypeId() === (self::$carvedPumpkinItemTypeId ??= VanillaBlocks::CARVED_PUMPKIN()->asItem()->getTypeId());
	}

	/**
	 * Enderman stare check (Java): the viewer looks at the entity's eyes within a cone narrowing with distance
	 * (cosine > 1 - 0.025 / distance) and nothing blocks the view. Wearing a carved pumpkin hides the stare.
	 */
	public static function isStaringAt(Living $viewer, Entity $entity) : bool{
		//the narrow cone first: it rules out almost every viewer, and none of the three checks has side effects
		[$cos, $dist] = self::lookCosine($viewer, $entity->getEyePos());
		return $dist > 0 && $cos > 1 - 0.025 / $dist && !self::wearsCarvedPumpkin($viewer) && Sensing::hasLineOfSight($viewer, $entity);
	}

	/**
	 * Returns whether the viewer sees the entity within a field of view (degrees), checking its head, body and feet
	 * (vanilla looked_at with look_at_locations head, body, feet + 0.5).
	 */
	public static function isInFieldOfView(Living $viewer, Entity $entity, float $fieldOfViewDegrees) : bool{
		$minCos = cos(deg2rad($fieldOfViewDegrees / 2));
		$loc = $entity->getLocation();
		$height = $entity->getSize()->getHeight();
		foreach([$entity->getEyePos(), new Vector3($loc->x, $loc->y + $height / 2, $loc->z), new Vector3($loc->x, $loc->y + 0.5, $loc->z)] as $point){
			[$cos, ] = self::lookCosine($viewer, $point);
			if($cos >= $minCos){
				return Sensing::hasLineOfSight($viewer, $entity);
			}
		}
		return false;
	}
}
