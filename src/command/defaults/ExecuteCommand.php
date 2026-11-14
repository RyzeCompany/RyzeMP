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

use pocketmine\block\BlockTypeIds;
use pocketmine\command\BlockCommandSender;
use pocketmine\command\CommandSender;
use pocketmine\command\EntityCommandSender;
use pocketmine\command\ExecuteCommandSender;
use pocketmine\command\parameter\CommandParameter;
use pocketmine\command\selector\TargetSelector;
use pocketmine\command\utils\CommandBlockParser;
use pocketmine\command\utils\CommandCoordinates;
use pocketmine\command\utils\CommandFailureException;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\math\Vector3;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use pocketmine\world\dimension\Dimension;
use pocketmine\world\Position;
use pocketmine\world\World;
use function abs;
use function array_slice;
use function atan2;
use function count;
use function floor;
use function implode;
use function ltrim;
use function min;
use function preg_match;
use function rad2deg;
use function sqrt;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function substr_count;

/**
 * Bedrock's /execute: a chain of subcommands, each changing the context of the command run at the end, or testing a
 * condition:
 *
 * - as <targets>: runs once per target, as that entity (@s);
 * - at <targets>: runs once per target, at its position, rotation and dimension;
 * - positioned <x y z> / positioned as <targets>;
 * - rotated <yRot xRot> / rotated as <targets>;
 * - facing <x y z> / facing entity <targets> <eyes|feet>;
 * - align <axes> (floors the given coordinates, e.g. xz);
 * - anchored <eyes|feet> (where "facing" and local positions start from);
 * - in <overworld|nether|the_end> (the linked world of that dimension, same coordinates);
 * - if|unless block <x y z> <block> [states], if|unless blocks <begin> <end> <destination> <all|masked>,
 *   if|unless entity <targets>: keeps the contexts for which the test passes (or fails, for unless);
 * - run <command>: runs the command in every remaining context, with an {@link ExecuteCommandSender}.
 *
 * Without "run", the chain must end with a condition, and the result of the test is shown.
 */
class ExecuteCommand extends VanillaCommand{
	/** Most blocks "if blocks" compares, like vanilla. */
	public const MAX_COMPARED_BLOCKS = 32768;

	public function __construct(){
		parent::__construct(
			"execute",
			KnownTranslationFactory::pocketmine_command_execute_description(),
			KnownTranslationFactory::pocketmine_command_execute_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_EXECUTE);
	}

	public function getOverloads() : array{
		$chained = CommandParameter::rawText("chainedCommand");
		$sub = fn(string $name) : CommandParameter => CommandParameter::enum("subcommand", "Option_" . $name, [$name]);
		$condition = CommandParameter::enum("subcommand", "Option_Condition", ["if", "unless"]);
		return [
			[$sub("as"), CommandParameter::target("origin"), $chained],
			[$sub("at"), CommandParameter::target("origin"), $chained],
			[$sub("positioned"), CommandParameter::position("position"), $chained],
			[$sub("positioned"), CommandParameter::enum("as", "Option_As", ["as"]), CommandParameter::target("origin"), $chained],
			[$sub("rotated"), CommandParameter::value("yaw"), CommandParameter::value("pitch"), $chained],
			[$sub("rotated"), CommandParameter::enum("as", "Option_As", ["as"]), CommandParameter::target("origin"), $chained],
			[$sub("facing"), CommandParameter::position("position"), $chained],
			[$sub("facing"), CommandParameter::enum("entity", "Option_Entity", ["entity"]), CommandParameter::target("origin"), CommandParameter::enum("anchor", "ExecuteAnchor", ["eyes", "feet"]), $chained],
			[$sub("align"), CommandParameter::string("axes"), $chained],
			[$sub("anchored"), CommandParameter::enum("anchor", "ExecuteAnchor", ["eyes", "feet"]), $chained],
			[$sub("in"), CommandParameter::enum("dimension", "Dimension", ["overworld", "nether", "the_end"]), $chained],
			[$condition, CommandParameter::enum("block", "Option_Block", ["block"]), CommandParameter::position("position"), CommandParameter::block("block"), CommandParameter::rawText("chainedCommand", true)],
			[$condition, CommandParameter::enum("blocks", "Option_Blocks", ["blocks"]), CommandParameter::position("begin"), CommandParameter::position("end"), CommandParameter::position("destination"), CommandParameter::enum("scanMode", "BlocksScanMode", ["all", "masked"]), CommandParameter::rawText("chainedCommand", true)],
			[$condition, CommandParameter::enum("entity", "Option_Entity", ["entity"]), CommandParameter::target("target"), CommandParameter::rawText("chainedCommand", true)],
			[$sub("run"), CommandParameter::rawText("command")],
		];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}
		$contexts = [self::initialContext($sender)];
		$i = 0;
		$lastCondition = null;
		$lastCount = null;
		while(isset($args[$i])){
			$sub = strtolower($args[$i++]);
			$lastCondition = null;
			switch($sub){
				case "run":
					$line = self::joinArguments(array_slice($args, $i));
					$line = ltrim($line, "/ ");
					if($line === ""){
						throw new InvalidCommandSyntaxException();
					}
					$map = $sender->getServer()->getCommandMap();
					foreach($contexts as $context){
						$map->dispatch($context, $line);
					}
					return true;
				case "as":
				case "at":
					$target = self::arg($args, $i);
					$next = [];
					foreach($contexts as $context){
						foreach(self::selectTargets($context, $target) as $entity){
							$next[] = $sub === "as" ?
								self::with($context, $entity, $context->getExecutingPosition(), $context->getYaw(), $context->getPitch(), $context->isAnchoredAtEyes()) :
								self::withLocation($context, $entity->getLocation(), $entity->getLocation()->yaw, $entity->getLocation()->pitch);
						}
					}
					if(count($next) === 0){
						$sender->sendMessage(KnownTranslationFactory::commands_generic_noTargetMatch()->prefix(TextFormat::RED));
						return true;
					}
					$contexts = $next;
					break;
				case "positioned":
					if(strtolower($args[$i] ?? "") === "as"){
						$i++;
						$target = self::arg($args, $i);
						$next = [];
						foreach($contexts as $context){
							foreach(self::selectTargets($context, $target) as $entity){
								$next[] = self::withLocation($context, $entity->getPosition(), $context->getYaw(), $context->getPitch());
							}
						}
						if(count($next) === 0){
							$sender->sendMessage(KnownTranslationFactory::commands_generic_noTargetMatch()->prefix(TextFormat::RED));
							return true;
						}
						$contexts = $next;
					}else{
						$coords = [self::arg($args, $i), self::arg($args, $i), self::arg($args, $i)];
						foreach($contexts as $k => $context){
							$position = self::parsePosition($context, $coords);
							$contexts[$k] = self::with($context, $context->getExecutor(), Position::fromObject($position, $context->getExecutingPosition()->getWorld()), $context->getYaw(), $context->getPitch(), false);
						}
					}
					break;
				case "rotated":
					if(strtolower($args[$i] ?? "") === "as"){
						$i++;
						$target = self::arg($args, $i);
						$next = [];
						foreach($contexts as $context){
							foreach(self::selectTargets($context, $target) as $entity){
								$next[] = self::withLocation($context, $context->getExecutingPosition(), $entity->getLocation()->yaw, $entity->getLocation()->pitch);
							}
						}
						if(count($next) === 0){
							$sender->sendMessage(KnownTranslationFactory::commands_generic_noTargetMatch()->prefix(TextFormat::RED));
							return true;
						}
						$contexts = $next;
					}else{
						$yaw = self::arg($args, $i);
						$pitch = self::arg($args, $i);
						foreach($contexts as $k => $context){
							$contexts[$k] = self::withLocation(
								$context,
								$context->getExecutingPosition(),
								CommandCoordinates::parseRotation($context->getYaw(), $yaw),
								CommandCoordinates::parseRotation($context->getPitch(), $pitch)
							);
						}
					}
					break;
				case "facing":
					if(strtolower($args[$i] ?? "") === "entity"){
						$i++;
						$target = self::arg($args, $i);
						$anchor = strtolower(self::arg($args, $i));
						if($anchor !== "eyes" && $anchor !== "feet"){
							throw new InvalidCommandSyntaxException();
						}
						$next = [];
						foreach($contexts as $context){
							foreach(self::selectTargets($context, $target) as $entity){
								$next[] = self::facing($context, $anchor === "eyes" ? $entity->getEyePos() : $entity->getPosition()->asVector3());
							}
						}
						if(count($next) === 0){
							$sender->sendMessage(KnownTranslationFactory::commands_generic_noTargetMatch()->prefix(TextFormat::RED));
							return true;
						}
						$contexts = $next;
					}else{
						$coords = [self::arg($args, $i), self::arg($args, $i), self::arg($args, $i)];
						foreach($contexts as $k => $context){
							$contexts[$k] = self::facing($context, self::parsePosition($context, $coords));
						}
					}
					break;
				case "align":
					$axes = strtolower(self::arg($args, $i));
					if(preg_match('/^[xyz]{1,3}$/', $axes) !== 1 || substr_count($axes, "x") > 1 || substr_count($axes, "y") > 1 || substr_count($axes, "z") > 1){
						$sender->sendMessage(KnownTranslationFactory::commands_execute_align_invalidInput()->prefix(TextFormat::RED));
						return true;
					}
					foreach($contexts as $k => $context){
						$p = $context->getExecutingPosition();
						$aligned = new Vector3(
							str_contains($axes, "x") ? floor($p->x) : $p->x,
							str_contains($axes, "y") ? floor($p->y) : $p->y,
							str_contains($axes, "z") ? floor($p->z) : $p->z
						);
						$contexts[$k] = self::withLocation($context, Position::fromObject($aligned, $p->getWorld()), $context->getYaw(), $context->getPitch());
					}
					break;
				case "anchored":
					$anchor = strtolower(self::arg($args, $i));
					if($anchor !== "eyes" && $anchor !== "feet"){
						throw new InvalidCommandSyntaxException();
					}
					foreach($contexts as $k => $context){
						$contexts[$k] = self::with($context, $context->getExecutor(), $context->getExecutingPosition(), $context->getYaw(), $context->getPitch(), $anchor === "eyes");
					}
					break;
				case "in":
					$dimension = self::parseDimension(self::arg($args, $i));
					foreach($contexts as $k => $context){
						$p = $context->getExecutingPosition();
						$world = $sender->getServer()->getWorldManager()->getLinkedWorld($p->getWorld(), $dimension);
						if($world === null){
							$sender->sendMessage(KnownTranslationFactory::commands_generic_dimension_notFound()->prefix(TextFormat::RED));
							return true;
						}
						$contexts[$k] = self::withLocation($context, Position::fromObject($p, $world), $context->getYaw(), $context->getPitch());
					}
					break;
				case "if":
				case "unless":
					$kind = strtolower(self::arg($args, $i));
					$next = [];
					$lastCount = null;
					switch($kind){
						case "block":
							$coords = [self::arg($args, $i), self::arg($args, $i), self::arg($args, $i)];
							$block = CommandBlockParser::parse($args, $i);
							foreach($contexts as $context){
								$pos = self::parsePosition($context, $coords)->floor();
								$world = $context->getExecutingPosition()->getWorld();
								if(!$world->isInWorld((int) $pos->x, (int) $pos->y, (int) $pos->z)){
									$sender->sendMessage(KnownTranslationFactory::commands_execute_outRangedDetectPosition((string) $pos->x, (string) $pos->y, (string) $pos->z)->prefix(TextFormat::RED));
									return true;
								}
								if($block->matches($world->getBlockAt((int) $pos->x, (int) $pos->y, (int) $pos->z)) === ($sub === "if")){
									$next[] = $context;
								}
							}
							break;
						case "blocks":
							$coords = [];
							for($n = 0; $n < 9; ++$n){
								$coords[] = self::arg($args, $i);
							}
							$scanMode = strtolower(self::arg($args, $i));
							if($scanMode !== "all" && $scanMode !== "masked"){
								throw new InvalidCommandSyntaxException();
							}
							foreach($contexts as $context){
								$count = self::compareBlocks(
									$context,
									self::parsePosition($context, array_slice($coords, 0, 3))->floor(),
									self::parsePosition($context, array_slice($coords, 3, 3))->floor(),
									self::parsePosition($context, array_slice($coords, 6, 3))->floor(),
									$scanMode === "masked"
								);
								if(($count !== null) === ($sub === "if")){
									$next[] = $context;
									$lastCount ??= $count;
								}
							}
							break;
						case "entity":
							$target = self::arg($args, $i);
							foreach($contexts as $context){
								if((count(self::selectTargets($context, $target)) > 0) === ($sub === "if")){
									$next[] = $context;
								}
							}
							break;
						default:
							throw new InvalidCommandSyntaxException();
					}
					if(count($next) === 0){
						$sender->sendMessage(KnownTranslationFactory::commands_execute_falseCondition($sub, $kind)->prefix(TextFormat::RED));
						return true;
					}
					$contexts = $next;
					$lastCondition = [$sub, $kind];
					break;
				default:
					throw new InvalidCommandSyntaxException();
			}
		}

		if($lastCondition === null){
			//the chain must end with "run" or a condition
			throw new InvalidCommandSyntaxException();
		}
		$sender->sendMessage($lastCount !== null ?
			KnownTranslationFactory::commands_execute_trueConditionWithCount((string) $lastCount) :
			KnownTranslationFactory::commands_execute_trueCondition()
		);
		return true;
	}

	/**
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	private static function arg(array $args, int &$i) : string{
		if(!isset($args[$i])){
			throw new InvalidCommandSyntaxException();
		}
		return $args[$i++];
	}

	/**
	 * The context of the sender of /execute.
	 */
	public static function initialContext(CommandSender $sender) : ExecuteCommandSender{
		if($sender instanceof ExecuteCommandSender){
			return $sender;
		}
		$entity = $sender instanceof Entity ? $sender : ($sender instanceof EntityCommandSender ? $sender->getExecutingEntity() : null);
		if($entity !== null){
			$location = $entity->getLocation();
			return new ExecuteCommandSender($sender, $entity, $location, $location->yaw, $location->pitch);
		}
		if($sender instanceof BlockCommandSender){
			return new ExecuteCommandSender($sender, null, $sender->getExecutingPosition(), 0.0, 0.0);
		}
		$world = $sender->getServer()->getWorldManager()->getDefaultWorld() ?? throw new InvalidCommandSyntaxException();
		return new ExecuteCommandSender($sender, null, $world->getSpawnLocation(), 0.0, 0.0);
	}

	private static function withLocation(ExecuteCommandSender $context, Position $position, float $yaw, float $pitch) : ExecuteCommandSender{
		return self::with($context, $context->getExecutor(), $position, $yaw, $pitch, $context->isAnchoredAtEyes());
	}

	private static function with(ExecuteCommandSender $context, ?Entity $executor, Position $position, float $yaw, float $pitch, bool $anchoredAtEyes) : ExecuteCommandSender{
		return new ExecuteCommandSender($context, $executor, Position::fromObject($position->asVector3(), $position->getWorld()), $yaw, $pitch, $anchoredAtEyes);
	}

	/**
	 * Where "facing" and local coordinates start from: the position, or the executor's eyes when anchored there.
	 */
	private static function anchorOf(ExecuteCommandSender $context) : Vector3{
		$position = $context->getExecutingPosition()->asVector3();
		$executor = $context->getExecutor();
		if($executor !== null && $context->isAnchoredAtEyes()){
			return $position->add(0, $executor->getEyeHeight(), 0);
		}
		return $position;
	}

	/**
	 * @param string[] $coords
	 * @phpstan-param array<int, string> $coords
	 */
	private static function parsePosition(ExecuteCommandSender $context, array $coords) : Vector3{
		[$x, $y, $z] = [$coords[0] ?? "", $coords[1] ?? "", $coords[2] ?? ""];
		$origin = $context->getExecutingLocation();
		if(str_starts_with($x, "^")){
			$origin = Location::fromObject(self::anchorOf($context), $origin->getWorld(), $origin->yaw, $origin->pitch);
		}
		return CommandCoordinates::parsePosition($origin, $x, $y, $z);
	}

	private static function facing(ExecuteCommandSender $context, Vector3 $target) : ExecuteCommandSender{
		$from = self::anchorOf($context);
		$xDist = $target->x - $from->x;
		$zDist = $target->z - $from->z;
		$horizontal = sqrt($xDist ** 2 + $zDist ** 2);
		$pitch = -rad2deg(atan2($target->y - $from->y, $horizontal));
		$yaw = rad2deg(atan2($zDist, $xDist)) - 90;
		if($yaw < 0){
			$yaw += 360.0;
		}
		return self::withLocation($context, $context->getExecutingPosition(), $yaw, $pitch);
	}

	/**
	 * Entities named by a selector or player name, as seen from the context; empty when nothing matches (malformed
	 * selectors still throw).
	 *
	 * @return Entity[]
	 * @phpstan-return list<Entity>
	 */
	private static function selectTargets(ExecuteCommandSender $context, string $target) : array{
		if(TargetSelector::isSelectorArgument($target)){
			return TargetSelector::parse($target)->selectEntities($context);
		}
		$player = $context->getServer()->getPlayerByPrefix($target);
		return $player !== null ? [$player] : [];
	}

	/**
	 * Dimension named by "in": overworld, nether or the_end (with or without "minecraft:", and the Java name
	 * the_nether).
	 */
	public static function parseDimension(string $name) : Dimension{
		$name = strtolower($name);
		if(str_starts_with($name, "minecraft:")){
			$name = substr($name, 10);
		}
		return match($name){
			"overworld" => Dimension::OVERWORLD,
			"nether", "the_nether" => Dimension::NETHER,
			"the_end" => Dimension::END,
			default => throw new InvalidCommandSyntaxException()
		};
	}

	/**
	 * Compares the box between $begin and $end with the box of the same size at $destination. Returns the number of
	 * compared blocks if they all match (masked: air in the source box is not compared), or null.
	 */
	private static function compareBlocks(ExecuteCommandSender $context, Vector3 $begin, Vector3 $end, Vector3 $destination, bool $masked) : ?int{
		$world = $context->getExecutingPosition()->getWorld();
		$minX = (int) min($begin->x, $end->x);
		$minY = (int) min($begin->y, $end->y);
		$minZ = (int) min($begin->z, $end->z);
		$sizeX = (int) abs($begin->x - $end->x) + 1;
		$sizeY = (int) abs($begin->y - $end->y) + 1;
		$sizeZ = (int) abs($begin->z - $end->z) + 1;
		$total = $sizeX * $sizeY * $sizeZ;
		if($total > self::MAX_COMPARED_BLOCKS){
			throw new CommandFailureException(KnownTranslationFactory::commands_execute_ifUnlessBlocks_tooManyBlocks((string) self::MAX_COMPARED_BLOCKS, (string) $total));
		}
		$dx = (int) $destination->x - $minX;
		$dy = (int) $destination->y - $minY;
		$dz = (int) $destination->z - $minZ;
		$count = 0;
		for($x = $minX; $x < $minX + $sizeX; ++$x){
			for($y = $minY; $y < $minY + $sizeY; ++$y){
				for($z = $minZ; $z < $minZ + $sizeZ; ++$z){
					if(!$world->isInWorld($x, $y, $z) || !$world->isInWorld($x + $dx, $y + $dy, $z + $dz)){
						return null;
					}
					$source = $world->getBlockAt($x, $y, $z, addToCache: false);
					if($masked && $source->getTypeId() === BlockTypeIds::AIR){
						continue;
					}
					if($source->getStateId() !== $world->getBlockAt($x + $dx, $y + $dy, $z + $dz, addToCache: false)->getStateId()){
						return null;
					}
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Joins the arguments of "run" back into a command line, quoting those that contain spaces or quotes so that the
	 * command gets the same arguments.
	 *
	 * @param string[] $args
	 */
	public static function joinArguments(array $args) : string{
		$parts = [];
		foreach($args as $arg){
			if(TargetSelector::isSelectorArgument($arg) || ($arg !== "" && !str_contains($arg, " ") && !str_contains($arg, "\""))){
				$parts[] = $arg;
			}else{
				$parts[] = "\"" . str_replace(["\\", "\""], ["\\\\", "\\\""], $arg) . "\"";
			}
		}
		return implode(" ", $parts);
	}
}
