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

use pocketmine\entity\animal\CamelHusk;
use pocketmine\entity\animal\Strider;
use pocketmine\entity\animal\SulfurCube;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Location;
use pocketmine\entity\Mob;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\utils\Utils;
use pocketmine\world\World;

/**
 * Registration of the mobs of phase 3 (hostile mobs of the Overworld, the Nether and the End, plus the strider,
 * camel husk and sulfur cube): their save IDs in {@link EntityFactory} and their spawn egg factories in
 * {@link EntitySpawnRegistry}. Kept in one place so that the shared registries only need one line each.
 *
 * Their natural spawning follows the Bedrock spawn rules (see {@link \pocketmine\world\mobs\NaturalMobSpawner}).
 */
final class HostileMobs{

	private function __construct(){
		//NOOP
	}

	/**
	 * Bedrock identifier => [class, legacy PocketMine save name, factory].
	 *
	 * @return mixed[][]
	 * @phpstan-return array<string, array{class-string<Mob>, string, \Closure(Location, ?CompoundTag) : Mob}>
	 */
	public static function getMobs() : array{
		return [
			EntityIds::HUSK => [Husk::class, "Husk", fn(Location $l, ?CompoundTag $n) => new Husk($l, $n)],
			EntityIds::DROWNED => [Drowned::class, "Drowned", fn(Location $l, ?CompoundTag $n) => new Drowned($l, $n)],
			EntityIds::ZOMBIE_VILLAGER_V2 => [ZombieVillager::class, "ZombieVillager", fn(Location $l, ?CompoundTag $n) => new ZombieVillager($l, $n)],
			EntityIds::ZOMBIE_PIGMAN => [ZombifiedPiglin::class, "ZombifiedPiglin", fn(Location $l, ?CompoundTag $n) => new ZombifiedPiglin($l, $n)],
			EntityIds::STRAY => [Stray::class, "Stray", fn(Location $l, ?CompoundTag $n) => new Stray($l, $n)],
			EntityIds::BOGGED => [Bogged::class, "Bogged", fn(Location $l, ?CompoundTag $n) => new Bogged($l, $n)],
			EntityIds::PARCHED => [Parched::class, "Parched", fn(Location $l, ?CompoundTag $n) => new Parched($l, $n)],
			EntityIds::WITHER_SKELETON => [WitherSkeleton::class, "WitherSkeleton", fn(Location $l, ?CompoundTag $n) => new WitherSkeleton($l, $n)],
			EntityIds::PILLAGER => [Pillager::class, "Pillager", fn(Location $l, ?CompoundTag $n) => new Pillager($l, $n)],
			EntityIds::VINDICATOR => [Vindicator::class, "Vindicator", fn(Location $l, ?CompoundTag $n) => new Vindicator($l, $n)],
			EntityIds::EVOCATION_ILLAGER => [Evoker::class, "Evoker", fn(Location $l, ?CompoundTag $n) => new Evoker($l, $n)],
			EntityIds::VEX => [Vex::class, "Vex", fn(Location $l, ?CompoundTag $n) => new Vex($l, $n)],
			EntityIds::RAVAGER => [Ravager::class, "Ravager", fn(Location $l, ?CompoundTag $n) => new Ravager($l, $n)],
			EntityIds::WITCH => [Witch::class, "Witch", fn(Location $l, ?CompoundTag $n) => new Witch($l, $n)],
			EntityIds::CAVE_SPIDER => [CaveSpider::class, "CaveSpider", fn(Location $l, ?CompoundTag $n) => new CaveSpider($l, $n)],
			EntityIds::SILVERFISH => [Silverfish::class, "Silverfish", fn(Location $l, ?CompoundTag $n) => new Silverfish($l, $n)],
			EntityIds::ENDERMITE => [Endermite::class, "Endermite", fn(Location $l, ?CompoundTag $n) => new Endermite($l, $n)],
			EntityIds::SLIME => [Slime::class, "Slime", fn(Location $l, ?CompoundTag $n) => new Slime($l, $n)],
			EntityIds::MAGMA_CUBE => [MagmaCube::class, "MagmaCube", fn(Location $l, ?CompoundTag $n) => new MagmaCube($l, $n)],
			EntityIds::GUARDIAN => [Guardian::class, "Guardian", fn(Location $l, ?CompoundTag $n) => new Guardian($l, $n)],
			EntityIds::ELDER_GUARDIAN => [ElderGuardian::class, "ElderGuardian", fn(Location $l, ?CompoundTag $n) => new ElderGuardian($l, $n)],
			EntityIds::ZOMBIE_NAUTILUS => [ZombieNautilus::class, "ZombieNautilus", fn(Location $l, ?CompoundTag $n) => new ZombieNautilus($l, $n)],
			EntityIds::PHANTOM => [Phantom::class, "Phantom", fn(Location $l, ?CompoundTag $n) => new Phantom($l, $n)],
			EntityIds::GHAST => [Ghast::class, "Ghast", fn(Location $l, ?CompoundTag $n) => new Ghast($l, $n)],
			EntityIds::BLAZE => [Blaze::class, "Blaze", fn(Location $l, ?CompoundTag $n) => new Blaze($l, $n)],
			EntityIds::BREEZE => [Breeze::class, "Breeze", fn(Location $l, ?CompoundTag $n) => new Breeze($l, $n)],
			EntityIds::CREAKING => [Creaking::class, "Creaking", fn(Location $l, ?CompoundTag $n) => new Creaking($l, $n)],
			EntityIds::ENDERMAN => [Enderman::class, "Enderman", fn(Location $l, ?CompoundTag $n) => new Enderman($l, $n)],
			EntityIds::SHULKER => [Shulker::class, "Shulker", fn(Location $l, ?CompoundTag $n) => new Shulker($l, $n)],
			EntityIds::HOGLIN => [Hoglin::class, "Hoglin", fn(Location $l, ?CompoundTag $n) => new Hoglin($l, $n)],
			EntityIds::ZOGLIN => [Zoglin::class, "Zoglin", fn(Location $l, ?CompoundTag $n) => new Zoglin($l, $n)],
			EntityIds::PIGLIN => [Piglin::class, "Piglin", fn(Location $l, ?CompoundTag $n) => new Piglin($l, $n)],
			EntityIds::PIGLIN_BRUTE => [PiglinBrute::class, "PiglinBrute", fn(Location $l, ?CompoundTag $n) => new PiglinBrute($l, $n)],
			EntityIds::STRIDER => [Strider::class, "Strider", fn(Location $l, ?CompoundTag $n) => new Strider($l, $n)],
			EntityIds::CAMEL_HUSK => [CamelHusk::class, "CamelHusk", fn(Location $l, ?CompoundTag $n) => new CamelHusk($l, $n)],
			EntityIds::SULFUR_CUBE => [SulfurCube::class, "SulfurCube", fn(Location $l, ?CompoundTag $n) => new SulfurCube($l, $n)],
		];
	}

	public static function registerEntities(EntityFactory $factory) : void{
		foreach(Utils::stringifyKeys(self::getMobs()) as $identifier => [$class, $legacyName, $create]){
			$factory->register($class, fn(World $world, CompoundTag $nbt) : Mob => $create(EntityDataHelper::parseLocation($nbt, $world), $nbt), [$legacyName, $identifier]);
		}
	}

	public static function registerSpawnFactories(EntitySpawnRegistry $registry) : void{
		foreach(Utils::stringifyKeys(self::getMobs()) as $identifier => [, , $create]){
			$registry->register($identifier, fn(Location $location) : Mob => $create($location, null));
		}
		//zombie villagers from before Village & Pillage
		$registry->register(EntityIds::ZOMBIE_VILLAGER, fn(Location $location) : Mob => new ZombieVillager($location));
	}
}
