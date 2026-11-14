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

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\selector\CommandTargets;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\CommandFailureException;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\entity\animal\AbstractHorse;
use pocketmine\entity\animal\Animal;
use pocketmine\entity\animal\TameableAnimal;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntitySpawnRegistry;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\monster\Creeper;
use pocketmine\entity\monster\Piglin;
use pocketmine\entity\monster\Slime;
use pocketmine\entity\monster\Zoglin;
use pocketmine\entity\monster\ZombieVillager;
use pocketmine\entity\npc\VillagerV2;
use pocketmine\entity\object\LightningBolt;
use pocketmine\entity\SpawnReason;
use pocketmine\entity\Zombie;
use pocketmine\event\world\LightningStrikeEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use function array_slice;
use function atan2;
use function count;
use function floor;
use function implode;
use function in_array;
use function rad2deg;
use function sort;
use function sqrt;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use const SORT_STRING;

/**
 * /summon, with Bedrock's overloads:
 * - /summon <entityType> [spawnPos] [yRot] [xRot] [spawnEvent] [nameTag]
 * - /summon <entityType> [spawnPos] facing <lookAtEntity|lookAtPosition> [spawnEvent] [nameTag]
 * - /summon <entityType> <nameTag> [spawnPos]
 *
 * Spawn events the server models: minecraft:spawn_small/spawn_medium/spawn_large (slime and magma cube sizes),
 * minecraft:become_charged (creeper), minecraft:entity_born/as_baby/spawn_baby (babies), minecraft:as_adult/
 * spawn_adult/ageable_grow_up (adults), minecraft:on_tame (horses, donkeys, mules, llamas, wolves, cats, parrots...:
 * tamed for the player who summons) and the villager professions (minecraft:spawn_farmer..., spawn_unskilled,
 * spawn_nitwit). Like BDS, other events are ignored.
 */
class SummonCommand extends VanillaCommand{

	/** Old or Java names of entities, as BDS accepts them in commands. */
	public const ALIASES = [
		"minecraft:villager" => EntityIds::VILLAGER_V2,
		"minecraft:zombie_villager" => EntityIds::ZOMBIE_VILLAGER_V2,
		"minecraft:evoker" => EntityIds::EVOCATION_ILLAGER,
		"minecraft:zombified_piglin" => EntityIds::ZOMBIE_PIGMAN,
		"minecraft:experience_orb" => EntityIds::XP_ORB,
		"minecraft:end_crystal" => EntityIds::ENDER_CRYSTAL,
	];

	private const SLIME_SIZES = ["minecraft:spawn_small" => 1, "minecraft:spawn_medium" => 2, "minecraft:spawn_large" => 4];
	private const BABY_EVENTS = ["minecraft:entity_born", "minecraft:as_baby", "minecraft:spawn_baby"];
	private const ADULT_EVENTS = ["minecraft:as_adult", "minecraft:spawn_adult", "minecraft:ageable_grow_up"];
	private const PROFESSIONS = [
		"minecraft:spawn_unskilled" => VillagerV2::PROFESSION_UNEMPLOYED,
		"minecraft:spawn_farmer" => VillagerV2::PROFESSION_FARMER,
		"minecraft:spawn_fisherman" => VillagerV2::PROFESSION_FISHERMAN,
		"minecraft:spawn_shepherd" => VillagerV2::PROFESSION_SHEPHERD,
		"minecraft:spawn_fletcher" => VillagerV2::PROFESSION_FLETCHER,
		"minecraft:spawn_librarian" => VillagerV2::PROFESSION_LIBRARIAN,
		"minecraft:spawn_cartographer" => VillagerV2::PROFESSION_CARTOGRAPHER,
		"minecraft:spawn_cleric" => VillagerV2::PROFESSION_CLERIC,
		"minecraft:spawn_armorer" => VillagerV2::PROFESSION_ARMORER,
		"minecraft:spawn_weaponsmith" => VillagerV2::PROFESSION_WEAPONSMITH,
		"minecraft:spawn_toolsmith" => VillagerV2::PROFESSION_TOOLSMITH,
		"minecraft:spawn_butcher" => VillagerV2::PROFESSION_BUTCHER,
		"minecraft:spawn_leatherworker" => VillagerV2::PROFESSION_LEATHERWORKER,
		"minecraft:spawn_mason" => VillagerV2::PROFESSION_MASON,
		"minecraft:spawn_nitwit" => VillagerV2::PROFESSION_NITWIT,
	];

	public function __construct(){
		parent::__construct(
			"summon",
			KnownTranslationFactory::pocketmine_command_summon_description(),
			KnownTranslationFactory::pocketmine_command_summon_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_SUMMON);
	}

	public function getOverloads() : array{
		$type = fn() : CommandParameter => self::entityTypeParameter("entityType");
		$event = CommandParameter::string("spawnEvent", true);
		$name = CommandParameter::string("nameTag", true);
		$facing = CommandParameter::enum("facing", "SummonFacing", ["facing"]);
		return [
			[$type(), CommandParameter::position("spawnPos", true), CommandParameter::value("yRot", true), CommandParameter::value("xRot", true), $event, $name],
			[$type(), CommandParameter::position("spawnPos"), $facing, CommandParameter::target("lookAtEntity"), $event, $name],
			[$type(), CommandParameter::position("spawnPos"), $facing, CommandParameter::position("lookAtPosition"), $event, $name],
			[$type(), CommandParameter::string("nameTag"), CommandParameter::position("spawnPos", true)],
		];
	}

	/**
	 * The entity types /summon (and /ride) accept, without "minecraft:".
	 */
	public static function entityTypeParameter(string $name) : CommandParameter{
		$names = [];
		foreach(EntitySpawnRegistry::getInstance()->getIdentifiers() as $id){
			$names[str_starts_with($id, "minecraft:") ? substr($id, 10) : $id] = true;
		}
		foreach(self::ALIASES as $alias => $_){
			$names[substr($alias, 10)] = true;
		}
		$list = [];
		foreach(Utils::stringifyKeys($names) as $n => $_){
			$list[] = $n;
		}
		sort($list, SORT_STRING);
		return CommandParameter::enum($name, "EntityType", $list);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}
		$origin = self::getCommandOrigin($sender) ?? throw new InvalidCommandSyntaxException();
		$type = $args[0];
		$id = self::resolveEntityType($type);

		$nameTag = null;
		$event = null;
		$yaw = 0.0;
		$pitch = 0.0;
		$lookAt = null;
		$rest = array_slice($args, 1);
		if(isset($rest[0]) && !CommandCoordinates::isCoordinate($rest[0])){
			//summon <entityType> <nameTag> [spawnPos]
			$nameTag = $rest[0];
			$posArgs = array_slice($rest, 1);
			if(count($posArgs) !== 0 && count($posArgs) !== 3){
				throw new InvalidCommandSyntaxException();
			}
			$rest = [];
		}else{
			$posArgs = array_slice($rest, 0, 3);
			if(count($posArgs) !== 0 && count($posArgs) !== 3){
				throw new InvalidCommandSyntaxException();
			}
			$rest = array_slice($rest, 3);
		}
		$position = count($posArgs) === 3 ? CommandCoordinates::parsePosition($origin, $posArgs[0], $posArgs[1], $posArgs[2]) : $origin->asVector3();

		if(isset($rest[0]) && strtolower($rest[0]) === "facing"){
			if(isset($rest[1]) && CommandCoordinates::isCoordinate($rest[1])){
				if(count($rest) < 4){
					throw new InvalidCommandSyntaxException();
				}
				$lookAt = CommandCoordinates::parsePosition($origin, $rest[1], $rest[2], $rest[3]);
				$rest = array_slice($rest, 4);
			}elseif(isset($rest[1])){
				$lookAt = CommandTargets::entity($sender, $rest[1])->getEyePos();
				$rest = array_slice($rest, 2);
			}else{
				throw new InvalidCommandSyntaxException();
			}
		}else{
			//[yRot] [xRot] [spawnEvent] [nameTag]: the event can only follow both rotations (BDS rejects
			//"summon cow ~ ~ ~ minecraft:entity_born")
			if(isset($rest[0])){
				$yaw = CommandCoordinates::parseRotation($origin->yaw, $rest[0]);
				$rest = array_slice($rest, 1);
				if(isset($rest[0])){
					$pitch = CommandCoordinates::parseRotation($origin->pitch, $rest[0]);
					$rest = array_slice($rest, 1);
				}
			}
		}
		if(isset($rest[0])){
			$event = $rest[0];
			$rest = array_slice($rest, 1);
		}
		if(isset($rest[0])){
			$nameTag = $rest[0];
			$rest = array_slice($rest, 1);
		}
		if(count($rest) > 0){
			throw new InvalidCommandSyntaxException();
		}

		$world = $origin->getWorld();
		//like BDS, entities may be summoned above or below the build limits (the position is already clamped to them)
		if(!$world->isInWorld((int) floor($position->x), $world->getMinY(), (int) floor($position->z))){
			$sender->sendMessage(KnownTranslationFactory::commands_summon_outOfWorld()->prefix(TextFormat::RED));
			return true;
		}
		if($lookAt !== null){
			[$yaw, $pitch] = self::rotationTowards($position, $lookAt);
		}
		$executor = self::getSenderEntity($sender);
		$entity = self::summon($id, Location::fromObject($position, $world, $yaw, $pitch), $event, $nameTag, $executor instanceof Player ? $executor : null);
		if($entity === null){
			//BDS: unknown entity types are rejected by the command parser
			throw new CommandFailureException(KnownTranslationFactory::commands_generic_syntax("/summon ", $type, " " . implode(" ", array_slice($args, 1))));
		}
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_summon_success());
		return true;
	}

	/**
	 * Namespaced entity ID for a /summon argument ("cow", "minecraft:cow", old names like "villager" for the entity
	 * BDS spawns). Entities that are not in the {@link EntitySpawnRegistry} are created from their save data, if the
	 * server knows them.
	 */
	public static function resolveEntityType(string $type) : string{
		$id = strtolower($type);
		if(!str_contains($id, ":")){
			$id = "minecraft:" . $id;
		}
		$registry = EntitySpawnRegistry::getInstance();
		$alias = self::ALIASES[$id] ?? null;
		if($alias !== null && $registry->isRegistered($alias)){
			return $alias;
		}
		if($registry->isRegistered($id)){
			return $id;
		}
		return $alias ?? $id;
	}

	/**
	 * Creates and spawns an entity like /summon: of the given type (see {@link resolveEntityType()}), with the spawn
	 * event applied (unknown events are ignored, like BDS) and the name tag. Returns null if it can't be created. $tamer owns
	 * animals tamed by the minecraft:on_tame event.
	 */
	public static function summon(string $id, Location $location, ?string $event = null, ?string $nameTag = null, ?Player $tamer = null) : ?Entity{
		$entity = self::create($id, $location, $event);
		if($entity === null || $entity->isClosed()){
			return $entity;
		}
		if($event !== null){
			self::applySpawnEvent($entity, $event, $tamer);
		}
		if($nameTag !== null){
			$entity->setNameTag($nameTag);
		}
		if($entity instanceof Living){
			$entity->setSpawnReason(SpawnReason::CUSTOM);
		}
		$entity->spawnToAll();
		return $entity;
	}

	private static function create(string $id, Location $location, ?string $event) : ?Entity{
		if($id === EntityIds::LIGHTNING_BOLT){
			//not saved, so not in the entity factory; plugins see the strike in LightningStrikeEvent like any other
			$bolt = new LightningBolt($location);
			$ev = new LightningStrikeEvent($bolt, LightningStrikeEvent::CAUSE_CUSTOM);
			$ev->call();
			if($ev->isCancelled()){
				$bolt->close();
			}
			return $bolt;
		}
		$event = $event !== null ? self::normalizeEvent($event) : null;
		if($event !== null && isset(self::SLIME_SIZES[$event]) && ($id === EntityIds::SLIME || $id === EntityIds::MAGMA_CUBE)){
			//the size is chosen when the slime is created
			$nbt = CompoundTag::create()->setInt(Slime::TAG_SIZE, self::SLIME_SIZES[$event]);
			$nbt->setTag(EntityFactory::TAG_IDENTIFIER, new StringTag($id));
			$entity = EntityFactory::getInstance()->createFromData($location->getWorld(), self::locationTags($nbt, $location));
			if($entity !== null){
				return $entity;
			}
		}
		$registry = EntitySpawnRegistry::getInstance();
		if($registry->isRegistered($id)){
			return $registry->create($id, $location);
		}
		$nbt = CompoundTag::create()->setTag(EntityFactory::TAG_IDENTIFIER, new StringTag($id));
		try{
			return EntityFactory::getInstance()->createFromData($location->getWorld(), self::locationTags($nbt, $location));
		}catch(SavedDataLoadingException){
			return null;
		}
	}

	private static function locationTags(CompoundTag $nbt, Location $location) : CompoundTag{
		return $nbt
			->setTag(Entity::TAG_POS, new ListTag([new DoubleTag($location->x), new DoubleTag($location->y), new DoubleTag($location->z)]))
			->setTag(Entity::TAG_MOTION, new ListTag([new DoubleTag(0), new DoubleTag(0), new DoubleTag(0)]))
			->setTag(Entity::TAG_ROTATION, new ListTag([new FloatTag($location->yaw), new FloatTag($location->pitch)]));
	}

	private static function normalizeEvent(string $event) : string{
		$event = strtolower($event);
		return str_contains($event, ":") ? $event : "minecraft:" . $event;
	}

	/**
	 * Applies a Bedrock spawn event to a newly created entity, for the events the server models (see the class doc).
	 * Returns whether the event did something. minecraft:on_tame tames a tameable animal for $tamer (BDS tames it without an
	 * owner; the server needs one).
	 */
	public static function applySpawnEvent(Entity $entity, string $event, ?Player $tamer = null) : bool{
		$event = self::normalizeEvent($event);
		if($event === "minecraft:on_tame"){
			if($tamer === null || !$entity instanceof TameableAnimal || ($entity instanceof AbstractHorse && !$entity->isTameable())){
				return false;
			}
			$entity->setOwner($tamer);
			return true;
		}
		if($event === "minecraft:become_charged" && $entity instanceof Creeper){
			$entity->setPowered(true);
			return true;
		}
		$baby = in_array($event, self::BABY_EVENTS, true);
		if($baby || in_array($event, self::ADULT_EVENTS, true)){
			if($entity instanceof Animal){
				$entity->setAge($baby ? Animal::BABY_AGE : 0);
				return true;
			}
			if($entity instanceof Zombie || $entity instanceof Piglin || $entity instanceof Zoglin){
				$entity->setBaby($baby);
				return true;
			}
			return false;
		}
		$profession = self::PROFESSIONS[$event] ?? null;
		if($profession !== null && ($entity instanceof VillagerV2 || $entity instanceof ZombieVillager)){
			$entity->setProfession($profession);
			return true;
		}
		return false;
	}

	/**
	 * Yaw and pitch to look from a position (at eye level of a standing mob, roughly) at a point.
	 *
	 * @return float[]
	 * @phpstan-return array{float, float}
	 */
	private static function rotationTowards(Vector3 $from, Vector3 $to) : array{
		$xDist = $to->x - $from->x;
		$zDist = $to->z - $from->z;
		$horizontal = sqrt($xDist ** 2 + $zDist ** 2);
		$pitch = -rad2deg(atan2($to->y - $from->y, $horizontal));
		$yaw = rad2deg(atan2($zDist, $xDist)) - 90;
		if($yaw < 0){
			$yaw += 360.0;
		}
		return [$yaw, $pitch];
	}
}
