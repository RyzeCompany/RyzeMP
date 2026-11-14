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

namespace pocketmine\command\defaults;

use pocketmine\block\tile\Container;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\selector\CommandTargets;
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\CommandItemParser;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParserException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use function array_keys;
use function array_slice;
use function count;
use function implode;
use function is_numeric;
use function method_exists;
use function strtolower;

/**
 * /replaceitem entity <target> <slotType> <slotId> [destroy|keep] <item> [amount] [data] [components] and
 * /replaceitem block <position> slot.container <slotId> [destroy|keep] <item> [amount] [data] [components], like
 * Bedrock. Slot types: slot.weapon.mainhand, slot.weapon.offhand, slot.armor.head/chest/legs/feet, slot.armor (0-3),
 * slot.hotbar (0-8), slot.inventory (0-26, the inventory above the hotbar) and slot.enderchest (0-26, players).
 * "keep" leaves an occupied slot alone.
 */
class ReplaceItemCommand extends VanillaCommand{
	public const SLOT_MAINHAND = "slot.weapon.mainhand";
	public const SLOT_OFFHAND = "slot.weapon.offhand";
	public const SLOT_HEAD = "slot.armor.head";
	public const SLOT_CHEST = "slot.armor.chest";
	public const SLOT_LEGS = "slot.armor.legs";
	public const SLOT_FEET = "slot.armor.feet";
	public const SLOT_ARMOR = "slot.armor";
	public const SLOT_HOTBAR = "slot.hotbar";
	public const SLOT_INVENTORY = "slot.inventory";
	public const SLOT_ENDERCHEST = "slot.enderchest";
	public const SLOT_CONTAINER = "slot.container";

	/** Number of slots of each entity slot type. */
	public const SLOT_COUNTS = [
		self::SLOT_MAINHAND => 1,
		self::SLOT_OFFHAND => 1,
		self::SLOT_HEAD => 1,
		self::SLOT_CHEST => 1,
		self::SLOT_LEGS => 1,
		self::SLOT_FEET => 1,
		self::SLOT_ARMOR => 4,
		self::SLOT_HOTBAR => 9,
		self::SLOT_INVENTORY => 27,
		self::SLOT_ENDERCHEST => 27,
	];

	public function __construct(){
		parent::__construct(
			"replaceitem",
			KnownTranslationFactory::pocketmine_command_replaceitem_description(),
			KnownTranslationFactory::pocketmine_command_replaceitem_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_REPLACEITEM);
	}

	public function getOverloads() : array{
		$entitySlots = CommandParameter::enum("slotType", "EntityEquipmentSlot", array_keys(self::SLOT_COUNTS));
		$mode = CommandParameter::enum("oldItemHandling", "ReplaceMode", ["destroy", "keep"]);
		$tail = [CommandParameter::item("itemName"), CommandParameter::int("amount", true), CommandParameter::int("data", true), CommandParameter::json("components", true)];
		$block = [CommandParameter::enum("block", "ReplaceItemBlock", ["block"]), CommandParameter::position("position"), CommandParameter::enum("slotType", "BlockEquipmentSlot", [self::SLOT_CONTAINER]), CommandParameter::int("slotId")];
		$entity = [CommandParameter::enum("entity", "ReplaceItemEntity", ["entity"]), CommandParameter::target("target"), $entitySlots, CommandParameter::int("slotId")];
		return [
			[...$block, ...$tail],
			[...$block, $mode, ...$tail],
			[...$entity, ...$tail],
			[...$entity, $mode, ...$tail],
		];
	}

	/**
	 * Slot type and slot of an entity argument, mapped to the inventory and the slot index in it (null inventory: a
	 * mob's hand), or null if the entity has no such slot.
	 *
	 * @phpstan-return array{Inventory|null, int}|null
	 */
	public static function resolveEntitySlot(Entity $entity, string $slotType, int $slot) : ?array{
		if($entity instanceof Human){
			return match($slotType){
				self::SLOT_MAINHAND => [$entity->getInventory(), $entity->getInventory()->getHeldItemIndex()],
				self::SLOT_OFFHAND => [$entity->getOffHandInventory(), 0],
				self::SLOT_HOTBAR => [$entity->getInventory(), $slot],
				self::SLOT_INVENTORY => [$entity->getInventory(), 9 + $slot],
				self::SLOT_ENDERCHEST => [$entity->getEnderInventory(), $slot],
				default => self::resolveArmorSlot($entity, $slotType, $slot)
			};
		}
		if($entity instanceof Living){
			return match($slotType){
				self::SLOT_MAINHAND, self::SLOT_OFFHAND => method_exists($entity, "setMainHandItem") ? [null, $slotType === self::SLOT_OFFHAND ? 1 : 0] : null,
				default => self::resolveArmorSlot($entity, $slotType, $slot)
			};
		}
		return null;
	}

	/**
	 * @phpstan-return array{Inventory, int}|null
	 */
	private static function resolveArmorSlot(Living $entity, string $slotType, int $slot) : ?array{
		$armor = $entity->getArmorInventory();
		return match($slotType){
			self::SLOT_HEAD => [$armor, ArmorInventory::SLOT_HEAD],
			self::SLOT_CHEST => [$armor, ArmorInventory::SLOT_CHEST],
			self::SLOT_LEGS => [$armor, ArmorInventory::SLOT_LEGS],
			self::SLOT_FEET => [$armor, ArmorInventory::SLOT_FEET],
			self::SLOT_ARMOR => [$armor, $slot],
			default => null
		};
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 5){
			throw new InvalidCommandSyntaxException();
		}
		$kind = strtolower($args[0]);
		if($kind === "entity"){
			$targetArg = $args[1];
			$slotType = strtolower($args[2]);
			if(!isset(self::SLOT_COUNTS[$slotType])){
				throw new InvalidCommandSyntaxException();
			}
			$slotArg = $args[3];
			$rest = array_slice($args, 4);
			$maxSlot = self::SLOT_COUNTS[$slotType] - 1;
		}elseif($kind === "block"){
			if(count($args) < 7 || strtolower($args[4]) !== self::SLOT_CONTAINER){
				throw new InvalidCommandSyntaxException();
			}
			$targetArg = null;
			$slotType = self::SLOT_CONTAINER;
			$slotArg = $args[5];
			$rest = array_slice($args, 6);
			$maxSlot = null;
		}else{
			throw new InvalidCommandSyntaxException();
		}
		if(!is_numeric($slotArg)){
			throw new InvalidCommandSyntaxException();
		}
		$slot = (int) $slotArg;

		$mode = "destroy";
		if(isset($rest[0]) && (strtolower($rest[0]) === "destroy" || strtolower($rest[0]) === "keep")){
			$mode = strtolower($rest[0]);
			$rest = array_slice($rest, 1);
		}
		$item = $this->parseItem($sender, $rest);
		if($item === null){
			return true;
		}

		if($targetArg === null){
			$this->replaceInBlock($sender, $args, $slot, $mode, $item);
			return true;
		}

		if($maxSlot !== null && ($slot < 0 || $slot > $maxSlot)){
			$sender->sendMessage(KnownTranslationFactory::commands_replaceitem_badSlotNumber((string) $slot, "0", (string) $maxSlot)->prefix(TextFormat::RED));
			return true;
		}
		foreach(CommandTargets::entities($sender, $targetArg) as $entity){
			$resolved = self::resolveEntitySlot($entity, $slotType, $slot);
			$name = TargetSelector::getEntityName($entity);
			if($resolved === null){
				$sender->sendMessage(KnownTranslationFactory::commands_replaceitem_failed($slotType, (string) $slot, (string) $item->getCount(), $item->getName())->prefix(TextFormat::RED));
				continue;
			}
			[$inventory, $index] = $resolved;
			if($inventory === null){
				//a mob's hands (HeldItemsTrait)
				if(!method_exists($entity, "getMainHandItem") || !method_exists($entity, "getOffHandItem") || !method_exists($entity, "setMainHandItem") || !method_exists($entity, "setOffHandItem")){
					continue;
				}
				$current = $index === 0 ? $entity->getMainHandItem() : $entity->getOffHandItem();
				if($mode === "keep" && $current instanceof Item && !$current->isNull()){
					$sender->sendMessage(KnownTranslationFactory::commands_replaceitem_failed($slotType, (string) $slot, (string) $item->getCount(), $item->getName())->prefix(TextFormat::RED));
					continue;
				}
				if($index === 0){
					$entity->setMainHandItem(clone $item);
				}else{
					$entity->setOffHandItem(clone $item);
				}
			}else{
				if($mode === "keep" && !$inventory->getItem($index)->isNull()){
					$sender->sendMessage(KnownTranslationFactory::commands_replaceitem_failed($slotType, (string) $slot, (string) $item->getCount(), $item->getName())->prefix(TextFormat::RED));
					continue;
				}
				$inventory->setItem($index, clone $item);
			}
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_replaceitem_success_entity($slotType, (string) $slot, $name, (string) $item->getCount(), $item->getName()));
		}
		return true;
	}

	/**
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	private function replaceInBlock(CommandSender $sender, array $args, int $slot, string $mode, Item $item) : void{
		$origin = self::getCommandOrigin($sender) ?? throw new InvalidCommandSyntaxException();
		$pos = CommandCoordinates::parsePosition($origin, $args[1], $args[2], $args[3])->floor();
		$world = $origin->getWorld();
		$tile = $world->isInWorld((int) $pos->x, (int) $pos->y, (int) $pos->z) ? $world->getTile($pos) : null;
		if(!$tile instanceof Container){
			$sender->sendMessage(KnownTranslationFactory::commands_replaceitem_noContainer("Pos(" . (int) $pos->x . "," . (int) $pos->y . "," . (int) $pos->z . ")")->prefix(TextFormat::RED));
			return;
		}
		$inventory = $tile->getInventory();
		$size = $inventory->getSize();
		if($slot < 0 || $slot >= $size){
			$sender->sendMessage(KnownTranslationFactory::commands_replaceitem_badSlotNumber((string) $slot, "0", (string) ($size - 1))->prefix(TextFormat::RED));
			return;
		}
		if($mode === "keep" && !$inventory->getItem($slot)->isNull()){
			$sender->sendMessage(KnownTranslationFactory::commands_replaceitem_failed(self::SLOT_CONTAINER, (string) $slot, (string) $item->getCount(), $item->getName())->prefix(TextFormat::RED));
			return;
		}
		$inventory->setItem($slot, $item);
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_replaceitem_success(self::SLOT_CONTAINER, (string) $slot, (string) $item->getCount(), $item->getName()));
	}

	/**
	 * <item> [amount] [data] [components]
	 *
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	private function parseItem(CommandSender $sender, array $args) : ?Item{
		if(!isset($args[0])){
			throw new InvalidCommandSyntaxException();
		}
		try{
			$item = CommandItemParser::parse($args[0]);
		}catch(LegacyStringToItemParserException){
			$sender->sendMessage(KnownTranslationFactory::commands_give_item_notFound($args[0])->prefix(TextFormat::RED));
			return null;
		}
		$count = 1;
		if(isset($args[1]) && is_numeric($args[1])){
			$count = $this->getBoundedInt($sender, $args[1], 1, 64);
			if($count === null){
				return null;
			}
		}
		$item->setCount($count);
		$tagsStart = isset($args[1]) && is_numeric($args[1]) ? 2 : 1;
		if(isset($args[2]) && $tagsStart === 2 && is_numeric($args[2])){
			$data = $this->getBoundedInt($sender, $args[2], 0, 32767);
			if($data === null){
				return null;
			}
			$variant = CommandItemParser::withData($item, $data);
			if($variant === null){
				$sender->sendMessage(KnownTranslationFactory::commands_give_item_notFound($args[0] . ":" . $args[2])->prefix(TextFormat::RED));
				return null;
			}
			$item = $variant;
			$tagsStart = 3;
		}
		if(isset($args[$tagsStart])){
			$error = CommandItemParser::applyTags($item, implode(" ", array_slice($args, $tagsStart)));
			if($error !== null){
				$sender->sendMessage(KnownTranslationFactory::commands_replaceitem_tagError($error)->prefix(TextFormat::RED));
				return null;
			}
		}
		return $item;
	}
}
