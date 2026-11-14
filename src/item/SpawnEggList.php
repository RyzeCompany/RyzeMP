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

namespace pocketmine\item;

/**
 * Spawn eggs registered as {@link GenericSpawnEgg} items (correct ID, icon and name). Using one spawns the entity
 * registered for its mob in {@link \pocketmine\entity\EntitySpawnRegistry} (cow, pig, sheep, chicken, creeper,
 * skeleton, spider...), or does nothing if PocketMine-MP has no entity for it; they always set the mob of a monster
 * spawner.
 *
 * Keys are the item name without the "minecraft:" prefix and the "_spawn_egg" suffix (they are also the
 * {@link VanillaItems} member names with "_spawn_egg" appended). Values are the Bedrock entity identifier and the
 * display name of the mob.
 *
 * The zombie, squid and villager spawn eggs are not listed here: they have their own SpawnEgg classes.
 * The agent and NPC spawn eggs are Education Edition only and are not registered.
 *
 * @internal
 */
final class SpawnEggList{

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-var array<string, array{string, string}>
	 */
	public const EGGS = [
		"allay" => ["minecraft:allay", "Allay"],
		"armadillo" => ["minecraft:armadillo", "Armadillo"],
		"axolotl" => ["minecraft:axolotl", "Axolotl"],
		"bat" => ["minecraft:bat", "Bat"],
		"bee" => ["minecraft:bee", "Bee"],
		"blaze" => ["minecraft:blaze", "Blaze"],
		"bogged" => ["minecraft:bogged", "Bogged"],
		"breeze" => ["minecraft:breeze", "Breeze"],
		"camel" => ["minecraft:camel", "Camel"],
		"camel_husk" => ["minecraft:camel_husk", "Camel Husk"],
		"cat" => ["minecraft:cat", "Cat"],
		"cave_spider" => ["minecraft:cave_spider", "Cave Spider"],
		"chicken" => ["minecraft:chicken", "Chicken"],
		"cod" => ["minecraft:cod", "Cod"],
		"copper_golem" => ["minecraft:copper_golem", "Copper Golem"],
		"cow" => ["minecraft:cow", "Cow"],
		"creaking" => ["minecraft:creaking", "Creaking"],
		"creeper" => ["minecraft:creeper", "Creeper"],
		"dolphin" => ["minecraft:dolphin", "Dolphin"],
		"donkey" => ["minecraft:donkey", "Donkey"],
		"drowned" => ["minecraft:drowned", "Drowned"],
		"elder_guardian" => ["minecraft:elder_guardian", "Elder Guardian"],
		"ender_dragon" => ["minecraft:ender_dragon", "Ender Dragon"],
		"enderman" => ["minecraft:enderman", "Enderman"],
		"endermite" => ["minecraft:endermite", "Endermite"],
		"evoker" => ["minecraft:evocation_illager", "Evoker"],
		"fox" => ["minecraft:fox", "Fox"],
		"frog" => ["minecraft:frog", "Frog"],
		"ghast" => ["minecraft:ghast", "Ghast"],
		"glow_squid" => ["minecraft:glow_squid", "Glow Squid"],
		"goat" => ["minecraft:goat", "Goat"],
		"guardian" => ["minecraft:guardian", "Guardian"],
		"happy_ghast" => ["minecraft:happy_ghast", "Happy Ghast"],
		"hoglin" => ["minecraft:hoglin", "Hoglin"],
		"horse" => ["minecraft:horse", "Horse"],
		"husk" => ["minecraft:husk", "Husk"],
		"iron_golem" => ["minecraft:iron_golem", "Iron Golem"],
		"llama" => ["minecraft:llama", "Llama"],
		"magma_cube" => ["minecraft:magma_cube", "Magma Cube"],
		"mooshroom" => ["minecraft:mooshroom", "Mooshroom"],
		"mule" => ["minecraft:mule", "Mule"],
		"nautilus" => ["minecraft:nautilus", "Nautilus"],
		"ocelot" => ["minecraft:ocelot", "Ocelot"],
		"panda" => ["minecraft:panda", "Panda"],
		"parched" => ["minecraft:parched", "Parched"],
		"parrot" => ["minecraft:parrot", "Parrot"],
		"phantom" => ["minecraft:phantom", "Phantom"],
		"pig" => ["minecraft:pig", "Pig"],
		"piglin" => ["minecraft:piglin", "Piglin"],
		"piglin_brute" => ["minecraft:piglin_brute", "Piglin Brute"],
		"pillager" => ["minecraft:pillager", "Pillager"],
		"polar_bear" => ["minecraft:polar_bear", "Polar Bear"],
		"pufferfish" => ["minecraft:pufferfish", "Pufferfish"],
		"rabbit" => ["minecraft:rabbit", "Rabbit"],
		"ravager" => ["minecraft:ravager", "Ravager"],
		"salmon" => ["minecraft:salmon", "Salmon"],
		"sheep" => ["minecraft:sheep", "Sheep"],
		"shulker" => ["minecraft:shulker", "Shulker"],
		"silverfish" => ["minecraft:silverfish", "Silverfish"],
		"skeleton" => ["minecraft:skeleton", "Skeleton"],
		"skeleton_horse" => ["minecraft:skeleton_horse", "Skeleton Horse"],
		"slime" => ["minecraft:slime", "Slime"],
		"sniffer" => ["minecraft:sniffer", "Sniffer"],
		"snow_golem" => ["minecraft:snow_golem", "Snow Golem"],
		"spider" => ["minecraft:spider", "Spider"],
		"stray" => ["minecraft:stray", "Stray"],
		"strider" => ["minecraft:strider", "Strider"],
		"sulfur_cube" => ["minecraft:sulfur_cube", "Sulfur Cube"],
		"tadpole" => ["minecraft:tadpole", "Tadpole"],
		"trader_llama" => ["minecraft:trader_llama", "Trader Llama"],
		"tropical_fish" => ["minecraft:tropicalfish", "Tropical Fish"],
		"turtle" => ["minecraft:turtle", "Turtle"],
		"vex" => ["minecraft:vex", "Vex"],
		"vindicator" => ["minecraft:vindicator", "Vindicator"],
		"wandering_trader" => ["minecraft:wandering_trader", "Wandering Trader"],
		"warden" => ["minecraft:warden", "Warden"],
		"witch" => ["minecraft:witch", "Witch"],
		"wither" => ["minecraft:wither", "Wither"],
		"wither_skeleton" => ["minecraft:wither_skeleton", "Wither Skeleton"],
		"wolf" => ["minecraft:wolf", "Wolf"],
		"zoglin" => ["minecraft:zoglin", "Zoglin"],
		"zombie_horse" => ["minecraft:zombie_horse", "Zombie Horse"],
		"zombie_nautilus" => ["minecraft:zombie_nautilus", "Zombie Nautilus"],
		"zombie_pigman" => ["minecraft:zombie_pigman", "Zombified Piglin"],
		"zombie_villager" => ["minecraft:zombie_villager_v2", "Zombie Villager"],
	];
}
