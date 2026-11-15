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

use pocketmine\block\tile\Cauldron as TileCauldron;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Potion;
use pocketmine\item\SplashPotion;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\sound\CauldronEmptyPotionSound;
use pocketmine\world\sound\CauldronFillPotionSound;
use pocketmine\world\sound\Sound;
use function assert;
use function intdiv;
use function min;

final class PotionCauldron extends FillableCauldron{
	public const POTION_FILL_AMOUNT = 2;
	/** Most arrows tipped in one use, by the number of potion bottles in the cauldron (index). */
	private const ARROWS_PER_BOTTLES = [0, 16, 32, 64];

	private ?Item $potionItem = null;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		$this->potionItem = $tile instanceof TileCauldron ? $tile->getPotionItem() : null;

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileCauldron);
		$tile->setCustomWaterColor(null);
		$tile->setPotionItem($this->potionItem);
	}

	public function getPotionItem() : ?Item{ return $this->potionItem === null ? null : clone $this->potionItem; }

	/** @return $this */
	public function setPotionItem(?Item $potionItem) : self{
		if($potionItem !== null && !match($potionItem->getTypeId()){
			ItemTypeIds::POTION,
			ItemTypeIds::SPLASH_POTION,
			ItemTypeIds::LINGERING_POTION => true,
			default => false,
		}){
			throw new \InvalidArgumentException("Item must be a POTION, SPLASH_POTION or LINGERING_POTION");
		}
		$this->potionItem = $potionItem !== null ? (clone $potionItem)->setCount(1) : null;
		return $this;
	}

	public function getFillSound() : Sound{
		return new CauldronFillPotionSound();
	}

	public function getEmptySound() : Sound{
		return new CauldronEmptyPotionSound();
	}

	/**
	 * @param Item[] &$returnedItems
	 */
	protected function addFillLevelsOrMix(int $amount, Item $usedItem, Item $returnedItem, array &$returnedItems) : void{
		if($this->potionItem !== null && !$usedItem->equals($this->potionItem, true, false)){
			$this->mix($usedItem, $returnedItem, $returnedItems);
		}else{
			$this->addFillLevels($amount, $usedItem, $returnedItem, $returnedItems);
		}
	}

	/**
	 * Returns how many arrows a potion cauldron with the given fill level can tip at once, and how many fill levels
	 * tipping $arrows of them uses up (Bedrock: 16 arrows for a third of a cauldron, 32 for two thirds, 64 when full).
	 *
	 * @return int[]
	 * @phpstan-return array{int, int} [arrows tipped, fill levels used]
	 */
	private static function getArrowTipping(int $fillLevel, int $arrows) : array{
		$bottles = intdiv($fillLevel + 1, self::POTION_FILL_AMOUNT);
		if($bottles <= 0 || $arrows <= 0){
			return [0, 0];
		}
		$tipped = min($arrows, self::ARROWS_PER_BOTTLES[min($bottles, 3)]);
		$bottlesUsed = 1;
		while(self::ARROWS_PER_BOTTLES[$bottlesUsed] < $tipped){
			++$bottlesUsed;
		}
		return [$tipped, min($fillLevel, $bottlesUsed * self::POTION_FILL_AMOUNT)];
	}

	/**
	 * Arrows dipped in the potion become tipped arrows of its base potion (also for splash and lingering potions).
	 *
	 * @param Item[] &$returnedItems
	 */
	private function tipArrows(Item $arrows, array &$returnedItems) : void{
		$potion = $this->potionItem;
		if(!$potion instanceof Potion && !$potion instanceof SplashPotion){
			return;
		}
		[$tipped, $levelsUsed] = self::getArrowTipping($this->getFillLevel(), $arrows->getCount());
		if($tipped === 0){
			return;
		}
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this->withFillLevel($this->getFillLevel() - $levelsUsed));
		$world->addSound($this->position->add(0.5, 0.5, 0.5), $this->getEmptySound());

		$arrows->pop($tipped);
		$returnedItems[] = VanillaItems::TIPPED_ARROW()->setType($potion->getType())->setCount($tipped);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		match($item->getTypeId()){
			ItemTypeIds::LINGERING_POTION, ItemTypeIds::POTION, ItemTypeIds::SPLASH_POTION => $this->addFillLevelsOrMix(self::POTION_FILL_AMOUNT, $item, VanillaItems::GLASS_BOTTLE(), $returnedItems),
			ItemTypeIds::GLASS_BOTTLE => $this->potionItem === null ? null : $this->removeFillLevels(self::POTION_FILL_AMOUNT, $item, clone $this->potionItem, $returnedItems),
			ItemTypeIds::LAVA_BUCKET, ItemTypeIds::POWDER_SNOW_BUCKET, ItemTypeIds::WATER_BUCKET => $this->mix($item, VanillaItems::BUCKET(), $returnedItems),
			ItemTypeIds::ARROW => $this->tipArrows($item, $returnedItems),
			default => null
		};
		return true;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if($world->getBlock($this->position->up())->getTypeId() === BlockTypeIds::WATER){
			$cauldron = VanillaBlocks::WATER_CAULDRON()->setFillLevel(FillableCauldron::MAX_FILL_LEVEL);
			$world->setBlock($this->position, $cauldron);
			$world->addSound($this->position->add(0.5, 0.5, 0.5), $cauldron->getFillSound());
		}
	}
}
