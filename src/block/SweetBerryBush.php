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

use pocketmine\block\utils\Ageable;
use pocketmine\block\utils\AgeableTrait;
use pocketmine\block\utils\BlockEventHelper;
use pocketmine\block\utils\CropGrowthHelper;
use pocketmine\block\utils\FortuneDropHelper;
use pocketmine\block\utils\StaticSupportTrait;
use pocketmine\entity\animal\Bee;
use pocketmine\entity\animal\Fox;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Mob;
use pocketmine\event\entity\EntityDamageByBlockEvent;
use pocketmine\item\Fertilizer;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\SweetBerriesPickSound;
use function abs;
use function mt_rand;

class SweetBerryBush extends Flowable implements Ageable{
	use AgeableTrait;
	use StaticSupportTrait;

	public const STAGE_SAPLING = 0;
	public const STAGE_BUSH_NO_BERRIES = 1;
	public const STAGE_BUSH_SOME_BERRIES = 2;
	public const STAGE_MATURE = 3;
	public const MAX_AGE = self::STAGE_MATURE;

	private const MIN_GROWTH_LIGHT = 9;
	/** Moving less than this per tick on both horizontal axes inside a bush doesn't hurt (vanilla entityInside()). */
	private const MIN_HURT_MOVEMENT = 0.003;

	/**
	 * Last horizontal position of the entities which are inside bushes and don't report their movement (all except
	 * players), with the server tick it was seen at.
	 * @phpstan-var \WeakMap<Entity, array{float, float, int}>|null
	 */
	private static ?\WeakMap $lastPositions = null;

	public function getBerryDropAmount() : int{
		if($this->age === self::STAGE_MATURE){
			return mt_rand(2, 3);
		}elseif($this->age >= self::STAGE_BUSH_SOME_BERRIES){
			return mt_rand(1, 2);
		}
		return 0;
	}

	/**
	 * @deprecated
	 */
	protected function canBeSupportedBy(Block $block) : bool{
		return $block->getTypeId() !== BlockTypeIds::FARMLAND && //bedrock-specific thing (bug?)
			($block->hasTypeTag(BlockTypeTags::DIRT) || $block->hasTypeTag(BlockTypeTags::MUD));
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return $this->canBeSupportedBy($supportBlock);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		if($this->age < self::STAGE_MATURE && $item instanceof Fertilizer){
			$block = clone $this;
			$block->age++;
			if(BlockEventHelper::grow($this, $block, $player)){
				$item->pop();
			}
		}elseif(($dropAmount = $this->getBerryDropAmount()) > 0){
			$world->setBlock($this->position, $this->setAge(self::STAGE_BUSH_NO_BERRIES));
			$world->dropItem($this->position, $this->asItem()->setCount($dropAmount));
			$world->addSound($this->position, new SweetBerriesPickSound());
		}

		return true;
	}

	public function asItem() : Item{
		return VanillaItems::SWEET_BERRIES();
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$count = match($this->age){
			self::STAGE_MATURE => FortuneDropHelper::discrete($item, 2, 3),
			self::STAGE_BUSH_SOME_BERRIES => FortuneDropHelper::discrete($item, 1, 2),
			default => 0
		};
		return [
			$this->asItem()->setCount($count)
		];
	}

	public function ticksRandomly() : bool{
		return $this->age < self::STAGE_MATURE;
	}

	public function getFlameEncouragement() : int{
		return 60;
	}

	public function getFlammability() : int{
		return 100;
	}

	public function onRandomTick() : void{
		//vanilla SweetBerryBushBlock.randomTick(): 1 in 5, with a light level of at least 9 above
		if(
			$this->age < self::STAGE_MATURE &&
			mt_rand(0, 4) === 0 &&
			CropGrowthHelper::hasEnoughLight($this->getSide(Facing::UP), self::MIN_GROWTH_LIGHT)
		){
			$block = clone $this;
			++$block->age;
			BlockEventHelper::grow($this, $block, null);
		}
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		//foxes and bees go through berry bushes freely (Bedrock damage_sensor of fox.json and bee.json, Java
		//SweetBerryBushBlock.entityInside())
		if($entity instanceof Fox || $entity instanceof Bee){
			return true;
		}
		if($entity instanceof Mob){
			//players are slowed down by their client
			$entity->makeStuckInBlock($this, 0.8, 0.75, 0.8);
		}
		if($this->age >= self::STAGE_BUSH_NO_BERRIES && $entity instanceof Living){
			$entity->resetFallDistance();

			//only hurts while moving (vanilla SweetBerryBushBlock.entityInside()): standing still in a bush is safe; the
			//sapling stage doesn't hurt (Java; newer Bedrock versions may hurt there too, unconfirmed)
			if(self::movedSideways($entity)){
				$entity->attack(new EntityDamageByBlockEvent($this, $entity, EntityDamageByBlockEvent::CAUSE_SWEET_BERRY_BUSH, 1));
			}
		}
		return true;
	}

	/**
	 * Returns whether the entity moved at least 0.003 blocks along X or Z during its last tick: players by what their
	 * client reported, other entities by comparing with where they were inside a bush the tick before (an entity just
	 * walking in counts as moving).
	 */
	private static function movedSideways(Living $entity) : bool{
		if($entity instanceof Player){
			$movement = $entity->getTickMovement();
			return abs($movement->x) >= self::MIN_HURT_MOVEMENT || abs($movement->z) >= self::MIN_HURT_MOVEMENT;
		}
		$location = $entity->getLocation();
		$lastPositions = self::$lastPositions ??= new \WeakMap();
		$last = $lastPositions[$entity] ?? null;
		$lastPositions[$entity] = [(float) $location->x, (float) $location->z, $entity->ticksLived];
		if($last === null || $entity->ticksLived - $last[2] > 2){
			//just got in: it isn't slowed down by the bush yet, so its motion is how it moved
			$motion = $entity->getMotion();
			return abs($motion->x) >= self::MIN_HURT_MOVEMENT || abs($motion->z) >= self::MIN_HURT_MOVEMENT;
		}
		return abs($location->x - $last[0]) >= self::MIN_HURT_MOVEMENT || abs($location->z - $last[1]) >= self::MIN_HURT_MOVEMENT;
	}
}
