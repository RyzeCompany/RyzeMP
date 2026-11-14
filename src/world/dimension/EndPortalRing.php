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

namespace pocketmine\world\dimension;

use pocketmine\block\BlockTypeIds;
use pocketmine\block\EndPortalFrame;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\world\sound\EndPortalSpawnSound;
use pocketmine\world\World;

/**
 * The ring of twelve End portal frames (three per side) around a 3x3 hole. When every frame faces the centre and holds
 * an eye of ender, the hole fills with End portal blocks (vanilla EndPortalFrameBlock.getOrCreatePortalShape(): the
 * three frames on the north side face south, and so on). A frame faces the player who placed it, so a ring built by
 * hand works when its frames were placed from inside it; strongholds generate their frames facing inwards.
 */
final class EndPortalRing{

	private function __construct(){
		//NOOP
	}

	/**
	 * @return Vector3[] positions of the twelve frames around the given centre
	 */
	public static function getFramePositions(Vector3 $center) : array{
		$positions = [];
		foreach(Facing::HORIZONTAL as $side){
			$base = $center->getSide($side, 2);
			$tangent = Facing::rotateY($side, true);
			foreach([-1, 0, 1] as $offset){
				$positions[] = $base->getSide($tangent, $offset);
			}
		}
		return $positions;
	}

	/**
	 * @return Vector3[] the 3x3 portal positions around the given centre
	 */
	public static function getInteriorPositions(Vector3 $center) : array{
		$positions = [];
		for($dx = -1; $dx <= 1; ++$dx){
			for($dz = -1; $dz <= 1; ++$dz){
				$positions[] = $center->add($dx, 0, $dz);
			}
		}
		return $positions;
	}

	private static function isComplete(World $world, Vector3 $center) : bool{
		foreach(Facing::HORIZONTAL as $side){
			$base = $center->getSide($side, 2);
			$tangent = Facing::rotateY($side, true);
			$inward = Facing::opposite($side);
			foreach([-1, 0, 1] as $offset){
				$block = $world->getBlock($base->getSide($tangent, $offset));
				if(!$block instanceof EndPortalFrame || !$block->hasEye() || $block->getFacing() !== $inward){
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Finds the centre of a complete ring the frame at the given position is part of.
	 */
	public static function findCompleteRing(World $world, Vector3 $framePos) : ?Vector3{
		$framePos = $framePos->floor();
		foreach(Facing::HORIZONTAL as $inward){
			$base = $framePos->getSide($inward, 2);
			$tangent = Facing::rotateY($inward, true);
			foreach([-1, 0, 1] as $offset){
				$center = $base->getSide($tangent, $offset);
				if(self::isComplete($world, $center)){
					return $center;
				}
			}
		}
		return null;
	}

	/**
	 * Lights the portal of the ring the given frame belongs to, if the ring is complete. Returns whether a portal was
	 * lit.
	 */
	public static function tryActivate(World $world, Vector3 $framePos) : bool{
		$center = self::findCompleteRing($world, $framePos);
		if($center === null){
			return false;
		}
		$lit = false;
		$portal = VanillaBlocks::END_PORTAL();
		foreach(self::getInteriorPositions($center) as $pos){
			if($world->getBlock($pos)->getTypeId() !== BlockTypeIds::END_PORTAL){
				$world->setBlock($pos, $portal);
				$lit = true;
			}
		}
		if($lit){
			$world->addSound($center->add(0.5, 0.5, 0.5), new EndPortalSpawnSound());
		}
		return $lit;
	}
}
