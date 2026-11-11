<?php

/*
 * Powder snow cauldron integration adapted from VanillaMine / PocketMine-MP,
 * LGPL-3.0-or-later. Uses the existing Bedrock cauldron_liquid state.
 */
declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\tile\Cauldron as TileCauldron;
use pocketmine\block\utils\BlockEventHelper;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityExtinguishEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use pocketmine\world\sound\CauldronEmptyWaterSound;
use pocketmine\world\sound\CauldronFillWaterSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\weather\Precipitation;
use function assert;

/** A real powder_snow cauldron blockstate, not a water-coloured visual fallback. */
final class PowderSnowCauldron extends FillableCauldron{
	public const SNOW_FILL_CHANCE = 0.10;

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileCauldron);
		$tile->setCustomWaterColor(null);
		$tile->setPotionItem(null);
	}

	/** Safe audio fallback until a dedicated multi-protocol powder snow sound is registered. */
	public function getFillSound() : Sound{ return new CauldronFillWaterSound(); }
	public function getEmptySound() : Sound{ return new CauldronEmptyWaterSound(); }

	public function onPrecipitation(Precipitation $precipitation) : void{
		if($precipitation !== Precipitation::SNOW || $this->getFillLevel() >= self::MAX_FILL_LEVEL ||
			Utils::getRandomFloat() >= self::SNOW_FILL_CHANCE
		){
			return;
		}
		$next = clone $this;
		$next->setFillLevel($this->getFillLevel() + 1);
		BlockEventHelper::form($this, $next, $this);
	}

	public function hasEntityCollision() : bool{ return true; }

	public function onEntityInside(Entity $entity) : bool{
		if($entity->isOnFire()){
			$entity->extinguish(EntityExtinguishEvent::CAUSE_POWDER_SNOW);
			$level = $this->getFillLevel() - WaterCauldron::ENTITY_EXTINGUISH_USE_AMOUNT;
			$this->position->getWorld()->setBlock($this->position, $level <= 0 ?
				VanillaBlocks::CAULDRON() :
				VanillaBlocks::WATER_CAULDRON()->setFillLevel($level));
			return false;
		}
		return true;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		match($item->getTypeId()){
			ItemTypeIds::BUCKET => $this->removeFillLevels(self::MAX_FILL_LEVEL, $item, VanillaItems::POWDER_SNOW_BUCKET(), $returnedItems),
			ItemTypeIds::POWDER_SNOW_BUCKET => $this->addFillLevels(self::MAX_FILL_LEVEL, $item, VanillaItems::BUCKET(), $returnedItems),
			ItemTypeIds::LAVA_BUCKET, ItemTypeIds::WATER_BUCKET => $this->mix($item, VanillaItems::BUCKET(), $returnedItems),
			ItemTypeIds::LINGERING_POTION, ItemTypeIds::POTION, ItemTypeIds::SPLASH_POTION => $this->mix($item, VanillaItems::GLASS_BOTTLE(), $returnedItems),
			default => null
		};
		return true;
	}
}
