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

use pocketmine\world\generator\vanilla\feature\FeatureRandom;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\generator\vanilla\structure\StructureRandom;
use pocketmine\world\generator\vanilla\structure\template\Rotations;
use pocketmine\world\generator\vanilla\structure\template\StructureTemplates;
use function count;
use function max;

/**
 * A template pool (vanilla's StructureTemplatePool): the elements repeated by weight, and the fallback pool.
 */
final class TemplatePool{
	private ?int $maxSize = null;

	/**
	 * @param PoolElement[] $templates elements, each repeated "weight" times
	 * @phpstan-param list<PoolElement> $templates
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $templates,
		public readonly string $fallback
	){}

	public function size() : int{
		return count($this->templates);
	}

	public function getRandomTemplate(LegacyRandom|FeatureRandom $random) : PoolElement{
		return $this->templates[$random->nextBoundedInt(count($this->templates))];
	}

	/**
	 * @phpstan-return list<PoolElement>
	 */
	public function getShuffledTemplates(LegacyRandom|FeatureRandom $random) : array{
		return StructureRandom::shuffled($this->templates, $random);
	}

	/**
	 * StructureTemplatePool.getMaxSize(): the tallest element.
	 */
	public function getMaxSize(StructureTemplates $templates) : int{
		if($this->maxSize === null){
			$max = 0;
			foreach($this->templates as $element){
				if(!$element->isEmpty()){
					$max = max($max, $element->getBoundingBox($templates, 0, 0, 0, Rotations::NONE)->getYSpan());
				}
			}
			$this->maxSize = $max;
		}
		return $this->maxSize;
	}
}
