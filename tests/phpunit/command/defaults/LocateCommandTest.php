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

use pocketmine\command\selector\SelectorTestCase;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\CommandOverloadMapper;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;
use pocketmine\world\generator\structure\AsyncStructureLocator;
use pocketmine\world\generator\structure\StructureLocator;
use pocketmine\world\generator\structure\StructureLocators;
use pocketmine\world\World;
use function in_array;

class LocateCommandTest extends SelectorTestCase{

	protected function tearDown() : void{
		StructureLocators::unregisterForWorld($this->world);
		parent::tearDown();
	}

	public function testLocateStructure() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$this->runCommand(new LocateCommand(), $alice, "locate structure village");
		self::assertSame([KnownTranslationKeys::POCKETMINE_COMMAND_LOCATE_STRUCTURE_UNKNOWN, KnownTranslationKeys::POCKETMINE_COMMAND_LOCATE_SUGGESTIONS], $this->messagesOf("Alice"), "unknown here: suggest names");

		$locator = new class implements StructureLocator{
			public function getStructureIds() : array{
				return ["village", "end_city"];
			}

			public function findNearest(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly) : ?Vector3{
				return $newChunksOnly ? null : new Vector3(100, 64, 0);
			}
		};
		StructureLocators::registerForWorld($this->world, $locator);
		$this->runCommand(new LocateCommand(), $alice, "locate structure minecraft:village");
		self::assertSame(KnownTranslationKeys::COMMANDS_LOCATE_STRUCTURE_SUCCESS, $this->lastMessage("Alice"), "vanilla message, translated by the client");
		$this->runCommand(new LocateCommand(), $alice, "locate village true");
		self::assertSame(KnownTranslationKeys::COMMANDS_LOCATE_STRUCTURE_FAIL_NOSTRUCTUREFOUND, $this->lastMessage("Alice"));
		$this->runCommand(new LocateCommand(), $alice, "locate structure mansion");
		self::assertSame(KnownTranslationKeys::POCKETMINE_COMMAND_LOCATE_SUGGESTIONS, $this->lastMessage("Alice"));
	}

	public function testStructureSearchesAreQueuedPerSender() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		$bob = $this->player("Bob", 0.5, 1, 0.5);
		$locator = new class implements AsyncStructureLocator{
			/** @phpstan-var list<\Closure(?Vector3, bool, bool) : void> */
			public array $pending = [];

			public function getStructureIds() : array{
				return ["village"];
			}

			public function findNearest(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly) : ?Vector3{
				throw new \LogicException("should search asynchronously");
			}

			public function findNearestAsync(World $world, string $structureId, Vector3 $from, int $radiusChunks, bool $newChunksOnly, int $timeoutSeconds, \Closure $onCompletion) : void{
				$this->pending[] = $onCompletion;
			}
		};
		StructureLocators::registerForWorld($this->world, $locator);
		$command = new LocateCommand();
		$this->runCommand($command, $alice, "locate structure village");
		self::assertSame([], $this->messagesOf("Alice"), "the answer comes later");
		$this->runCommand($command, $alice, "locate structure village");
		self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_TOO_MANY_REQUESTS, $this->lastMessage("Alice"), "one search per player");
		$this->runCommand($command, $bob, "locate structure village");
		self::assertSame([], $this->messagesOf("Bob"), "other players are not blocked");
		self::assertCount(2, $locator->pending);

		($locator->pending[0])(new Vector3(16, 0, 32), false, false);
		self::assertSame(KnownTranslationKeys::COMMANDS_LOCATE_STRUCTURE_SUCCESS, $this->lastMessage("Alice"));
		($locator->pending[1])(null, false, true);
		self::assertSame([KnownTranslationKeys::COMMANDS_LOCATE_STRUCTURE_FAIL_NOSTRUCTUREFOUND, KnownTranslationKeys::POCKETMINE_COMMAND_LOCATE_TIMEOUT], $this->messagesOf("Bob"), "a timeout: not found, and why");

		$this->runCommand($command, $alice, "locate structure village");
		self::assertCount(3, $locator->pending, "Alice may search again");
	}

	public function testLocateBiomeInLoadedChunks() : void{
		$alice = $this->player("Alice", 0.5, 1, 0.5);
		//the test world is flat (not vanilla): only loaded chunks are searched; new chunks have biome 0 (ocean)
		$this->runCommand(new LocateCommand(), $alice, "locate biome ocean");
		self::assertSame(KnownTranslationKeys::COMMANDS_LOCATE_BIOME_SUCCESS, $this->lastMessage("Alice"));
		$this->runCommand(new LocateCommand(), $alice, "locate biome desert");
		self::assertSame(KnownTranslationKeys::COMMANDS_LOCATE_BIOME_FAIL, $this->lastMessage("Alice"));
		$this->runCommand(new LocateCommand(), $alice, "locate biome desrt");
		self::assertSame([KnownTranslationKeys::POCKETMINE_COMMAND_LOCATE_BIOME_UNKNOWN, KnownTranslationKeys::POCKETMINE_COMMAND_LOCATE_SUGGESTIONS], [$this->messagesOf("Alice")[2], $this->messagesOf("Alice")[3]]);
	}

	public function testSuggestNames() : void{
		$names = LocateCommand::BEDROCK_STRUCTURES;
		self::assertSame("end_city", LocateCommand::suggestNames("ender", $names)[0] ?? null);
		self::assertSame("end_city", LocateCommand::suggestNames("endcity", $names)[0] ?? null);
		self::assertSame("stronghold", LocateCommand::suggestNames("strongold", $names)[0] ?? null);
		self::assertSame("village", LocateCommand::suggestNames("minecraft:vilage", $names)[0] ?? null);
		self::assertSame([], LocateCommand::suggestNames("xyz", $names));
		self::assertSame(["end_city", "endcity"], LocateCommand::suggestNames("ender", ["endcity", "end_city"], $names), "the names the client suggests come first");
	}

	public function testClientSuggestions() : void{
		$overloads = (new CommandOverloadMapper())->map(new LocateCommand());
		$values = [];
		foreach($overloads as $overload){
			foreach($overload->getParameters() as $parameter){
				if($parameter->enum instanceof CommandSoftEnum){
					$values[$parameter->paramName] = $parameter->enum->getValues();
				}
			}
		}
		self::assertArrayHasKey("structure", $values);
		self::assertArrayHasKey("biome", $values);
		self::assertTrue(in_array("end_city", $values["structure"], true), "structure names are suggested");
		self::assertTrue(in_array("mesa", $values["biome"], true), "Bedrock biome names are suggested");
		self::assertFalse(in_array("badlands", $values["biome"], true), "Java names are accepted but not suggested");
	}
}
