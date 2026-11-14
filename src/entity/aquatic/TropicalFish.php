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

namespace pocketmine\entity\aquatic;

use pocketmine\entity\ai\goal\FlockingGoal;
use pocketmine\entity\BucketableMob;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Mob;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use function max;
use function min;
use function mt_rand;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Bedrock values (entities/tropicalfish.json): 3 health, 0.4x0.4 at scale 1.3, underwater speed 0.12.
 * Appearance: body shape (VARIANT 0-1), pattern (MARK_VARIANT 0-5), base colour (COLOR) and pattern colour
 * (COLOR_2), dye colour IDs 0-14 except black. A new fish (spawn egg, /summon, the small natural herds) gets a fully
 * random look (Bedrock entity_spawned); the big natural herds (spawn_rules/tropicalfish.json, 3-5 fish) are one of
 * the 22 named varieties for the whole herd (minecraft:become_<variety>, see {@link TropicalFish::applyVarietyEvent()}).
 * Fish only school with fish of the same look (flocking match_variants).
 * Loot (loot_tables/entities/tropicalfish.json): 1 tropical fish, 25% bone.
 */
class TropicalFish extends AbstractFish{
	/**
	 * Named varieties: [base colour, pattern colour, pattern, shape], from the behaviour pack component groups.
	 */
	public const PRESETS = [
		"anemone" => [1, 7, 1, 1], "clownfish" => [1, 0, 0, 0], "black_tang" => [7, 7, 0, 1], "cc_betta" => [6, 3, 5, 0],
		"dog_fish" => [10, 4, 3, 1], "blue_dory" => [7, 3, 1, 0], "butterfly_fish" => [0, 7, 4, 0], "cichlid" => [11, 7, 1, 0],
		"e_red_snapper" => [0, 14, 5, 1], "goat_fish" => [0, 4, 5, 0], "moorish_idol" => [0, 7, 2, 1], "ornate_butterfly" => [0, 1, 5, 1],
		"red_snapper" => [14, 0, 3, 1], "parrot_fish" => [9, 6, 3, 0], "queen_angel_fish" => [5, 3, 4, 0], "red_cichlid" => [14, 0, 4, 1],
		"red_lipped_benny" => [7, 14, 2, 0], "threadfin" => [0, 4, 0, 1], "yellow_tang" => [4, 4, 1, 1], "tomato_clown" => [14, 0, 1, 0],
		"triggerfish" => [7, 0, 1, 0], "yellow_tail_parrot" => [9, 4, 3, 0],
	];

	private const VARIETY_EVENT_PREFIX = "minecraft:become_";

	private int $shape = 0;
	private int $pattern = 0;
	private int $baseColor = 0;
	private int $patternColor = 0;

	public static function getNetworkTypeId() : string{ return EntityIds::TROPICALFISH; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(0.4, 0.4, 0.26);
	}

	public function getName() : string{
		return "Tropical Fish";
	}

	protected function getSwimSpeed() : float{
		return 0.12;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->setScale(1.3);
		if($nbt->getTag(BucketableMob::BUCKET_TAG_VARIANT) !== null){
			$this->readAppearance($nbt);
		}else{
			$this->randomizeAppearance();
		}
	}

	/**
	 * Gives the fish a fully random look (Bedrock entity_spawned: shape, pattern and both colours).
	 */
	public function randomizeAppearance() : void{
		$this->shape = mt_rand(0, 1);
		$this->pattern = mt_rand(0, 5);
		$this->baseColor = mt_rand(0, 14);
		$this->patternColor = mt_rand(0, 14);
		$this->networkPropertiesDirty = true;
	}

	/**
	 * Gives the fish one of the named varieties ({@link TropicalFish::PRESETS} key). Returns false for an unknown name.
	 */
	public function setVariety(string $name) : bool{
		$preset = self::PRESETS[$name] ?? null;
		if($preset === null){
			return false;
		}
		[$this->baseColor, $this->patternColor, $this->pattern, $this->shape] = $preset;
		$this->networkPropertiesDirty = true;
		return true;
	}

	/**
	 * Applies a spawn rule herd event minecraft:become_<variety> (the behaviour pack spells anemone "anenonme").
	 * Returns false if the event is not a variety event.
	 */
	public function applyVarietyEvent(string $event) : bool{
		if(!str_starts_with($event, self::VARIETY_EVENT_PREFIX)){
			return false;
		}
		$name = substr($event, strlen(self::VARIETY_EVENT_PREFIX));
		return $this->setVariety($name === "anenonme" ? "anemone" : $name);
	}

	/**
	 * Returns whether the fish looks exactly like the other one (they school together, flocking match_variants).
	 */
	public function hasSameLook(TropicalFish $other) : bool{
		return $this->shape === $other->shape && $this->pattern === $other->pattern && $this->baseColor === $other->baseColor && $this->patternColor === $other->patternColor;
	}

	protected function createFlockingGoal() : FlockingGoal{
		//tropicalfish.json flocking: cohesion threshold 1.5, match_variants
		return new FlockingGoal($this, 3.0, 4, 8, 0.1, 1.5, true, fn(Mob $other) => $other instanceof TropicalFish && $this->hasSameLook($other));
	}

	public function setAppearance(int $shape, int $pattern, int $baseColor, int $patternColor) : void{
		$this->shape = $shape & 1;
		$this->pattern = max(0, min(5, $pattern));
		$this->baseColor = max(0, min(15, $baseColor));
		$this->patternColor = max(0, min(15, $patternColor));
		$this->networkPropertiesDirty = true;
	}

	public function getShape() : int{ return $this->shape; }

	public function getPattern() : int{ return $this->pattern; }

	public function getBaseColor() : int{ return $this->baseColor; }

	public function getPatternColor() : int{ return $this->patternColor; }

	private function readAppearance(CompoundTag $tag) : void{
		$this->setAppearance(
			$tag->getInt(BucketableMob::BUCKET_TAG_VARIANT, 0),
			$tag->getInt(BucketableMob::BUCKET_TAG_MARK_VARIANT, 0),
			$tag->getByte(BucketableMob::BUCKET_TAG_COLOR, 0),
			$tag->getByte(BucketableMob::BUCKET_TAG_COLOR2, 0)
		);
	}

	private function writeAppearance(CompoundTag $tag) : void{
		$tag->setInt(BucketableMob::BUCKET_TAG_VARIANT, $this->shape);
		$tag->setInt(BucketableMob::BUCKET_TAG_MARK_VARIANT, $this->pattern);
		$tag->setByte(BucketableMob::BUCKET_TAG_COLOR, $this->baseColor);
		$tag->setByte(BucketableMob::BUCKET_TAG_COLOR2, $this->patternColor);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$this->writeAppearance($nbt);
		return $nbt;
	}

	protected function writeBucketData(CompoundTag $tag) : void{
		parent::writeBucketData($tag);
		$this->writeAppearance($tag);
	}

	public function applyBucketData(CompoundTag $tag) : void{
		parent::applyBucketData($tag);
		if($tag->getTag(BucketableMob::BUCKET_TAG_VARIANT) !== null){
			$this->readAppearance($tag);
		}
	}

	protected function getBucketItem() : Item{
		return VanillaItems::TROPICAL_FISH_BUCKET();
	}

	protected function getFishDrops() : array{
		return [VanillaItems::CLOWNFISH()];
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);
		$properties->setInt(EntityMetadataProperties::VARIANT, $this->shape);
		$properties->setInt(EntityMetadataProperties::MARK_VARIANT, $this->pattern);
		$properties->setByte(EntityMetadataProperties::COLOR, $this->baseColor);
		$properties->setByte(EntityMetadataProperties::COLOR_2, $this->patternColor);
	}
}
