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

namespace pocketmine\block;

use pocketmine\block\tile\BrushableBlock as TileBrushableBlock;
use pocketmine\block\utils\Fallable;
use pocketmine\block\utils\FallableTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\object\FallingBlock;
use pocketmine\item\Brush;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\BrushSound;
use function max;

/**
 * Suspicious sand and gravel. Brushing them (using a brush on them) uncovers the item rolled from their archaeology
 * loot table; after 10 brush strokes, at most one every 10 ticks like in vanilla, the item pops out of the brushed face
 * and the block turns into normal sand/gravel. Unfinished brushing decays after 2 seconds.
 *
 * They fall like sand (unless "hanging", which vanilla uses for blocks generated without support) and break without
 * dropping anything when they land. They never drop anything when broken, even with silk touch.
 */
class BrushableBlock extends Opaque implements Fallable{
	use FallableTrait{
		onNearbyBlockChange as fallableOnNearbyBlockChange;
	}

	public const MAX_BRUSHED_PROGRESS = 3;
	public const BRUSHES_TO_COMPLETE = 10;
	private const BRUSH_COOLDOWN_TICKS = 10;
	private const BRUSH_RESET_TICKS = 40;

	private int $brushedProgress = 0;
	private bool $hanging = false;

	/**
	 * @phpstan-param \Closure() : Block $brushedResult
	 */
	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, private \Closure $brushedResult){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_BRUSHED_PROGRESS, $this->brushedProgress);
		$w->bool($this->hanging);
	}

	public function getBrushedProgress() : int{ return $this->brushedProgress; }

	/** @return $this */
	public function setBrushedProgress(int $brushedProgress) : self{
		if($brushedProgress < 0 || $brushedProgress > self::MAX_BRUSHED_PROGRESS){
			throw new \InvalidArgumentException("Brushed progress must be in range 0-" . self::MAX_BRUSHED_PROGRESS);
		}
		$this->brushedProgress = $brushedProgress;
		return $this;
	}

	public function isHanging() : bool{ return $this->hanging; }

	/** @return $this */
	public function setHanging(bool $hanging) : self{
		$this->hanging = $hanging;
		return $this;
	}

	/**
	 * Returns the block this turns into once fully brushed.
	 */
	public function getBrushedResult() : Block{
		return ($this->brushedResult)();
	}

	public function onNearbyBlockChange() : void{
		if(!$this->hanging){
			$this->fallableOnNearbyBlockChange();
		}
	}

	public function onHitGround(FallingBlock $blockEntity) : bool{
		//vanilla: falling suspicious blocks break and drop nothing
		return false;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	private static function progressForCount(int $count) : int{
		return match(true){
			$count <= 0 => 0,
			$count < 3 => 1,
			$count < 6 => 2,
			default => 3
		};
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$item instanceof Brush){
			return false;
		}
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileBrushableBlock){
			return false;
		}
		$tick = $world->getServer()->getTick();
		if($tick < $tile->cooldownEndsAtTick){
			return true;
		}
		$tile->cooldownEndsAtTick = $tick + self::BRUSH_COOLDOWN_TICKS;
		$tile->brushCountResetsAtTick = $tick + self::BRUSH_RESET_TICKS;
		$tile->setBrushDirection($face);
		$uncovered = $tile->unpackLootTable($player, $item);
		if($tile->getLootTable() !== null){
			// The pinned data could not be read. Leave the undisclosed loot intact.
			return true;
		}

		$count = $tile->getBrushCount() + 1;
		$world->addSound($this->position->add(0.5, 0.5, 0.5), new BrushSound($this, $count >= self::BRUSHES_TO_COMPLETE));
		if($count >= self::BRUSHES_TO_COMPLETE){
			if($uncovered !== null){
				$world->dropItem($this->position->add(0.5, 0.5, 0.5)->addVector(Vector3::zero()->getSide($face)->multiply(0.7)), $uncovered);
			}
			$tile->setItem(null);
			$item->applyDamage(1);
			$world->setBlock($this->position, $this->getBrushedResult());
			return true;
		}

		$tile->setBrushCount($count);
		$progress = self::progressForCount($count);
		if($progress !== $this->brushedProgress){
			$this->brushedProgress = $progress;
			$world->setBlock($this->position, $this);
		}
		$world->scheduleDelayedBlockUpdate($this->position, self::BRUSH_RESET_TICKS);
		return true;
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if(!$tile instanceof TileBrushableBlock || $tile->getBrushCount() === 0){
			return;
		}
		$tick = $world->getServer()->getTick();
		if($tick < $tile->brushCountResetsAtTick){
			$world->scheduleDelayedBlockUpdate($this->position, $tile->brushCountResetsAtTick - $tick);
			return;
		}
		//vanilla: the brush count drops by 2 every 4 ticks once the player stops brushing
		$count = max(0, $tile->getBrushCount() - 2);
		$tile->setBrushCount($count);
		$tile->brushCountResetsAtTick = $tick + 4;
		$progress = self::progressForCount($count);
		if($progress !== $this->brushedProgress){
			$this->brushedProgress = $progress;
			$world->setBlock($this->position, $this);
		}
		if($count > 0){
			$world->scheduleDelayedBlockUpdate($this->position, 4);
		}
	}
}
