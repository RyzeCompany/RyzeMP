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

namespace pocketmine\loot;

use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\generator\vanilla\random\LegacyRandom;
use pocketmine\world\World;
use function method_exists;
use function random_int;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Everything a loot table may look at while rolling (vanilla's LootContext and LootParams): the random source, the
 * luck, and the optional parameters (origin, entities, tool, explosion...). Conditions that need a parameter that is
 * not set fail, like in vanilla.
 *
 * Checks that depend on things the loot engine does not model (entity predicates, damage sources, locations, block
 * states, dynamic drops) are delegated to the optional resolver closures; without a resolver they fail.
 */
final class LootContext{
	public float $luck = 0.0;
	public ?World $world = null;
	public ?Vector3 $origin = null;
	/** THIS_ENTITY: the entity that died, the player opening a container, the player brushing... */
	public ?Entity $thisEntity = null;
	/** ATTACKING_ENTITY: the entity whose attack killed $thisEntity (the shooter for projectiles) */
	public ?Entity $attackingEntity = null;
	/** DIRECT_ATTACKING_ENTITY: the entity that dealt the damage (the projectile for projectiles) */
	public ?Entity $directAttackingEntity = null;
	/** LAST_DAMAGE_PLAYER: set when a player took part in the kill (killed_by_player) */
	public ?Player $lastDamagePlayer = null;
	/** TOOL: the item used to break a block, brush, shear... */
	public ?LootStack $tool = null;
	/** EXPLOSION_RADIUS: set when the loot comes from a block destroyed by an explosion */
	public ?float $explosionRadius = null;

	/**
	 * Java block id and string properties of the BLOCK_STATE parameter (blocks broken), or null.
	 * @var string[]|null
	 * @phpstan-var array<string, string>|null
	 */
	public ?array $blockProperties = null;
	public ?string $blockId = null;

	/**
	 * minecraft:entity_properties: fn(string $target, array $predicate) : bool, $target being "this", "attacker",
	 * "direct_attacker" or "attacking_player".
	 * @phpstan-var (\Closure(string, mixed[]) : bool)|null
	 */
	public ?\Closure $entityPredicate = null;
	/**
	 * minecraft:damage_source_properties: fn(array $predicate) : bool
	 * @phpstan-var (\Closure(mixed[]) : bool)|null
	 */
	public ?\Closure $damageSourcePredicate = null;
	/**
	 * minecraft:location_check: fn(array $predicate, int $offsetX, int $offsetY, int $offsetZ) : bool. Without a resolver
	 * only "biomes" predicates are checked, with {@link $biomeAt}.
	 * @phpstan-var (\Closure(mixed[], int, int, int) : bool)|null
	 */
	public ?\Closure $locationPredicate = null;
	/**
	 * Java biome id at a position, for location checks: fn(int $x, int $y, int $z) : ?string
	 * @phpstan-var (\Closure(int, int, int) : ?string)|null
	 */
	public ?\Closure $biomeAt = null;
	/**
	 * minecraft:dynamic entries ("contents", "sherds"): fn(string $name, \Closure(LootStack) : void $output) : void
	 * @phpstan-var (\Closure(string, \Closure(LootStack) : void) : void)|null
	 */
	public ?\Closure $dynamicDrops = null;
	/**
	 * Enchantment level of a living entity's equipment (EnchantmentHelper.getEnchantmentLevel), used by looting-like
	 * functions: fn(Entity $entity, string $javaEnchantmentId) : int. Defaults to the held item of humans.
	 * @phpstan-var (\Closure(Entity, string) : int)|null
	 */
	public ?\Closure $entityEnchantmentLevel = null;

	/**
	 * Loot tables and predicates being evaluated (recursion guard, like vanilla's visited elements).
	 * @var true[]
	 * @phpstan-var array<string, true>
	 */
	public array $visited = [];

	public function __construct(
		public readonly LegacyRandom $random
	){}

	/**
	 * A context whose random is seeded like vanilla's LootContext.Builder.withOptionalRandomSeed(): a seed of 0 means
	 * "no seed" (a random one is used).
	 */
	public static function seeded(int $seed) : self{
		return new self(new LegacyRandom($seed !== 0 ? $seed : self::randomSeed()));
	}

	/**
	 * A context with a random seed.
	 */
	public static function create() : self{
		return new self(new LegacyRandom(self::randomSeed()));
	}

	public static function randomSeed() : int{
		return random_int(PHP_INT_MIN, PHP_INT_MAX);
	}

	/**
	 * Enchantment level of an entity's equipment.
	 */
	public function getEntityEnchantmentLevel(?Entity $entity, string $enchantment) : int{
		if($entity === null){
			return 0;
		}
		if($this->entityEnchantmentLevel !== null){
			return ($this->entityEnchantmentLevel)($entity, $enchantment);
		}
		if($entity instanceof Human){
			return LootItemConverter::enchantmentLevel($entity->getInventory()->getItemInHand(), $enchantment);
		}
		if(method_exists($entity, "getMainHandItem")){
			$item = $entity->getMainHandItem();
			if($item instanceof Item){
				return LootItemConverter::enchantmentLevel($item, $enchantment);
			}
		}
		return 0;
	}
}
