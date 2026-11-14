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

namespace pocketmine\entity\monster;

use pocketmine\block\Block;
use pocketmine\block\Campfire;
use pocketmine\block\FlowerPot;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\ai\sensing\Sensing;
use pocketmine\item\Item;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\raid\VillageScanner;
use pocketmine\world\World;

/**
 * Piglin knowledge shared by piglins, hoglins and the world hooks: the gold they love, the blocks they guard, the
 * blocks piglins and hoglins are afraid of, and the anger at players who open containers or mine gold near them.
 */
final class PiglinAi{

	private function __construct(){
		//NOOP
	}

	/** Piglins within this distance of a player who opens a container or mines a guarded block get angry. */
	public const GUARD_RANGE = 16.0;

	/**
	 * @var true[]|null item type ID => true
	 * @phpstan-var array<int, true>|null
	 */
	private static ?array $lovedItems = null;
	/**
	 * @var true[]|null block type ID => true
	 * @phpstan-var array<int, true>|null
	 */
	private static ?array $guardedBlocks = null;
	/**
	 * @var true[]|null block type ID => true
	 * @phpstan-var array<int, true>|null
	 */
	private static ?array $piglinRepellents = null;
	/**
	 * @var true[]|null block type ID => true
	 * @phpstan-var array<int, true>|null
	 */
	private static ?array $hoglinRepellents = null;
	/** Piglins and piglin brutes alive in any world, so hoglins can skip looking for them when there are none. */
	private static int $loadedPiglins = 0;

	/** @internal */
	public static function onPiglinCreated() : void{
		++self::$loadedPiglins;
	}

	/** @internal */
	public static function onPiglinDisposed() : void{
		if(self::$loadedPiglins > 0){
			--self::$loadedPiglins;
		}
	}

	/**
	 * Whether any piglin or piglin brute exists (cheap early-out for scans looking for them).
	 * @internal
	 */
	public static function hasPiglins() : bool{
		return self::$loadedPiglins > 0;
	}

	/**
	 * Gold items piglins pick up and admire (Java tag piglin_loved, Bedrock shareables with admire: also the golden
	 * dandelion). Gold ingots are bartered, the rest kept.
	 */
	public static function isLovedItem(Item $item) : bool{
		if(self::$lovedItems === null){
			$items = [
				VanillaItems::GOLD_INGOT(), VanillaItems::RAW_GOLD(), VanillaItems::CLOCK(), VanillaItems::GOLDEN_CARROT(),
				VanillaItems::GLISTERING_MELON(), VanillaItems::GOLDEN_APPLE(), VanillaItems::ENCHANTED_GOLDEN_APPLE(),
				VanillaItems::GOLDEN_HELMET(), VanillaItems::GOLDEN_CHESTPLATE(), VanillaItems::GOLDEN_LEGGINGS(),
				VanillaItems::GOLDEN_BOOTS(), VanillaItems::GOLDEN_HORSE_ARMOR(), VanillaItems::GOLDEN_NAUTILUS_ARMOR(),
				VanillaItems::GOLDEN_SWORD(), VanillaItems::GOLDEN_PICKAXE(), VanillaItems::GOLDEN_SHOVEL(),
				VanillaItems::GOLDEN_AXE(), VanillaItems::GOLDEN_HOE(), VanillaItems::GOLDEN_SPEAR(),
				VanillaBlocks::GOLD()->asItem(), VanillaBlocks::RAW_GOLD()->asItem(), VanillaBlocks::GOLD_ORE()->asItem(),
				VanillaBlocks::DEEPSLATE_GOLD_ORE()->asItem(), VanillaBlocks::NETHER_GOLD_ORE()->asItem(),
				VanillaBlocks::GILDED_BLACKSTONE()->asItem(), VanillaBlocks::BELL()->asItem(),
				VanillaBlocks::WEIGHTED_PRESSURE_PLATE_LIGHT()->asItem(), VanillaBlocks::GOLDEN_DANDELION()->asItem(),
			];
			self::$lovedItems = [];
			foreach($items as $loved){
				self::$lovedItems[$loved->getTypeId()] = true;
			}
		}
		return isset(self::$lovedItems[$item->getTypeId()]);
	}

	/**
	 * Items piglins pick up and put straight into their inventory without admiring them (Bedrock shareables
	 * stored_in_inventory without admire: gold nuggets).
	 */
	public static function isStoredItem(Item $item) : bool{
		return $item->getTypeId() === ItemTypeIds::GOLD_NUGGET;
	}

	/**
	 * Food piglins pick up and eat (vanilla piglin_food).
	 */
	public static function isFood(Item $item) : bool{
		$id = $item->getTypeId();
		return $id === ItemTypeIds::RAW_PORKCHOP || $id === ItemTypeIds::COOKED_PORKCHOP;
	}

	/**
	 * @phpstan-param list<Block> $blocks
	 * @phpstan-return array<int, true>
	 */
	private static function typeSet(array $blocks) : array{
		$set = [];
		foreach($blocks as $block){
			$set[$block->getTypeId()] = true;
		}
		return $set;
	}

	/**
	 * Blocks piglins guard (Java tag guarded_by_piglins, Bedrock block_sensor: also the copper chests): breaking one
	 * angers the piglins around.
	 */
	public static function isGuardedBlock(Block $block) : bool{
		self::$guardedBlocks ??= self::typeSet([
			VanillaBlocks::GOLD(), VanillaBlocks::RAW_GOLD(), VanillaBlocks::GOLD_ORE(), VanillaBlocks::DEEPSLATE_GOLD_ORE(),
			VanillaBlocks::NETHER_GOLD_ORE(), VanillaBlocks::GILDED_BLACKSTONE(), VanillaBlocks::CHEST(),
			VanillaBlocks::TRAPPED_CHEST(), VanillaBlocks::ENDER_CHEST(), VanillaBlocks::BARREL(),
			VanillaBlocks::SHULKER_BOX(), VanillaBlocks::DYED_SHULKER_BOX(), VanillaBlocks::COPPER_CHEST(),
		]);
		return isset(self::$guardedBlocks[$block->getTypeId()]);
	}

	/**
	 * Blocks piglins avoid (Java tag piglin_repellents): soul fire, soul torches, soul lanterns and lit soul campfires.
	 *
	 * @phpstan-return array<int, true>
	 */
	public static function getPiglinRepellents() : array{
		return self::$piglinRepellents ??= self::typeSet([
			VanillaBlocks::SOUL_FIRE(), VanillaBlocks::SOUL_TORCH(), VanillaBlocks::SOUL_LANTERN(), VanillaBlocks::SOUL_CAMPFIRE(),
		]);
	}

	/**
	 * Blocks hoglins avoid (hoglin.json avoid_block): warped fungus, nether portals and respawn anchors (not potted
	 * warped fungus, unlike the Java tag hoglin_repellents).
	 *
	 * @phpstan-return array<int, true>
	 */
	public static function getHoglinRepellents() : array{
		return self::$hoglinRepellents ??= self::typeSet([
			VanillaBlocks::WARPED_FUNGUS(), VanillaBlocks::NETHER_PORTAL(), VanillaBlocks::RESPAWN_ANCHOR(),
		]);
	}

	/**
	 * Returns the nearest block of the given types within $range blocks horizontally and $height vertically.
	 *
	 * @phpstan-param array<int, true> $types
	 * @phpstan-param (\Closure(Block) : bool)|null $filter
	 */
	public static function findNearestBlock(World $world, Vector3 $center, int $range, int $height, array $types, ?\Closure $filter = null) : ?Vector3{
		$cx = $center->getFloorX();
		$cy = $center->getFloorY();
		$cz = $center->getFloorZ();
		$best = null;
		$bestDist = null;
		foreach(VillageScanner::findBlocks($world, $cx - $range, $cy - $height, $cz - $range, $cx + $range, $cy + $height, $cz + $range, $types) as [$x, $y, $z, ]){
			$d = ($x - $cx) ** 2 + ($y - $cy) ** 2 + ($z - $cz) ** 2;
			if($bestDist !== null && $d >= $bestDist){
				continue;
			}
			if($filter !== null && !$filter($world->getBlockAt($x, $y, $z))){
				continue;
			}
			$best = new Vector3($x, $y, $z);
			$bestDist = $d;
		}
		return $best;
	}

	/**
	 * Whether the block really repels piglins: soul campfires only when lit.
	 */
	public static function isActivePiglinRepellent(Block $block) : bool{
		return !$block instanceof Campfire || $block->isLit();
	}

	/**
	 * Whether the block really repels hoglins: flower pots only with a warped fungus in them.
	 */
	public static function isActiveHoglinRepellent(Block $block) : bool{
		return !$block instanceof FlowerPot || $block->getPlant()?->getTypeId() === VanillaBlocks::WARPED_FUNGUS()->getTypeId();
	}

	/**
	 * Called when a player broke a block: breaking a guarded block angers the piglins around (Bedrock block_sensor,
	 * no line of sight needed).
	 */
	public static function onBlockBroken(Player $player, Block $block) : int{
		if(!self::isGuardedBlock($block)){
			return 0;
		}
		return self::angerNearbyPiglins($player, false);
	}

	/**
	 * Called when a player opened a chest, trapped chest, ender chest, barrel or shulker box: the piglins around that
	 * see the player get angry.
	 */
	public static function onContainerOpened(Player $player) : int{
		return self::angerNearbyPiglins($player, true);
	}

	/**
	 * Makes the piglins within 16 blocks of the player angry at it (gold armor doesn't help). Piglins busy admiring
	 * gold, babies and, if asked, those which can't see the player are left alone. Returns how many got angry.
	 */
	public static function angerNearbyPiglins(Player $player, bool $requireLineOfSight) : int{
		if(!Sensing::isTargetablePlayer($player)){
			return 0;
		}
		$pos = $player->getPosition();
		$r = self::GUARD_RANGE;
		$angered = 0;
		foreach($player->getWorld()->getNearbyEntities(new AxisAlignedBB($pos->x - $r, $pos->y - $r, $pos->z - $r, $pos->x + $r, $pos->y + $r, $pos->z + $r)) as $entity){
			if(!$entity instanceof Piglin || !$entity->isAlive() || $entity->isBaby() || $entity->isAdmiring() ||
				$entity->getPosition()->distanceSquared($pos) > $r * $r ||
				($requireLineOfSight && !Sensing::hasLineOfSight($entity, $player))
			){
				continue;
			}
			$entity->setAngryAt($player, Piglin::ANGER_TICKS);
			++$angered;
		}
		return $angered;
	}
}
