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

use pocketmine\block\tile\Bed as TileBed;
use pocketmine\block\utils\Colored;
use pocketmine\block\utils\ColoredTrait;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\HorizontalFacing;
use pocketmine\block\utils\HorizontalFacingTrait;
use pocketmine\block\utils\SupportType;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\event\block\BlockPreExplodeEvent;
use pocketmine\item\Item;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\BlockTransaction;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\Explosion;
use pocketmine\world\GameRules;
use pocketmine\world\Position;
use pocketmine\world\SleepRules;

class Bed extends Transparent implements Colored, HorizontalFacing{
	use ColoredTrait;
	use HorizontalFacingTrait;

	protected bool $occupied = false;
	protected bool $head = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->occupied);
		$w->bool($this->head);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		//read extra state information from the tile - this is an ugly hack
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileBed){
			$this->color = $tile->getColor();
		}else{
			$this->color = DyeColor::RED; //legacy pre-1.1 beds don't have tiles
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		//extra block properties storage hack
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileBed){
			$tile->setColor($this->color);
		}
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 7 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function isHeadPart() : bool{
		return $this->head;
	}

	/** @return $this */
	public function setHead(bool $head) : self{
		$this->head = $head;
		return $this;
	}

	public function isOccupied() : bool{
		return $this->occupied;
	}

	/** @return $this */
	public function setOccupied(bool $occupied = true) : self{
		$this->occupied = $occupied;
		return $this;
	}

	private function getOtherHalfSide() : int{
		return $this->head ? Facing::opposite($this->facing) : $this->facing;
	}

	public function getOtherHalf() : ?Bed{
		$other = $this->getSide($this->getOtherHalfSide());
		if($other instanceof Bed && $other->head !== $this->head && $other->facing === $this->facing){
			return $other;
		}

		return null;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			$other = $this->getOtherHalf();
			$playerPos = $player->getPosition();
			if($other === null){
				$player->sendMessage(KnownTranslationFactory::pocketmine_block_bed_incomplete()->prefix(TextFormat::GRAY));

				return true;
			}elseif($this->position->getWorld()->getDimension() !== Dimension::OVERWORLD){
				//beds can't be slept in outside the Overworld: they blow up (unless respawnblocksexplode is off)
				if(GameRules::allowsRespawnBlockExplosions($this->position->getWorld())){
					$this->explode($player, $other);
				}else{
					$player->sendMessage(KnownTranslationFactory::tile_bed_noSleep()->prefix(TextFormat::GRAY));
				}
				return true;
			}elseif($playerPos->distanceSquared($this->position) > 4 && $playerPos->distanceSquared($other->position) > 4){
				$player->sendMessage(KnownTranslationFactory::tile_bed_tooFar()->prefix(TextFormat::GRAY));
				return true;
			}

			$b = ($this->isHeadPart() ? $this : $other);
			$world = $b->position->getWorld();

			if($b->occupied && ($villager = VillagerV2::findSleeperAt($world, $b->position)) !== null){
				//using a villager's bed wakes it up
				$villager->wakeUp();
				$b->occupied = false;
			}
			if($b->occupied){
				$player->sendMessage(KnownTranslationFactory::tile_bed_occupied()->prefix(TextFormat::GRAY));

				return true;
			}

			//using a bed sets the spawn point at any time, even when the player can't sleep (Bedrock 1.16+)
			if(!$this->isSpawnPointOf($player, $b)){
				$player->setSpawn($b->position);
				$player->sendMessage(KnownTranslationFactory::tile_bed_respawnSet()->prefix(TextFormat::GRAY));
			}

			//players can also sleep through a thunderstorm (vanilla)
			if(!SleepRules::canSleepNow($world)){
				$player->sendMessage(KnownTranslationFactory::tile_bed_noSleep()->prefix(TextFormat::GRAY));

				return true;
			}

			if(($player->isSurvival() || $player->isAdventure()) && SleepRules::findMonsterPreventingSleep($world, $b->position, $player) !== null){
				$player->sendMessage(KnownTranslationFactory::tile_bed_notSafe()->prefix(TextFormat::GRAY));

				return true;
			}

			$player->sleepOn($b->position);
		}

		return true;

	}

	private function isSpawnPointOf(Player $player, Bed $head) : bool{
		if(!$player->hasValidCustomSpawn()){
			return false;
		}
		$spawn = $player->getSpawn();
		return $spawn->getWorld() === $head->position->getWorld() &&
			$spawn->getFloorX() === $head->position->getFloorX() &&
			$spawn->getFloorY() === $head->position->getFloorY() &&
			$spawn->getFloorZ() === $head->position->getFloorZ();
	}

	/**
	 * Vanilla "intentional game design": power 5 incendiary explosion centred on the head of the bed.
	 */
	private function explode(Player $player, Bed $other) : void{
		$head = $this->head ? $this : $other;
		$ev = new BlockPreExplodeEvent($head, 5, $player);
		$ev->setIncendiary(true);
		$ev->call();
		if($ev->isCancelled()){
			return;
		}

		$world = $this->position->getWorld();
		$world->setBlock($this->position, VanillaBlocks::AIR());
		$world->setBlock($other->position, VanillaBlocks::AIR());

		$explosion = new Explosion(Position::fromObject($head->position->add(0.5, 0.5, 0.5), $world), $ev->getRadius(), $head);
		$explosion->setFireChance($ev->getFireChance());
		if($ev->isBlockBreaking()){
			$explosion->explodeA();
		}
		$explosion->explodeB();
	}

	public function onNearbyBlockChange() : void{
		if(!$this->head && ($other = $this->getOtherHalf()) !== null && $other->occupied !== $this->occupied){
			$this->occupied = $other->occupied;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	public function onEntityLand(Entity $entity) : ?float{
		//beds halve the fall damage (the part of the distance above the 3 safe blocks), sneaking only stops the bounce
		$fallDistance = $entity->getFallDistance();
		if($fallDistance > 3){
			$entity->setFallDistance(3 + ($fallDistance - 3) * 0.5);
		}
		if($entity instanceof Living && $entity->isSneaking()){
			return null;
		}
		$motionY = $entity->getMotion()->y;
		if($motionY >= 0){
			return null;
		}
		//2/3 in Java, according to the wiki; items, orbs, minecarts... get 80% of it (vanilla BedBlock.bounceUp())
		return $motionY * -3 / 4 * ($entity instanceof Living ? 1.0 : 0.8);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($this->canBeSupportedAt($blockReplace)){
			$this->facing = $player !== null ? $player->getHorizontalFacing() : Facing::NORTH;

			$next = $this->getSide($this->getOtherHalfSide());
			if($next->canBeReplaced() && $this->canBeSupportedAt($next)){
				$nextState = clone $this;
				$nextState->head = true;
				$tx->addBlock($blockReplace->position, $this)->addBlock($next->position, $nextState);
				return true;
			}
		}

		return false;
	}

	public function getDrops(Item $item) : array{
		if($this->head){
			return parent::getDrops($item);
		}

		return [];
	}

	public function getAffectedBlocks() : array{
		if(($other = $this->getOtherHalf()) !== null){
			return [$this, $other];
		}

		return parent::getAffectedBlocks();
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN) !== SupportType::NONE;
	}

	public function getMaxStackSize() : int{ return 1; }
}
