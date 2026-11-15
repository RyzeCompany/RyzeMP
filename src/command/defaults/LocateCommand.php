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

use pocketmine\command\CommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\parameter\ExhaustiveOverloads;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Entity;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\Vector3;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\generator\structure\AsyncStructureLocator;
use pocketmine\world\generator\structure\StructureLocator;
use pocketmine\world\generator\structure\StructureLocators;
use pocketmine\world\locate\BiomeLocator;
use pocketmine\world\locate\LocateWorkerPool;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use function array_keys;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function asort;
use function count;
use function floor;
use function hrtime;
use function implode;
use function in_array;
use function levenshtein;
use function min;
use function round;
use function spl_object_id;
use function sqrt;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Bedrock /locate:
 *  - /locate biome <biome>: the closest biome of that type (vanilla worlds are searched from the generator's noise up
 *    to 6400 blocks away without generating chunks; other worlds only in their loaded chunks)
 *  - /locate structure <structure> [useNewChunksOnly]: asks the world's StructureLocator (from the console: the
 *    default world, or its loaded Nether / End world if the structure only exists there)
 *  - /locate <structure> [useNewChunksOnly]: older Bedrock syntax (console and plugins only: clients get just the two
 *    syntaxes above, with their biome and structure lists, and no free-text overload)
 *
 * Slow searches (vanilla biomes, {@link AsyncStructureLocator}s) run on the {@link LocateWorkerPool}, never on the main
 * thread nor on the async pool that generates chunks; each sender may have one search running at a time, at most
 * {@link MAX_PENDING_SEARCHES} searches are queued, and each gives up after {@link SEARCH_TIMEOUT_SECONDS}. Results use
 * the vanilla messages (commands.locate.*), which the client shows in its own language.
 */
class LocateCommand extends VanillaCommand implements ExhaustiveOverloads{
	/** Search radius of /locate structure, in chunks (6400 blocks, like /locate biome). */
	public const STRUCTURE_RADIUS_CHUNKS = 400;
	/** A biome or structure search running longer than this is abandoned (and reported as not found). */
	public const SEARCH_TIMEOUT_SECONDS = 60;
	/** Searches queued or running on the /locate worker at most (all senders together). */
	public const MAX_PENDING_SEARCHES = 4;

	/**
	 * Structure names of Bedrock's /locate structure (the client suggests them).
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public const BEDROCK_STRUCTURES = [
		"ancient_city", "bastion_remnant", "buried_treasure", "end_city", "fortress", "mansion", "mineshaft", "monument",
		"pillager_outpost", "ruined_portal", "ruins", "shipwreck", "stronghold", "temple", "trail_ruins",
		"trial_chambers", "village"
	];

	/**
	 * Names the client suggests for /locate structure: Bedrock's, plus every single structure type vanilla worlds
	 * also locate on their own (what Bedrock's "temple", "village", "ruins"... group together, Nether fossils). The
	 * abandoned camps are suggested once, not per biome.
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private const SUGGESTED_STRUCTURES = [
		"abandoned_camp", "ancient_city", "bastion_remnant", "buried_treasure", "desert_pyramid", "end_city", "fortress",
		"igloo", "jungle_temple", "mansion", "mineshaft", "mineshaft_mesa", "monument", "nether_fossil",
		"ocean_ruin_cold", "ocean_ruin_warm", "pillager_outpost", "ruined_portal", "ruined_portal_desert",
		"ruined_portal_jungle", "ruined_portal_mountain", "ruined_portal_nether", "ruined_portal_ocean",
		"ruined_portal_swamp", "ruins", "shipwreck", "shipwreck_beached", "stronghold", "swamp_hut", "temple",
		"trail_ruins", "trial_chambers", "village", "village_desert", "village_plains", "village_savanna",
		"village_snowy", "village_taiga"
	];

	/**
	 * Senders with a search running (spl_object_id => true).
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $running = [];

	public function __construct(){
		parent::__construct(
			"locate",
			KnownTranslationFactory::pocketmine_command_locate_description(),
			KnownTranslationFactory::commands_locate_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_LOCATE);
	}

	public function getOverloads() : array{
		return [
			[CommandParameter::enum("mode", "LocateBiome", ["biome"]), CommandParameter::softEnum("biome", "LocateBiomeName", BiomeLocator::getBedrockBiomeNames())],
			[CommandParameter::enum("mode", "LocateStructure", ["structure"]), CommandParameter::softEnum("structure", "LocateStructureName", self::SUGGESTED_STRUCTURES), CommandParameter::enum("useNewChunksOnly", "Boolean", ["true", "false"], true)],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}
		$senderOrigin = CommandCoordinates::senderOrigin($sender);
		if($senderOrigin !== null){
			//the player, or the command block running it
			$world = $senderOrigin->getWorld();
			$origin = $senderOrigin->asVector3();
		}else{
			$world = $sender->getServer()->getWorldManager()->getDefaultWorld();
			if($world === null){
				return true;
			}
			$origin = $world->getSpawnLocation()->asVector3();
		}

		$mode = strtolower($args[0]);
		if($mode === "biome"){
			if(count($args) !== 2){
				throw new InvalidCommandSyntaxException();
			}
			$this->locateBiome($sender, $world, $origin, $args[1]);
			return true;
		}

		if($mode === "structure"){
			if(count($args) < 2 || count($args) > 3){
				throw new InvalidCommandSyntaxException();
			}
			$structure = $args[1];
			$newChunksOnly = $args[2] ?? "false";
		}else{
			if(count($args) > 2){
				throw new InvalidCommandSyntaxException();
			}
			$structure = $args[0];
			$newChunksOnly = $args[1] ?? "false";
		}
		$newChunksOnly = strtolower($newChunksOnly);
		if($newChunksOnly !== "true" && $newChunksOnly !== "false"){
			throw new InvalidCommandSyntaxException();
		}
		$this->locateStructure($sender, $world, $origin, $structure, $newChunksOnly === "true");
		return true;
	}

	private static function normalizeName(string $name) : string{
		$name = strtolower($name);
		return str_starts_with($name, "minecraft:") ? substr($name, 10) : $name;
	}

	/**
	 * Reserves the sender's search slot, or tells them to wait (vanilla "Too many commands have been requested").
	 */
	private function startSearch(CommandSender $sender) : bool{
		if(isset($this->running[spl_object_id($sender)]) || LocateWorkerPool::getInstance($sender->getServer())->getPendingCount() >= self::MAX_PENDING_SEARCHES){
			$sender->sendMessage(KnownTranslationFactory::commands_generic_too_many_requests()->prefix(TextFormat::RED));
			return false;
		}
		$this->running[spl_object_id($sender)] = true;
		return true;
	}

	private function endSearch(CommandSender $sender) : void{
		unset($this->running[spl_object_id($sender)]);
	}

	/**
	 * Whether a result can still be sent to the sender (players may leave while the search runs).
	 */
	private static function canReply(CommandSender $sender) : bool{
		return !$sender instanceof Player || $sender->isConnected();
	}

	private static function describeResult(?Vector3 $found, bool $failed, bool $timedOut, int $started) : string{
		$result = $found !== null ? $found->getFloorX() . " " . $found->getFloorY() . " " . $found->getFloorZ() : ($timedOut ? "timed out" : ($failed ? "failed" : "not found"));
		return $result . " after " . round((hrtime(true) - $started) / 1e6) . " ms";
	}

	private function locateBiome(CommandSender $sender, World $world, Vector3 $origin, string $biome) : void{
		$name = self::normalizeName($biome);
		if(BiomeLocator::resolveBiomeName($name) === null){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_locate_biome_unknown($biome)->prefix(TextFormat::RED));
			self::sendSuggestions($sender, $name, BiomeLocator::getKnownBiomeNames(), BiomeLocator::getBedrockBiomeNames());
			return;
		}
		if(!$this->startSearch($sender)){
			return;
		}
		$started = hrtime(true);
		BiomeLocator::locate($world, $name, $origin, function(?Vector3 $found, bool $failed, bool $timedOut) use ($sender, $origin, $name, $started) : void{
			$this->endSearch($sender);
			$sender->getServer()->getLogger()->debug("/locate biome $name by " . $sender->getName() . ": " . self::describeResult($found, $failed, $timedOut, $started));
			if(!self::canReply($sender)){
				return;
			}
			if($found === null){
				$sender->sendMessage(KnownTranslationFactory::commands_locate_biome_fail($name)->prefix(TextFormat::RED));
				self::sendTimeoutHint($sender, $timedOut);
				return;
			}
			$sender->sendMessage(KnownTranslationFactory::commands_locate_biome_success(
				$name,
				(string) $found->getFloorX(),
				(string) $found->getFloorY(),
				(string) $found->getFloorZ(),
				(string) self::horizontalDistance($origin, $found)
			));
		}, timeoutSeconds: self::SEARCH_TIMEOUT_SECONDS);
	}

	private static function sendTimeoutHint(CommandSender $sender, bool $timedOut) : void{
		if($timedOut){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_locate_timeout((string) self::SEARCH_TIMEOUT_SECONDS)->prefix(TextFormat::GRAY));
		}
	}

	private static function dimensionName(Dimension $dimension) : string{
		return match($dimension){
			Dimension::OVERWORLD => "Overworld",
			Dimension::NETHER => "Nether",
			Dimension::END => "End",
		};
	}

	private function locateStructure(CommandSender $sender, World $world, Vector3 $origin, string $structure, bool $newChunksOnly) : void{
		$id = self::normalizeName($structure);
		$locator = StructureLocators::get($world);
		if($locator === null || !in_array($id, $locator->getStructureIds(), true)){
			//not in this dimension: find out where it is (the console has no dimension, so it searches there instead)
			$other = null;
			foreach([Dimension::OVERWORLD, Dimension::NETHER, Dimension::END] as $dimension){
				if($dimension === $world->getDimension()){
					continue;
				}
				$linked = $sender->getServer()->getWorldManager()->getWorldByName(WorldManager::getLinkedWorldName($world->getFolderName(), $world->getDimension(), $dimension));
				$linkedLocator = $linked !== null ? StructureLocators::get($linked) : null;
				if($linked !== null && $linkedLocator !== null && in_array($id, $linkedLocator->getStructureIds(), true)){
					$other = [$linked, $linkedLocator, $dimension];
					break;
				}
			}
			if($other === null){
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_locate_structure_unknown($structure)->prefix(TextFormat::RED));
				self::sendSuggestions($sender, $id, $this->getAllStructureNames($sender, $world, $locator), self::BEDROCK_STRUCTURES);
				return;
			}
			[$linked, $linkedLocator, $dimension] = $other;
			if($sender instanceof Entity){
				//like Bedrock: there is none in this dimension
				$sender->sendMessage(KnownTranslationFactory::commands_locate_structure_fail_nostructurefound()->prefix(TextFormat::RED));
				$sender->sendMessage(KnownTranslationFactory::pocketmine_command_locate_structure_otherDimension($id, self::dimensionName($dimension))->prefix(TextFormat::GRAY));
				return;
			}
			$world = $linked;
			$locator = $linkedLocator;
			$origin = $linked->getSpawnLocation()->asVector3();
		}

		$started = hrtime(true);
		$onFound = function(?Vector3 $found, bool $failed, bool $timedOut) use ($sender, $origin, $id, $started) : void{
			$sender->getServer()->getLogger()->debug("/locate structure $id by " . $sender->getName() . ": " . self::describeResult($found, $failed, $timedOut, $started));
			if(!self::canReply($sender)){
				return;
			}
			if($found === null){
				$sender->sendMessage(KnownTranslationFactory::commands_locate_structure_fail_nostructurefound()->prefix(TextFormat::RED));
				self::sendTimeoutHint($sender, $timedOut);
				return;
			}
			$sender->sendMessage(KnownTranslationFactory::commands_locate_structure_success(
				$id,
				(string) $found->getFloorX(),
				(string) $found->getFloorZ(),
				(string) self::horizontalDistance($origin, $found)
			));
		};
		if($locator instanceof AsyncStructureLocator){
			if(!$this->startSearch($sender)){
				return;
			}
			$locator->findNearestAsync($world, $id, $origin, self::STRUCTURE_RADIUS_CHUNKS, $newChunksOnly, self::SEARCH_TIMEOUT_SECONDS, function(?Vector3 $found, bool $failed, bool $timedOut) use ($sender, $onFound) : void{
				$this->endSearch($sender);
				$onFound($found, $failed, $timedOut);
			});
		}else{
			$onFound($locator->findNearest($world, $id, $origin, self::STRUCTURE_RADIUS_CHUNKS, $newChunksOnly), false, false);
		}
	}

	/**
	 * Structure names known in the sender's world and its linked dimensions, for suggestions.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	private function getAllStructureNames(CommandSender $sender, World $world, ?StructureLocator $locator) : array{
		$names = array_merge(self::BEDROCK_STRUCTURES, $locator !== null ? $locator->getStructureIds() : []);
		foreach([Dimension::OVERWORLD, Dimension::NETHER, Dimension::END] as $dimension){
			$linked = $sender->getServer()->getWorldManager()->getWorldByName(WorldManager::getLinkedWorldName($world->getFolderName(), $world->getDimension(), $dimension));
			$linkedLocator = $linked !== null ? StructureLocators::get($linked) : null;
			if($linkedLocator !== null){
				$names = array_merge($names, $linkedLocator->getStructureIds());
			}
		}
		return array_values(array_unique($names));
	}

	/**
	 * Up to 3 known names close to what was typed ("ender" -> "end_city"), most similar first; $preferred names (the
	 * ones the client suggests) win over aliases that are as close.
	 *
	 * @param string[] $known
	 * @param string[] $preferred
	 * @phpstan-param list<string> $known
	 * @phpstan-param list<string> $preferred
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public static function suggestNames(string $typed, array $known, array $preferred = []) : array{
		$typed = self::normalizeName($typed);
		$scores = [];
		foreach($known as $name){
			$prefix = 0;
			$max = min(strlen($name), strlen($typed));
			while($prefix < $max && $name[$prefix] === $typed[$prefix]){
				++$prefix;
			}
			$distance = levenshtein($typed, $name);
			if($distance <= 2 || $prefix >= 3 || (strlen($typed) >= 3 && str_contains($name, $typed))){
				$scores[$name] = $distance - 2 * $prefix - (in_array($name, $preferred, true) ? 3 : 0);
			}
		}
		asort($scores);
		return array_slice(array_keys($scores), 0, 3);
	}

	/**
	 * @param string[] $known
	 * @param string[] $preferred
	 * @phpstan-param list<string> $known
	 * @phpstan-param list<string> $preferred
	 */
	private static function sendSuggestions(CommandSender $sender, string $typed, array $known, array $preferred) : void{
		$suggestions = self::suggestNames($typed, $known, $preferred);
		if(count($suggestions) > 0){
			$sender->sendMessage(KnownTranslationFactory::pocketmine_command_locate_suggestions(implode(", ", $suggestions))->prefix(TextFormat::GRAY));
		}
	}

	private static function horizontalDistance(Vector3 $a, Vector3 $b) : int{
		return (int) floor(sqrt(($a->x - $b->x) ** 2 + ($a->z - $b->z) ** 2));
	}
}
