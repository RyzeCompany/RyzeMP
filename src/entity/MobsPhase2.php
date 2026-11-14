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

namespace pocketmine\entity;

use pocketmine\entity\animal\Allay;
use pocketmine\entity\animal\Armadillo;
use pocketmine\entity\animal\Axolotl;
use pocketmine\entity\animal\Bat;
use pocketmine\entity\animal\Bee;
use pocketmine\entity\animal\Camel;
use pocketmine\entity\animal\Cat;
use pocketmine\entity\animal\Donkey;
use pocketmine\entity\animal\Fox;
use pocketmine\entity\animal\Frog;
use pocketmine\entity\animal\Goat;
use pocketmine\entity\animal\HappyGhast;
use pocketmine\entity\animal\Horse;
use pocketmine\entity\animal\Llama;
use pocketmine\entity\animal\Mooshroom;
use pocketmine\entity\animal\Mule;
use pocketmine\entity\animal\Ocelot;
use pocketmine\entity\animal\Panda;
use pocketmine\entity\animal\Parrot;
use pocketmine\entity\animal\PolarBear;
use pocketmine\entity\animal\Rabbit;
use pocketmine\entity\animal\SkeletonHorse;
use pocketmine\entity\animal\Sniffer;
use pocketmine\entity\animal\TraderLlama;
use pocketmine\entity\animal\Turtle;
use pocketmine\entity\animal\Wolf;
use pocketmine\entity\animal\ZombieHorse;
use pocketmine\entity\aquatic\Cod;
use pocketmine\entity\aquatic\Dolphin;
use pocketmine\entity\aquatic\GlowSquid;
use pocketmine\entity\aquatic\Nautilus;
use pocketmine\entity\aquatic\Pufferfish;
use pocketmine\entity\aquatic\Salmon;
use pocketmine\entity\aquatic\Tadpole;
use pocketmine\entity\aquatic\TropicalFish;
use pocketmine\entity\EntityDataHelper as Helper;
use pocketmine\entity\golem\CopperGolem;
use pocketmine\entity\golem\IronGolem;
use pocketmine\entity\golem\SnowGolem;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\npc\WanderingTrader;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;

/**
 * Registration of the mobs added in phase 2 (passive, neutral and aquatic overworld mobs) in {@link EntityFactory}
 * (saving with chunks) and {@link EntitySpawnRegistry} (spawn eggs, buckets), kept apart from the other phases.
 *
 * @internal
 */
final class MobsPhase2{

	private function __construct(){
		//NOOP
	}

	/**
	 * Mob classes, PocketMine save names and constructors.
	 *
	 * @return array[]
	 * @phpstan-return list<array{class-string<Entity>, string, \Closure(Location, ?CompoundTag) : Entity}>
	 */
	public static function getMobs() : array{
		return [
			[Allay::class, "Allay", fn(Location $l, ?CompoundTag $nbt) : Entity => new Allay($l, $nbt)],
			[Armadillo::class, "Armadillo", fn(Location $l, ?CompoundTag $nbt) : Entity => new Armadillo($l, $nbt)],
			[Axolotl::class, "Axolotl", fn(Location $l, ?CompoundTag $nbt) : Entity => new Axolotl($l, $nbt)],
			[Bat::class, "Bat", fn(Location $l, ?CompoundTag $nbt) : Entity => new Bat($l, $nbt)],
			[Bee::class, "Bee", fn(Location $l, ?CompoundTag $nbt) : Entity => new Bee($l, $nbt)],
			[Camel::class, "Camel", fn(Location $l, ?CompoundTag $nbt) : Entity => new Camel($l, $nbt)],
			[Cat::class, "Cat", fn(Location $l, ?CompoundTag $nbt) : Entity => new Cat($l, $nbt)],
			[Cod::class, "Cod", fn(Location $l, ?CompoundTag $nbt) : Entity => new Cod($l, $nbt)],
			[CopperGolem::class, "CopperGolem", fn(Location $l, ?CompoundTag $nbt) : Entity => new CopperGolem($l, $nbt)],
			[Dolphin::class, "Dolphin", fn(Location $l, ?CompoundTag $nbt) : Entity => new Dolphin($l, $nbt)],
			[Donkey::class, "Donkey", fn(Location $l, ?CompoundTag $nbt) : Entity => new Donkey($l, $nbt)],
			[Fox::class, "Fox", fn(Location $l, ?CompoundTag $nbt) : Entity => new Fox($l, $nbt)],
			[Frog::class, "Frog", fn(Location $l, ?CompoundTag $nbt) : Entity => new Frog($l, $nbt)],
			[GlowSquid::class, "GlowSquid", fn(Location $l, ?CompoundTag $nbt) : Entity => new GlowSquid($l, $nbt)],
			[Goat::class, "Goat", fn(Location $l, ?CompoundTag $nbt) : Entity => new Goat($l, $nbt)],
			[HappyGhast::class, "HappyGhast", fn(Location $l, ?CompoundTag $nbt) : Entity => new HappyGhast($l, $nbt)],
			[Horse::class, "Horse", fn(Location $l, ?CompoundTag $nbt) : Entity => new Horse($l, $nbt)],
			[IronGolem::class, "IronGolem", fn(Location $l, ?CompoundTag $nbt) : Entity => new IronGolem($l, $nbt)],
			[Llama::class, "Llama", fn(Location $l, ?CompoundTag $nbt) : Entity => new Llama($l, $nbt)],
			[Mooshroom::class, "Mooshroom", fn(Location $l, ?CompoundTag $nbt) : Entity => new Mooshroom($l, $nbt)],
			[Mule::class, "Mule", fn(Location $l, ?CompoundTag $nbt) : Entity => new Mule($l, $nbt)],
			[Nautilus::class, "Nautilus", fn(Location $l, ?CompoundTag $nbt) : Entity => new Nautilus($l, $nbt)],
			[Ocelot::class, "Ocelot", fn(Location $l, ?CompoundTag $nbt) : Entity => new Ocelot($l, $nbt)],
			[Panda::class, "Panda", fn(Location $l, ?CompoundTag $nbt) : Entity => new Panda($l, $nbt)],
			[Parrot::class, "Parrot", fn(Location $l, ?CompoundTag $nbt) : Entity => new Parrot($l, $nbt)],
			[PolarBear::class, "PolarBear", fn(Location $l, ?CompoundTag $nbt) : Entity => new PolarBear($l, $nbt)],
			[Pufferfish::class, "Pufferfish", fn(Location $l, ?CompoundTag $nbt) : Entity => new Pufferfish($l, $nbt)],
			[Rabbit::class, "Rabbit", fn(Location $l, ?CompoundTag $nbt) : Entity => new Rabbit($l, $nbt)],
			[Salmon::class, "Salmon", fn(Location $l, ?CompoundTag $nbt) : Entity => new Salmon($l, $nbt)],
			[SkeletonHorse::class, "SkeletonHorse", fn(Location $l, ?CompoundTag $nbt) : Entity => new SkeletonHorse($l, $nbt)],
			[Sniffer::class, "Sniffer", fn(Location $l, ?CompoundTag $nbt) : Entity => new Sniffer($l, $nbt)],
			[SnowGolem::class, "SnowGolem", fn(Location $l, ?CompoundTag $nbt) : Entity => new SnowGolem($l, $nbt)],
			[Tadpole::class, "Tadpole", fn(Location $l, ?CompoundTag $nbt) : Entity => new Tadpole($l, $nbt)],
			[TraderLlama::class, "TraderLlama", fn(Location $l, ?CompoundTag $nbt) : Entity => new TraderLlama($l, $nbt)],
			[TropicalFish::class, "TropicalFish", fn(Location $l, ?CompoundTag $nbt) : Entity => new TropicalFish($l, $nbt)],
			[Turtle::class, "Turtle", fn(Location $l, ?CompoundTag $nbt) : Entity => new Turtle($l, $nbt)],
			[VillagerV2::class, "VillagerV2", fn(Location $l, ?CompoundTag $nbt) : Entity => new VillagerV2($l, $nbt)],
			[WanderingTrader::class, "WanderingTrader", fn(Location $l, ?CompoundTag $nbt) : Entity => new WanderingTrader($l, $nbt)],
			[Wolf::class, "Wolf", fn(Location $l, ?CompoundTag $nbt) : Entity => new Wolf($l, $nbt)],
			[ZombieHorse::class, "ZombieHorse", fn(Location $l, ?CompoundTag $nbt) : Entity => new ZombieHorse($l, $nbt)],
		];
	}

	public static function registerSaveData(EntityFactory $factory) : void{
		foreach(self::getMobs() as [$class, $saveName, $create]){
			$factory->register($class, fn(World $world, CompoundTag $nbt) : Entity => $create(Helper::parseLocation($nbt, $world), $nbt), [$saveName, $class::getNetworkTypeId()]);
		}
	}

	public static function registerSpawns(EntitySpawnRegistry $registry) : void{
		foreach(self::getMobs() as [$class, , $create]){
			$registry->register($class::getNetworkTypeId(), fn(Location $l) : Entity => $create($l, null));
		}
	}
}
