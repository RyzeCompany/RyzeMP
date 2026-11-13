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

namespace pocketmine\world\generator\vanilla\structure\piece\fortress;

use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\BoundingBox;
use pocketmine\world\generator\vanilla\structure\piece\StructurePiecesBuilder;
use pocketmine\world\generator\vanilla\structure\StructurePlaceContext;

/**
 * NetherFortressPieces.BridgeEndFiller: the broken end of a bridge, shaped by its own seed.
 */
final class BridgeEndFiller extends NetherBridgePiece{
	private int $selfSeed;

	public function __construct(int $genDepth, LegacyRandom $random, BoundingBox $box, int $direction){
		parent::__construct($genDepth, $box, $direction);
		$this->selfSeed = $random->nextInt();
	}

	public static function createPiece(StructurePiecesBuilder $builder, LegacyRandom $random, int $footX, int $footY, int $footZ, int $direction, int $genDepth) : ?NetherBridgePiece{
		$box = BoundingBox::orientBox($footX, $footY, $footZ, -1, -3, 0, 5, 10, 8, $direction);
		if(!self::isOkBox($box) || $builder->findCollisionPiece($box) !== null){
			return null;
		}
		return new self($genDepth, $random, $box, $direction);
	}

	public function postProcess(StructurePlaceContext $c, BoundingBox $box, int $chunkX, int $chunkZ, array $pivot) : void{
		$bricks = $this->bricks($c);
		//RandomSource.createThreadLocalInstance(seed) = SingleThreadedRandomSource, the same LCG as LegacyRandom
		$selfRandom = new LegacyRandom($this->selfSeed);
		for($x = 0; $x <= 4; ++$x){
			for($y = 3; $y <= 4; ++$y){
				$z = $selfRandom->nextBoundedInt(8);
				$this->generateBox($c, $box, $x, $y, 0, $x, $y, $z, $bricks, $bricks, false);
			}
		}
		$z = $selfRandom->nextBoundedInt(8);
		$this->generateBox($c, $box, 0, 5, 0, 0, 5, $z, $bricks, $bricks, false);
		$z = $selfRandom->nextBoundedInt(8);
		$this->generateBox($c, $box, 4, 5, 0, 4, 5, $z, $bricks, $bricks, false);
		for($x = 0; $x <= 4; ++$x){
			$z = $selfRandom->nextBoundedInt(5);
			$this->generateBox($c, $box, $x, 2, 0, $x, 2, $z, $bricks, $bricks, false);
		}
		for($x = 0; $x <= 4; ++$x){
			for($y = 0; $y <= 1; ++$y){
				$z = $selfRandom->nextBoundedInt(3);
				$this->generateBox($c, $box, $x, $y, 0, $x, $y, $z, $bricks, $bricks, false);
			}
		}
	}
}
