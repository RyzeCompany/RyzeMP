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

namespace pocketmine\inventory\transaction;

use pocketmine\block\Anvil;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Planks;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Armor;
use pocketmine\item\Durable;
use pocketmine\item\Elytra;
use pocketmine\item\enchantment\AvailableEnchantmentRegistry;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\Rarity;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Mace;
use pocketmine\item\Shield;
use pocketmine\item\TieredTool;
use pocketmine\item\ToolTier;
use pocketmine\item\VanillaArmorMaterials;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\sound\AnvilBreakSound;
use pocketmine\world\sound\AnvilUseSound;
use function count;
use function intdiv;
use function max;
use function mb_substr;
use function min;
use function mt_rand;
use function trim;

/**
 * Repairing, combining and renaming in an anvil (vanilla AnvilMenu.createResult):
 * - repair with the material of the item: each material item restores a quarter of the durability (1 level each);
 * - combine with the same item (durability of both plus 12%, 2 levels) and/or an enchanted book: enchantments are
 *   merged (same levels go one up, up to the maximum), each costing its anvil cost (1, 2, 4 or 8 by rarity, halved for
 *   books) times the level; incompatible ones cost 1 level and are dropped;
 * - rename (1 level; renaming alone never costs more than 39 levels).
 * The prior work penalty (RepairCost tag) of both items is added and doubles plus one after each use. Results of 40
 * levels or more are "too expensive" outside creative. The anvil has a 12% chance to get damaged per use.
 */
class AnvilTransaction extends InventoryTransaction{
	public const TAG_REPAIR_COST = "RepairCost";
	public const TOO_EXPENSIVE = 40;
	public const MAX_NAME_LENGTH = 50;

	public function __construct(
		Player $source,
		private readonly Position $anvilPosition,
		private readonly AnvilResult $expected,
		array $actions = []
	){
		parent::__construct($source, $actions);
	}

	public function getExpectedResult() : AnvilResult{
		return $this->expected;
	}

	public static function getRepairCost(Item $item) : int{
		return $item->getNamedTag()->getInt(self::TAG_REPAIR_COST, 0);
	}

	/**
	 * Anvil cost of an enchantment per level (vanilla: 1, 2, 4 or 8 for common, uncommon, rare and very rare ones).
	 */
	public static function getAnvilCost(Enchantment $enchantment) : int{
		return match($enchantment->getRarity()){
			Rarity::COMMON => 1,
			Rarity::UNCOMMON => 2,
			Rarity::RARE => 4,
			default => 8
		};
	}

	/**
	 * Whether the material repairs the item in an anvil (vanilla repairable tags).
	 */
	public static function isValidRepairMaterial(Item $item, Item $material) : bool{
		if($item instanceof Elytra || $item instanceof Mace || $item instanceof Shield){
			return $item->isValidRepairMaterial($material);
		}
		$typeId = $material->getTypeId();
		if($item instanceof TieredTool){
			return match($item->getTier()){
				ToolTier::WOOD => $material instanceof ItemBlock && $material->getBlock() instanceof Planks,
				ToolTier::STONE => $material instanceof ItemBlock && match($material->getBlock()->getTypeId()){
					BlockTypeIds::COBBLESTONE, BlockTypeIds::BLACKSTONE, BlockTypeIds::COBBLED_DEEPSLATE => true,
					default => false
				},
				ToolTier::COPPER => $typeId === ItemTypeIds::COPPER_INGOT,
				ToolTier::IRON => $typeId === ItemTypeIds::IRON_INGOT,
				ToolTier::GOLD => $typeId === ItemTypeIds::GOLD_INGOT,
				ToolTier::DIAMOND => $typeId === ItemTypeIds::DIAMOND,
				ToolTier::NETHERITE => $typeId === ItemTypeIds::NETHERITE_INGOT
			};
		}
		if($item instanceof Armor){
			return match($item->getMaterial()){
				VanillaArmorMaterials::LEATHER() => $typeId === ItemTypeIds::LEATHER,
				VanillaArmorMaterials::CHAINMAIL(), VanillaArmorMaterials::IRON() => $typeId === ItemTypeIds::IRON_INGOT,
				VanillaArmorMaterials::COPPER() => $typeId === ItemTypeIds::COPPER_INGOT,
				VanillaArmorMaterials::GOLD() => $typeId === ItemTypeIds::GOLD_INGOT,
				VanillaArmorMaterials::DIAMOND() => $typeId === ItemTypeIds::DIAMOND,
				VanillaArmorMaterials::NETHERITE() => $typeId === ItemTypeIds::NETHERITE_INGOT,
				VanillaArmorMaterials::TURTLE() => $typeId === ItemTypeIds::SCUTE,
				default => false
			};
		}
		return false;
	}

	/**
	 * Computes the anvil result, or null if nothing can be made.
	 *
	 * @param string|null $newName the name typed in the anvil, null when it was not changed ("" removes a custom name)
	 */
	public static function calculateResult(Item $input, ?Item $material, ?string $newName, bool $creative) : ?AnvilResult{
		if($input->isNull()){
			return null;
		}
		if($material !== null && $material->isNull()){
			$material = null;
		}
		$result = clone $input;
		$cost = 0;
		$renameCost = 0;
		$materialUsed = 0;
		$tax = self::getRepairCost($input) + ($material !== null ? self::getRepairCost($material) : 0);

		if($material !== null){
			$isBook = $material->getTypeId() === ItemTypeIds::ENCHANTED_BOOK;
			if($result instanceof Durable && self::isValidRepairMaterial($input, $material)){
				$max = $result->getMaxDurability();
				$repair = min($result->getDamage(), intdiv($max, 4));
				if($repair <= 0){
					return null;
				}
				for(; $repair > 0 && $materialUsed < $material->getCount(); ++$materialUsed){
					$result->setDamage($result->getDamage() - $repair);
					++$cost;
					$repair = min($result->getDamage(), intdiv($max, 4));
				}
			}else{
				if(!$isBook && ($result->getTypeId() !== $material->getTypeId() || !$result instanceof Durable)){
					return null;
				}
				if($result instanceof Durable && $input instanceof Durable && $material instanceof Durable && !$isBook){
					$max = $result->getMaxDurability();
					$remaining = ($max - $input->getDamage()) + ($max - $material->getDamage()) + intdiv($max * 12, 100);
					$damage = max(0, $max - $remaining);
					if($damage < $result->getDamage()){
						$result->setDamage($damage);
						$cost += 2;
					}
				}
				$materialUsed = 1;
				$applied = false;
				$rejected = false;
				$registry = AvailableEnchantmentRegistry::getInstance();
				foreach($material->getEnchantments() as $instance){
					$type = $instance->getType();
					$current = $result->getEnchantmentLevel($type);
					$level = $current === $instance->getLevel() ? $current + 1 : max($current, $instance->getLevel());
					$accepted = $creative || $input->getTypeId() === ItemTypeIds::ENCHANTED_BOOK || $registry->isAvailableForItem($type, $input);
					foreach($result->getEnchantments() as $other){
						if($other->getType() !== $type && !$type->isCompatibleWith($other->getType())){
							$accepted = false;
							++$cost;
						}
					}
					if(!$accepted){
						$rejected = true;
						continue;
					}
					$applied = true;
					$level = min($level, $type->getMaxLevel());
					$result->addEnchantment(new EnchantmentInstance($type, $level));
					$enchantmentCost = self::getAnvilCost($type);
					if($isBook){
						$enchantmentCost = max(1, intdiv($enchantmentCost, 2));
					}
					$cost += $enchantmentCost * $level;
					if($input->getCount() > 1){
						$cost = self::TOO_EXPENSIVE;
					}
				}
				if($rejected && !$applied){
					return null;
				}
			}
		}

		if($newName !== null){
			$newName = mb_substr($newName, 0, self::MAX_NAME_LENGTH);
			if(trim($newName) === ""){
				if($input->hasCustomName()){
					$renameCost = 1;
					$result->clearCustomName();
				}
			}elseif($newName !== $input->getName()){
				$renameCost = 1;
				$result->setCustomName($newName);
			}
			$cost += $renameCost;
		}

		if($cost <= 0){
			return null;
		}
		$total = $tax + $cost;
		if($renameCost === $cost && $total >= self::TOO_EXPENSIVE){
			$total = self::TOO_EXPENSIVE - 1;
		}
		if($total >= self::TOO_EXPENSIVE && !$creative){
			return null;
		}

		$repairCost = self::getRepairCost($result);
		if($material !== null && $repairCost < self::getRepairCost($material)){
			$repairCost = self::getRepairCost($material);
		}
		if($renameCost !== $cost){
			$repairCost = $repairCost * 2 + 1;
		}
		$nbt = $result->getNamedTag();
		$nbt->setInt(self::TAG_REPAIR_COST, $repairCost);
		$result->setNamedTag($nbt);
		$result->setCount(1);

		return new AnvilResult($result, $total, $materialUsed);
	}

	public function validate() : void{
		if(count($this->actions) < 1){
			throw new TransactionValidationException("Transaction must have at least one action to be executable");
		}
		$inputs = [];
		$outputs = [];
		$this->matchItems($outputs, $inputs);
		if(count($outputs) !== 1 || !$outputs[0]->equalsExact($this->expected->getResult())){
			throw new TransactionValidationException("Invalid anvil output");
		}
		$consumed = 0;
		foreach($inputs as $input){
			$consumed += $input->getCount();
		}
		if($consumed !== 1 + $this->expected->getMaterialUsed()){
			throw new TransactionValidationException("Expected the anvil to use 1 item and " . $this->expected->getMaterialUsed() . " material items, got $consumed items");
		}
		$xp = $this->source->getXpManager();
		if($this->source->hasFiniteResources() && $xp->getXpLevel() < $this->expected->getXpCost()){
			throw new TransactionValidationException("Not enough experience levels (need " . $this->expected->getXpCost() . ")");
		}
	}

	public function execute() : void{
		parent::execute();

		if($this->source->hasFiniteResources()){
			$this->source->getXpManager()->subtractXpLevels($this->expected->getXpCost());
		}
		$world = $this->anvilPosition->getWorld();
		$block = $world->getBlock($this->anvilPosition);
		if($this->source->hasFiniteResources() && $block instanceof Anvil && mt_rand(0, 99) < 12){
			if($block->getDamage() >= Anvil::VERY_DAMAGED){
				$world->setBlock($this->anvilPosition, VanillaBlocks::AIR());
				$world->addSound($this->anvilPosition, new AnvilBreakSound());
				return;
			}
			$world->setBlock($this->anvilPosition, $block->setDamage($block->getDamage() + 1));
		}
		$world->addSound($this->anvilPosition, new AnvilUseSound());
	}
}
